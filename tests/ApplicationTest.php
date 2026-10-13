<?php

declare(strict_types=1);

namespace SpacedTracker\Api\Tests;

use SpacedTracker\Api\Bridge\DelegateController;
use SpacedTracker\Api\Bridge\SpaceTrackApiMiddleware;
use SpacedTracker\Api\Http\ApiConfig;
use SpacedTracker\Api\Http\Application;
use SpacedTracker\Api\Tests\Support\FakeClient;
use SpacedTracker\Api\Tests\Support\Http;
use SpacedTracker\Exception\AdvisoryLimitException;
use SpacedTracker\Exception\QueryException;
use SpacedTracker\Testing\ArrayLogger;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class ApplicationTest extends TestCase
{
    public function testDemoHealthDoesNotRequireATokenAndReportsOpenAuth(): void
    {
        $response = $this->demo()->handle(Http::request('GET', '/health'));
        $body = Http::json((string) $response->getBody());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('ok', $body['status']);
        self::assertSame('demo', $body['mode']);
        self::assertSame('open', $body['auth']);
    }

    public function testCatalogQueryReturnsIssAndKeepsJwstAtL2(): void
    {
        $app = $this->demo();
        $iss = Http::json((string) $app->handle(Http::request('POST', '/query', [
            'class' => 'gp',
            'where' => ['NORAD_CAT_ID' => 25544],
            'limit' => 1,
        ]))->getBody());
        $jwst = Http::json((string) $app->handle(Http::request('POST', '/query', [
            'class' => 'satcat',
            'where' => ['NORAD_CAT_ID' => 50463],
        ]))->getBody());

        self::assertSame('25544', $iss['contacts'][0]['id']);
        self::assertSame('PAY', $iss['contacts'][0]['class']);
        self::assertSame('LEO', $iss['contacts'][0]['orbit']);
        self::assertSame(418, $iss['contacts'][0]['perigee']);
        self::assertSame('L2', $jwst['contacts'][0]['orbit']);
    }

    public function testOrbitFilterAndObjectClassShortcut(): void
    {
        $app = $this->demo();
        $geo = Http::json((string) $app->handle(Http::request('POST', '/query', [
            'class' => 'satcat',
            'orbit' => 'GEO',
        ]))->getBody());
        $payloads = Http::json((string) $app->handle(Http::request('GET', '/satcat?object_type=PAYLOAD&limit=20'))->getBody());

        self::assertSame(2, $geo['count']);
        self::assertSame('GEO', $geo['contacts'][0]['orbit']);
        self::assertGreaterThan(0, $payloads['count']);
        foreach ($payloads['contacts'] as $contact) {
            self::assertSame('PAY', $contact['class']);
        }
    }

    public function testSearchShortcutUsesALikePredicate(): void
    {
        $body = Http::json((string) $this->demo()->handle(Http::request('GET', '/satcat?q=HUBBLE'))->getBody());

        self::assertSame(1, $body['count']);
        self::assertSame('20580', $body['contacts'][0]['id']);
    }

    public function testBatchPreservesKeysAndRejectsMoreThanTen(): void
    {
        $app = $this->demo();
        $ok = Http::json((string) $app->handle(Http::request('POST', '/batch', [
            'queries' => [
                ['key' => 'iss', 'class' => 'gp', 'where' => ['NORAD_CAT_ID' => 25544], 'limit' => 1],
                ['key' => 'hst', 'class' => 'gp', 'where' => ['NORAD_CAT_ID' => 20580], 'limit' => 1],
            ],
        ]))->getBody());
        $tooMany = $app->handle(Http::request('POST', '/batch', [
            'queries' => array_fill(0, 11, ['class' => 'boxscore']),
        ]));

        self::assertSame('25544', $ok['results']['iss']['contacts'][0]['id']);
        self::assertSame('20580', $ok['results']['hst']['contacts'][0]['id']);
        self::assertSame(422, $tooMany->getStatusCode());
        $detail = Http::json((string) $tooMany->getBody())['detail'];
        self::assertIsArray($detail);
        self::assertSame(['body', 'queries'], $detail[0]['loc']);
    }

    public function testMissingClassIsUnprocessableAndClassesListsGp(): void
    {
        $app = $this->demo();
        $invalid = $app->handle(Http::request('POST', '/query', ['where' => []]));
        $classes = Http::json((string) $app->handle(Http::request('GET', '/classes'))->getBody());
        $ids = array_column($classes['data'], 'id');

        self::assertSame(422, $invalid->getStatusCode());
        self::assertContains('basicspacedata.gp', $ids);
        self::assertContains('publicfiles.download', $ids);
    }

    public function testBearerTokenProtectsQueriesButNotHealthDocsOrOpenApi(): void
    {
        $app = new Application(new \SpacedTracker\Api\Demo\DemoClient(), new ApiConfig(
            'demo',
            'correct-token',
            ['http://localhost:5173'],
        ));
        $denied = $app->handle(Http::request('GET', '/satcat'));
        $allowed = $app->handle(Http::request('GET', '/satcat', null, ['Authorization' => 'Bearer correct-token']));
        $health = $app->handle(Http::request('GET', '/health'));
        $docs = $app->handle(Http::request('GET', '/docs'));
        $spec = Http::json((string) $app->handle(Http::request('GET', '/openapi.json'))->getBody());

        self::assertSame(401, $denied->getStatusCode());
        self::assertSame('Invalid API token.', Http::json((string) $denied->getBody())['detail']);
        self::assertSame(200, $allowed->getStatusCode());
        self::assertSame(200, $health->getStatusCode());
        self::assertSame('bearer', Http::json((string) $health->getBody())['auth']);
        self::assertSame(200, $docs->getStatusCode());
        self::assertStringContainsString('SPACED TRACKER API', (string) $docs->getBody());
        self::assertStringNotContainsString('cdn.jsdelivr.net', (string) $docs->getBody());
        self::assertStringNotContainsString('unpkg.com', (string) $docs->getBody());
        self::assertSame('3.0.3', $spec['openapi']);
        self::assertArrayHasKey('/query', $spec['paths']);
    }

    public function testCorsReflectsOnlyTheConfiguredOrigin(): void
    {
        $app = $this->demo();
        $local = $app->handle(Http::request('GET', '/health', null, ['Origin' => 'http://localhost:5173']));
        $evil = $app->handle(Http::request('OPTIONS', '/query', null, ['Origin' => 'http://evil.example']));
        $preflight = $app->handle(Http::request('OPTIONS', '/query', null, ['Origin' => 'http://127.0.0.1:5173']));

        self::assertSame('http://localhost:5173', $local->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertSame('', $evil->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertStringNotContainsString('*', $local->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertSame(204, $preflight->getStatusCode());
        self::assertStringContainsString('Authorization', $preflight->getHeaderLine('Access-Control-Allow-Headers'));
    }

    public function testPrefixAndMiddlewareLeaveForeignPathsAlone(): void
    {
        $app = $this->demo()->withPrefix('/api');
        $health = Http::json((string) $app->handle(Http::request('GET', '/api/health'))->getBody());
        $missing = $app->handle(Http::request('GET', '/health'));
        $middleware = new SpaceTrackApiMiddleware($app);
        $delegated = $middleware->process(Http::request('GET', '/other'), new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new \Nyholm\Psr7\Response(204))->withHeader('X-Delegate', $request->getUri()->getPath());
            }
        });
        $direct = new DelegateController($app);
        $throughController = Http::json((string) $direct(Http::request('GET', '/api/health'))->getBody());

        self::assertSame('demo', $health['mode']);
        self::assertSame(404, $missing->getStatusCode());
        self::assertSame(204, $delegated->getStatusCode());
        self::assertSame('/other', $delegated->getHeaderLine('X-Delegate'));
        $matched = $middleware->process(Http::request('GET', '/api/health'), new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                unset($request);

                throw new \RuntimeException('The API middleware must not delegate a prefixed path.');
            }
        });
        self::assertSame(200, $matched->getStatusCode());
        self::assertSame('ok', $throughController['status']);
    }

    public function testSessionDoesNotEchoAPostedPassword(): void
    {
        $logger = new ArrayLogger();
        $client = new FakeClient();
        $app = new Application($client, new ApiConfig('demo', null, []), $logger);
        $response = $app->handle(Http::request('POST', '/session', [
            'identity' => 'ada@example.com',
            'password' => 'correct-horse-battery',
        ]));
        $body = (string) $response->getBody();

        self::assertSame(1, $client->logins);
        self::assertTrue(Http::json($body)['authenticated']);
        self::assertStringNotContainsString('correct-horse-battery', $body);
        self::assertFalse($logger->contains('correct-horse-battery'));
        self::assertSame('', $response->getHeaderLine('Set-Cookie'));

        $closed = Http::json((string) $app->handle(Http::request('DELETE', '/session'))->getBody());
        self::assertFalse($closed['authenticated']);
        self::assertSame(1, $client->logouts);
    }

    public function testUpstreamErrorsUseStatusCodesWithoutResponseBodies(): void
    {
        $client = new FakeClient();
        $client->error = new QueryException('upstream failed', 503, 'password=correct-horse-battery');
        $app = new Application($client, new ApiConfig('demo', null, []));
        $response = $app->handle(Http::request('POST', '/query', ['class' => 'announcement']));
        $raw = (string) $response->getBody();

        self::assertSame(503, $response->getStatusCode());
        self::assertStringContainsString('upstream failed', $raw);
        self::assertStringNotContainsString('correct-horse-battery', $raw);

        $client->error = new AdvisoryLimitException('Bulk gp_history is limited.');
        $limited = $app->handle(Http::request('POST', '/query', ['class' => 'gp_history']));
        self::assertSame(429, $limited->getStatusCode());
    }

    public function testModelDefinitionAndDebrisClass(): void
    {
        $app = $this->demo();
        $definition = Http::json((string) $app->handle(Http::request('GET', '/classes/basicspacedata/gp/modeldef'))->getBody());
        $debris = Http::json((string) $app->handle(Http::request('GET', '/satcat?object_type=DEBRIS'))->getBody());

        self::assertSame('gp', $definition['class']);
        self::assertSame('DEB', $debris['contacts'][0]['class']);
        self::assertSame('38421', $debris['contacts'][0]['id']);
    }

    public function testEnvironmentWithoutIdentitySelectsDemoMode(): void
    {
        $config = ApiConfig::fromEnvironment([
            'SPACETRACK_IDENTITY' => '',
            'SPACED_TRACKER_CORS_ORIGINS' => '*',
        ]);

        self::assertSame('demo', $config->mode);
        self::assertSame([], $config->corsOrigins);
        self::assertSame('demo', Application::fromEnvironment(['SPACETRACK_IDENTITY' => ''])->handle(Http::request('GET', '/health'))->getStatusCode() === 200 ? 'demo' : 'live');
    }

    private function demo(): Application
    {
        return Application::fromEnvironment([
            'SPACETRACK_IDENTITY' => '',
            'SPACED_TRACKER_CORS_ORIGINS' => 'http://localhost:5173,http://127.0.0.1:5173',
        ]);
    }
}

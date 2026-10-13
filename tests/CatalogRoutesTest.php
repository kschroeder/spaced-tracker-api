<?php

declare(strict_types=1);

namespace SpacedTracker\Api\Tests;

use SpacedTracker\Api\Http\ApiConfig;
use SpacedTracker\Api\Http\Application;
use SpacedTracker\Api\Tests\Support\FakeClient;
use SpacedTracker\Api\Tests\Support\Http;
use SpacedTracker\Catalog\OutputFormat;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

final class CatalogRoutesTest extends TestCase
{
    public function testShortcutRoutesServeTheDemoDatasets(): void
    {
        $app = $this->demo();
        $decay = $this->json($app, 'GET', '/decay');
        $tip = $this->json($app, 'GET', '/tip');
        $boxscore = $this->json($app, 'GET', '/boxscore');
        $sites = $this->json($app, 'GET', '/launch-sites');
        $announcements = $this->json($app, 'GET', '/announcements');
        $cdm = $this->json($app, 'GET', '/cdm-public');
        $all = $this->json($app, 'GET', '/satcat?orbit=ALL');
        $gp = $this->json($app, 'GET', '/gp?norad_cat_id=25544&limit=1');

        self::assertSame('decay', $decay['class']);
        self::assertSame('7530', $decay['contacts'][0]['id']);
        self::assertSame('DECAYED', $decay['contacts'][0]['status']);
        self::assertSame('DECAYED', $tip['contacts'][0]['status']);
        self::assertSame(-43, $tip['records'][0]['LAT']);
        self::assertSame('US', $boxscore['contacts'][0]['id']);
        self::assertSame('', $boxscore['contacts'][0]['status']);
        self::assertSame('CAPE CANAVERAL', $sites['contacts'][0]['name']);
        self::assertSame('AFETR', $sites['contacts'][0]['designation']);
        self::assertSame('DEMO NOTICE', $announcements['contacts'][0]['name']);
        self::assertSame('25544', $cdm['contacts'][0]['id']);
        self::assertSame('DEMO-1', $cdm['contacts'][0]['designation']);
        self::assertSame(8, $all['count']);
        self::assertSame('25544', $gp['contacts'][0]['id']);

        $filtered = $this->json($app, 'GET', '/satcat?object_name=ISS&q=HUBBLE&country=US&intldes=1990-037B&object_id=1990-037B');
        self::assertSame('20580', $filtered['contacts'][0]['id']);

        $emptyOrbit = Http::json((string) $app->handle(Http::request('POST', '/query', [
            'class' => 'satcat',
            'orbit' => '',
        ]))->getBody());
        self::assertSame(8, $emptyOrbit['count']);
    }

    public function testFileRoutesAndBatchBodiesValidateBeforeAQueryRuns(): void
    {
        $client = new FakeClient();
        $app = new Application($client, new ApiConfig('demo', null, []));
        $app->handle($this->query('GET', '/satcat', [
            'norad_cat_id' => 25544,
            'limit' => 'many',
            'offset' => 'no',
            'format' => 1,
            'object_name' => '',
            'country' => 'US',
        ]));
        $app->handle(Http::request('GET', '/files'));
        $app->handle(Http::request('GET', '/files/folders'));
        $app->handle(Http::request('GET', '/public/dirs'));
        $deleted = $app->handle(Http::request('DELETE', '/files/FILE_1'));
        $download = $app->handle(Http::request('GET', '/public/download?name=demo.txt'));

        self::assertSame(50, $client->queries[0]->limitCount());
        self::assertNull($client->queries[0]->offset());
        self::assertSame(OutputFormat::Json, $client->queries[0]->outputFormat());
        self::assertSame('25544', $client->queries[0]->criteria()[0]->value);
        self::assertSame('US', $client->queries[0]->criteria()[1]->value);
        self::assertSame('file', $client->queries[1]->className());
        self::assertSame('fileshare', $client->queries[1]->controller());
        self::assertSame('folder', $client->queries[2]->className());
        self::assertSame('dirs', $client->queries[3]->className());
        self::assertSame('publicfiles', $client->queries[3]->controller());
        self::assertSame(200, $deleted->getStatusCode());
        self::assertSame('delete', $client->queries[4]->className());
        self::assertSame('FILE_1', $client->queries[4]->criteria()[0]->value);
        self::assertFalse($client->queries[4]->showsEmpty());
        self::assertSame(200, $download->getStatusCode());
        self::assertSame('download', $client->queries[5]->className());
        self::assertSame('publicfiles', $client->queries[5]->controller());
        self::assertSame(['name'], $client->queries[5]->queryParameterFields());

        $app->handle($this->query('GET', '/satcat', ['offset' => '3', 'limit' => '2']));
        self::assertSame(3, $client->queries[6]->offset());
        self::assertSame(2, $client->queries[6]->limitCount());

        $client->modelError = new \InvalidArgumentException('Invalid request class.');
        $rejected = $app->handle(Http::request('GET', '/classes/basicspacedata/bad-name/modeldef'));
        $detail = Http::json((string) $rejected->getBody())['detail'];
        self::assertSame(422, $rejected->getStatusCode());
        self::assertSame(['path', 'class'], $detail[0]['loc']);
        self::assertSame('Invalid request class.', $detail[0]['msg']);
        self::assertSame('value_error', $detail[0]['type']);

        $badId = $app->handle(Http::request('DELETE', '/files/bad@id'));
        $missingName = $app->handle(Http::request('GET', '/public/download'));
        self::assertSame(422, $badId->getStatusCode());
        self::assertSame(['path', 'id'], Http::json((string) $badId->getBody())['detail'][0]['loc']);
        self::assertSame(422, $missingName->getStatusCode());
        self::assertSame(['query', 'name'], Http::json((string) $missingName->getBody())['detail'][0]['loc']);
        self::assertCount(7, $client->queries);
    }

    public function testQueryBodiesBatchItemsAndMethodsReturnTheDocumentedErrors(): void
    {
        $app = $this->demo();
        $empty = $app->handle($this->raw('POST', '/query', ''));
        $text = $app->handle($this->raw('POST', '/query', '{'));
        $list = $app->handle($this->raw('POST', '/query', '[1]'));
        $denied = $app->handle(Http::request('POST', '/health'));
        $slash = $app->handle(Http::request('GET', '/health/'));
        $batch = Http::json((string) $app->handle(Http::request('POST', '/batch', [
            'queries' => [['class' => 'decay']],
        ]))->getBody());

        self::assertSame(400, $empty->getStatusCode());
        self::assertSame('Request body must be a JSON object.', Http::json((string) $empty->getBody())['detail']);
        self::assertSame('Request body must be JSON.', Http::json((string) $text->getBody())['detail']);
        self::assertSame('Request body must be a JSON object.', Http::json((string) $list->getBody())['detail']);
        self::assertSame(405, $denied->getStatusCode());
        self::assertStringContainsString('GET', $denied->getHeaderLine('Allow'));
        self::assertSame(200, $slash->getStatusCode());
        self::assertSame('SKYLAB', $batch['results']['0']['records'][0]['OBJECT_NAME']);

        $cases = [
            [['queries' => 'no'], ['body', 'queries'], 'queries must be a list.'],
            [['queries' => []], ['body', 'queries'], 'queries cannot be empty.'],
            [['queries' => ['no']], ['body', 'queries', '0'], 'Each query must be an object.'],
            [['queries' => [['key' => ['no'], 'class' => 'gp']]], ['body', 'queries', '0', 'key'], 'key must be a string or integer.'],
        ];
        foreach ($cases as [$payload, $loc, $message]) {
            $response = $app->handle(Http::request('POST', '/batch', $payload));
            $detail = Http::json((string) $response->getBody())['detail'];
            self::assertSame(422, $response->getStatusCode());
            self::assertSame($loc, $detail[0]['loc']);
            self::assertSame($message, $detail[0]['msg']);
        }

        $unknown = $app->handle(Http::request('GET', '/missing'));
        self::assertSame(404, $unknown->getStatusCode());
        self::assertSame('Not found.', Http::json((string) $unknown->getBody())['detail']);
    }

    private function demo(): Application
    {
        return Application::fromEnvironment(['SPACETRACK_IDENTITY' => '']);
    }

    /**
     * @return array<string, mixed>
     */
    private function json(Application $app, string $method, string $path): array
    {
        return Http::json((string) $app->handle(Http::request($method, $path))->getBody());
    }

    /**
     * @param array<string, mixed> $query
     */
    private function query(string $method, string $path, array $query): ServerRequestInterface
    {
        return Http::request($method, $path)->withQueryParams($query);
    }

    private function raw(string $method, string $path, string $body): ServerRequestInterface
    {
        $factory = new Psr17Factory();

        return Http::request($method, $path)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($factory->createStream($body));
    }
}

<?php

declare(strict_types=1);

namespace SpacedTracker\Api\Tests;

use SpacedTracker\Api\Demo\DemoClient;
use SpacedTracker\Api\Http\ApiConfig;
use SpacedTracker\Api\Http\Application;
use SpacedTracker\Api\Tests\Support\FakeClient;
use SpacedTracker\Api\Tests\Support\Http;
use SpacedTracker\Exception\AuthenticationException;
use SpacedTracker\Exception\ConfigurationException;
use SpacedTracker\Exception\QueryException;
use SpacedTracker\Exception\RateLimitException;
use SpacedTracker\Exception\SpaceTrackException;
use SpacedTracker\Testing\ArrayLogger;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

final class ApplicationBehaviorTest extends TestCase
{
    public function testUpstreamFailuresKeepSecretsOutOfTheResponse(): void
    {
        $logger = new ArrayLogger();
        $client = new FakeClient();
        $app = new Application($client, new ApiConfig('demo', 'correct-token', []), $logger);
        $request = Http::request('POST', '/query', ['class' => 'gp'], [
            'Authorization' => 'Bearer correct-token',
        ]);

        $client->error = new AuthenticationException('password=correct-horse-battery');
        $auth = $app->handle($request);
        self::assertSame(401, $auth->getStatusCode());
        self::assertSame('Space-Track authentication failed.', Http::json((string) $auth->getBody())['detail']);
        self::assertStringNotContainsString('correct-horse-battery', (string) $auth->getBody());

        $client->error = new QueryException('', 500);
        $empty = $app->handle($request);
        self::assertSame(500, $empty->getStatusCode());
        self::assertSame('Space-Track query failed.', Http::json((string) $empty->getBody())['detail']);

        $client->error = new QueryException('weird', 200);
        $range = $app->handle($request);
        self::assertSame(502, $range->getStatusCode());
        self::assertSame('weird', Http::json((string) $range->getBody())['detail']);

        $client->error = new RateLimitException('slow down');
        self::assertSame(429, $app->handle($request)->getStatusCode());

        $client->error = new SpaceTrackException('');
        $bare = $app->handle($request);
        self::assertSame(502, $bare->getStatusCode());
        self::assertSame('Space-Track request failed.', Http::json((string) $bare->getBody())['detail']);

        $client->error = new SpaceTrackException('transport down');
        self::assertSame('transport down', Http::json((string) $app->handle($request)->getBody())['detail']);

        $client->error = new \RuntimeException('password=correct-horse-battery');
        $unexpected = $app->handle($request);
        self::assertSame(500, $unexpected->getStatusCode());
        self::assertSame('Internal server error.', Http::json((string) $unexpected->getBody())['detail']);
        self::assertStringNotContainsString('correct-horse-battery', (string) $unexpected->getBody());
        self::assertTrue($logger->contains('RuntimeException'));
        self::assertFalse($logger->contains('correct-horse-battery'));

        $wrong = $app->handle(Http::request('GET', '/satcat', null, ['Authorization' => 'Bearer wrong-token']));
        $scheme = $app->handle(Http::request('GET', '/satcat', null, ['Authorization' => 'Token correct-token']));
        self::assertSame(401, $wrong->getStatusCode());
        self::assertSame(401, $scheme->getStatusCode());
    }

    public function testAnIdentitySelectsLiveModeAndDoesNotOpenASocket(): void
    {
        $open = new Application(new DemoClient(), new ApiConfig('demo', null, []));
        self::assertSame('', $open->prefix());
        self::assertTrue($open->matches('/anywhere'));

        try {
            Application::fromEnvironment([
                'SPACETRACK_IDENTITY' => 'ada@example.com',
                'SPACETRACK_PASSWORD' => 'correct-horse-battery',
            ]);
            self::fail('Live mode must try to build a Space-Track client.');
        } catch (ConfigurationException $exception) {
            self::assertStringContainsString('symfony/http-client', $exception->getMessage());
        }
    }

    public function testThePrefixItselfAndAnEmptyPathResolveToTheApiRoot(): void
    {
        $logger = new ArrayLogger();
        $prefixed = new Application(new DemoClient(), (new ApiConfig('demo', null, []))->withPrefix('/api'), $logger);
        $root = $prefixed->handle(Http::request('GET', '/api'));
        $outside = $prefixed->handle(Http::request('GET', '/health'));
        $factory = new Psr17Factory();
        $emptyPath = $factory->createServerRequest('GET', $factory->createUri('http://127.0.0.1/health')->withPath(''));
        $open = new Application(new DemoClient(), new ApiConfig('demo', null, []), $logger);
        $empty = $open->handle($emptyPath);

        self::assertSame(404, $root->getStatusCode());
        self::assertSame('Not found.', Http::json((string) $root->getBody())['detail']);
        self::assertSame(404, $outside->getStatusCode());
        self::assertSame(404, $empty->getStatusCode());
        self::assertSame(['/', '/health', '/'], $this->requestPaths($logger));
    }

    public function testCaptureRequestReadsTheServerArrayAndDropsUnsafeTargets(): void
    {
        $server = $_SERVER;
        try {
            $_SERVER = [
                'REQUEST_METHOD' => 'POST',
                'REQUEST_URI' => '/health?mode=demo',
                'HTTP_HOST' => 'catalog.test:8080',
                'HTTPS' => 'on',
                'HTTP_AUTHORIZATION' => 'Bearer token',
                'HTTP_ORIGIN' => 'http://localhost:5173',
                'CONTENT_TYPE' => 'application/json',
            ];
            $first = Application::captureRequest();
            $_SERVER['REQUEST_METHOD'] = '';
            $_SERVER['HTTPS'] = 'off';
            $_SERVER['HTTP_HOST'] = 'bad host';
            $_SERVER['REQUEST_URI'] = "/evil\r\n";
            $_SERVER['HTTP_AUTHORIZATION'] = '';
            $_SERVER['HTTP_ORIGIN'] = '';
            $_SERVER['CONTENT_TYPE'] = '';
            $second = Application::captureRequest();
            $_SERVER['REQUEST_METHOD'] = ['POST'];
            $_SERVER['HTTP_HOST'] = '';
            $_SERVER['REQUEST_URI'] = '';
            $_SERVER['HTTPS'] = '';
            $third = Application::captureRequest();
        } finally {
            $_SERVER = $server;
        }

        self::assertSame('POST', $first->getMethod());
        self::assertSame('https://catalog.test:8080/health?mode=demo', (string) $first->getUri());
        self::assertSame(['mode' => 'demo'], $first->getQueryParams());
        self::assertSame('Bearer token', $first->getHeaderLine('Authorization'));
        self::assertSame('http://localhost:5173', $first->getHeaderLine('Origin'));
        self::assertSame('application/json', $first->getHeaderLine('Content-Type'));
        self::assertSame('', (string) $first->getBody());
        self::assertSame('GET', $second->getMethod());
        self::assertSame('http://127.0.0.1/', (string) $second->getUri());
        self::assertSame('', $second->getHeaderLine('Authorization'));
        self::assertSame('GET', $third->getMethod());
        self::assertSame('http://127.0.0.1/', (string) $third->getUri());
    }

    public function testRunEmitsStatusHeadersAndBody(): void
    {
        $app = Application::fromEnvironment([
            'SPACETRACK_IDENTITY' => '',
            'SPACED_TRACKER_CORS_ORIGINS' => 'http://localhost:5173',
        ]);
        $status = 0;
        $body = $this->capture(function () use ($app, &$status): void {
            $app->run($this->request('GET', '/health'));
            $status = http_response_code();
        });
        self::assertSame(200, $status);
        self::assertSame('demo', json_decode($body, true, 512, JSON_THROW_ON_ERROR)['mode']);

        $router = sys_get_temp_dir() . '/spaced-api-' . bin2hex(random_bytes(4)) . '.php';
        $autoload = var_export(dirname(__DIR__) . '/vendor/autoload.php', true);
        file_put_contents($router, <<<PHP
<?php
declare(strict_types=1);
require {$autoload};
if ((\$_GET['premature'] ?? '') === '1') {
    echo 'already-sent';
    while (ob_get_level() > 0) {
        ob_end_flush();
    }
    flush();
}
SpacedTracker\\Api\\Http\\Application::fromEnvironment([
    'SPACETRACK_IDENTITY' => '',
    'SPACED_TRACKER_CORS_ORIGINS' => 'http://localhost:5173',
])->run();
PHP);
        try {
            $this->withServer($router, function (int $port): void {
                $health = $this->curl($port, '/health', ['Origin: http://localhost:5173']);
                self::assertSame(200, $health['status']);
                self::assertStringContainsString('application/json', strtolower($health['headers']));
                self::assertStringContainsString('http://localhost:5173', $health['headers']);
                self::assertSame('demo', json_decode($health['body'], true, 512, JSON_THROW_ON_ERROR)['mode']);

                $query = $this->curl($port, '/query', ['Content-Type: application/json'], 'POST', '{"class":"decay"}');
                self::assertSame(200, $query['status']);
                self::assertStringContainsString('SKYLAB', $query['body']);

                $early = $this->curl($port, '/missing?premature=1');
                self::assertSame(200, $early['status']);
                self::assertStringStartsWith('already-sent', $early['body']);
                self::assertStringContainsString('Not found.', $early['body']);
                self::assertStringNotContainsString('nosniff', strtolower($early['headers']));
            });
        } finally {
            if (is_file($router)) {
                unlink($router);
            }
        }
    }

    /**
     * @return list<string>
     */
    private function requestPaths(ArrayLogger $logger): array
    {
        $paths = [];
        foreach ($logger->records as $record) {
            if ($record['message'] !== 'spaced-tracker.api.request') {
                continue;
            }
            $path = $record['context']['path'] ?? null;
            if (is_string($path)) {
                $paths[] = $path;
            }
        }

        return $paths;
    }

    private function request(string $method, string $path): ServerRequestInterface
    {
        $factory = new Psr17Factory();

        return $factory->createServerRequest($method, $factory->createUri('http://127.0.0.1' . $path));
    }

    private function capture(callable $call): string
    {
        ob_start();
        try {
            $call();
        } finally {
            $body = ob_get_clean();
        }

        return is_string($body) ? $body : '';
    }

    /**
     * @param callable(int): void $assert
     */
    private function withServer(string $router, callable $assert): void
    {
        $log = $router . '.log';
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        self::assertIsResource($socket, $error);
        $address = stream_socket_get_name($socket, false);
        self::assertIsString($address);
        fclose($socket);
        $port = (int) substr($address, (int) strrpos($address, ':') + 1);
        $process = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, $router],
            [
                0 => ['pipe', 'r'],
                1 => ['file', $log, 'wb'],
                2 => ['file', $log, 'wb'],
            ],
            $pipes,
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        try {
            $this->waitUntilListening($port, $log);
            $assert($port);
        } finally {
            proc_terminate($process);
            proc_close($process);
            if (is_file($log)) {
                unlink($log);
            }
        }
    }

    private function waitUntilListening(int $port, string $log): void
    {
        $deadline = microtime(true) + 3;
        while (microtime(true) < $deadline) {
            $connection = @fsockopen('127.0.0.1', $port, $errno, $error, 0.1);
            if (is_resource($connection)) {
                fclose($connection);

                return;
            }
            usleep(50000);
        }

        $output = is_file($log) ? (string) file_get_contents($log) : '';
        self::fail('The local API server did not start. ' . $output);
    }

    /**
     * @param list<string> $headers
     * @return array{status: int, headers: string, body: string}
     */
    private function curl(int $port, string $path, array $headers = [], string $method = 'GET', string $body = ''): array
    {
        $headerFile = sys_get_temp_dir() . '/spaced-api-headers-' . bin2hex(random_bytes(4));
        $bodyFile = $headerFile . '.body';
        $stderrFile = $headerFile . '.err';
        $command = [
            'curl',
            '-sS',
            '--max-time',
            '5',
            '-D',
            $headerFile,
            '-o',
            $bodyFile,
            '-X',
            $method,
        ];
        foreach ($headers as $header) {
            $command[] = '-H';
            $command[] = $header;
        }
        if ($body !== '') {
            $command[] = '--data';
            $command[] = $body;
        }
        $command[] = 'http://127.0.0.1:' . $port . $path;
        $process = proc_open($command, [1 => ['file', $stderrFile, 'wb'], 2 => ['file', $stderrFile, 'ab']], $pipes);
        self::assertIsResource($process);
        $exit = proc_close($process);
        $stderr = is_file($stderrFile) ? (string) file_get_contents($stderrFile) : '';
        $headerText = is_file($headerFile) ? (string) file_get_contents($headerFile) : '';
        $response = is_file($bodyFile) ? (string) file_get_contents($bodyFile) : '';
        @unlink($headerFile);
        @unlink($bodyFile);
        @unlink($stderrFile);
        self::assertSame(0, $exit, $stderr);
        self::assertSame(1, preg_match('/^HTTP\/\S+\s+(\d+)/', $headerText, $status), $headerText);

        return [
            'status' => (int) $status[1],
            'headers' => $headerText,
            'body' => $response,
        ];
    }
}

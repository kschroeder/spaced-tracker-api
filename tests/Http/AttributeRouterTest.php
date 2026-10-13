<?php

declare(strict_types=1);

namespace SpacedTracker\Api\Tests\Http;

use SpacedTracker\Api\Exception\ApiException;
use SpacedTracker\Api\Http\AttributeRouter;
use SpacedTracker\Api\Http\Json;
use SpacedTracker\Api\Http\RouteScanner;
use SpacedTracker\Api\Tests\Support\FixtureController;
use SpacedTracker\Api\Tests\Support\Http;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;

final class AttributeRouterTest extends TestCase
{
    public function testRoutesNormalizePathsAndSkipUnrelatedAttributes(): void
    {
        $routes = RouteScanner::scan(FixtureController::class);
        $handlers = array_column($routes, 'handler');

        self::assertContains('root', $handlers);
        self::assertContains('postPing', $handlers);
        self::assertNotContains('noted', $handlers);
        self::assertNotContains('unrouted', $handlers);
        self::assertSame('POST', $routes[array_search('postPing', $handlers, true)]['method']);

        $router = new AttributeRouter(new FixtureController(), new Json());
        $root = Http::json((string) $router->dispatch($this->request('GET', ''))->getBody());
        $ping = Http::json((string) $router->dispatch($this->request('GET', '/ping/'))->getBody());
        $again = Http::json((string) $router->dispatch($this->request('GET', '/ping'))->getBody());
        $posted = Http::json((string) $router->dispatch($this->request('POST', '/ping'))->getBody());
        $item = Http::json((string) $router->dispatch($this->request('GET', '/items/25544'))->getBody());
        $optional = Http::json((string) $router->dispatch($this->request('GET', '/optional'))->getBody());
        $denied = $router->dispatch($this->request('PUT', '/ping'));

        self::assertTrue($root['root']);
        self::assertTrue($ping['ok']);
        self::assertTrue($again['ok']);
        self::assertTrue($posted['posted']);
        self::assertSame('25544', $item['id']);
        self::assertSame('fallback', $optional['label']);
        self::assertSame(405, $denied->getStatusCode());
        self::assertStringContainsString('GET', $denied->getHeaderLine('Allow'));
        self::assertStringContainsString('POST', $denied->getHeaderLine('Allow'));
        self::assertSame('Method not allowed.', Http::json((string) $denied->getBody())['detail']);
    }

    public function testMissingParametersAndNonArrayResultsFail(): void
    {
        $router = new AttributeRouter(new FixtureController(), new Json());

        try {
            $router->dispatch($this->request('GET', '/broken'));
            self::fail('A route parameter that is not in the path must be rejected.');
        } catch (ApiException $exception) {
            self::assertSame(400, $exception->status);
            self::assertSame('Missing route parameter "missing".', $exception->detail);
        }

        try {
            $router->dispatch($this->request('GET', '/missing'));
            self::fail('An unknown path must be a 404.');
        } catch (ApiException $exception) {
            self::assertSame(404, $exception->status);
            self::assertSame('Not found.', $exception->detail);
        }

        $this->expectException(\LogicException::class);
        $router->dispatch($this->request('GET', '/plain'));
    }

    private function request(string $method, string $path): \Psr\Http\Message\ServerRequestInterface
    {
        $factory = new Psr17Factory();
        $uri = $factory->createUri('http://127.0.0.1/')->withPath($path);

        return $factory->createServerRequest($method, $uri);
    }
}

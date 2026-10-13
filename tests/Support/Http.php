<?php

declare(strict_types=1);

namespace SpacedTracker\Api\Tests\Support;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ServerRequestInterface;

final class Http
{
    /**
     * @param array<string, mixed>|null $body
     * @param array<string, string> $headers
     */
    public static function request(string $method, string $uri, ?array $body = null, array $headers = []): ServerRequestInterface
    {
        if (!str_starts_with($uri, 'http://') && !str_starts_with($uri, 'https://')) {
            $uri = 'http://127.0.0.1' . $uri;
        }
        $factory = new Psr17Factory();
        $parsed = $factory->createUri($uri);
        $request = $factory->createServerRequest($method, $parsed);
        $query = [];
        if ($parsed->getQuery() !== '') {
            parse_str($parsed->getQuery(), $query);
        }
        $request = $request->withQueryParams($query);
        if ($body !== null) {
            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withBody($factory->createStream(json_encode($body, JSON_THROW_ON_ERROR)));
        }
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $request;
    }

    /**
     * @return array<string, mixed>
     */
    public static function json(string $body): array
    {
        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Response was not a JSON object or list.');
        }

        return $decoded;
    }
}

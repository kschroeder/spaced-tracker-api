<?php

declare(strict_types=1);

namespace SpacedTracker\Api\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;

final class Json
{
    public function __construct(private readonly Psr17Factory $factory = new Psr17Factory())
    {
    }

    /**
     * @param array<mixed> $payload
     */
    public function respond(int $status, array $payload): ResponseInterface
    {
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $this->factory->createResponse($status)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->factory->createStream($encoded));
    }

    public function html(string $html, int $status = 200): ResponseInterface
    {
        return $this->factory->createResponse($status)
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withBody($this->factory->createStream($html));
    }

    public function empty(int $status): ResponseInterface
    {
        return $this->factory->createResponse($status);
    }

    /**
     * @param string|list<array{loc: list<int|string>, msg: string, type: string}> $detail
     */
    public function error(int $status, string|array $detail): ResponseInterface
    {
        return $this->respond($status, ['detail' => $detail]);
    }
}

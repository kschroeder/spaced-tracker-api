<?php

declare(strict_types=1);

namespace SpacedTracker\Api\Exception;

/**
 * HTTP error mapped to a FastAPI-shaped JSON body.
 *
 * A string detail becomes {"detail":"..."}.
 * A list detail becomes {"detail":[{"loc":[...],"msg":"...","type":"..."}]}.
 */
final class ApiException extends \RuntimeException
{
    /**
     * @param string|list<array{loc: list<int|string>, msg: string, type: string}> $detail
     */
    public function __construct(
        public readonly int $status,
        public readonly string|array $detail,
    ) {
        parent::__construct(is_string($detail) ? $detail : 'Request validation failed.');
    }

    public static function badRequest(string $detail): self
    {
        return new self(400, $detail);
    }

    public static function unauthorized(): self
    {
        return new self(401, 'Invalid API token.');
    }

    public static function notFound(string $detail = 'Not found.'): self
    {
        return new self(404, $detail);
    }

    /**
     * @param list<int|string> $loc
     */
    public static function unprocessable(array $loc, string $message): self
    {
        return new self(422, [[
            'loc' => $loc,
            'msg' => $message,
            'type' => 'value_error',
        ]]);
    }
}

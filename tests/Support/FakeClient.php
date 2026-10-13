<?php

declare(strict_types=1);

namespace SpacedTracker\Api\Tests\Support;

use SpacedTracker\Query\Query;
use SpacedTracker\Query\QueryResult;
use SpacedTracker\SpaceTrackClientInterface;
use Nyholm\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class FakeClient implements SpaceTrackClientInterface
{
    public int $logins = 0;

    public int $logouts = 0;

    public bool $authenticated = false;

    public ?\Throwable $error = null;

    public ?\Throwable $modelError = null;

    /** @var list<Query> */
    public array $queries = [];

    public function login(): void
    {
        $this->logins++;
        $this->authenticated = true;
    }

    public function logout(): void
    {
        $this->logouts++;
        $this->authenticated = false;
    }

    public function hasSession(): bool
    {
        return $this->authenticated;
    }

    public function sessionExpiresAt(): ?\DateTimeImmutable
    {
        return $this->authenticated ? new \DateTimeImmutable('2026-01-01T02:00:00Z') : null;
    }

    public function execute(Query $query): QueryResult
    {
        $this->queries[] = $query;
        if ($this->error !== null) {
            throw $this->error;
        }

        return new QueryResult($query, 200, '[]', [], null, 0.0, []);
    }

    public function executeMany(array $queries): array
    {
        $results = [];
        foreach ($queries as $key => $query) {
            if (!$query instanceof Query) {
                throw new \InvalidArgumentException('executeMany expects Query values.');
            }
            $results[$key] = $this->execute($query);
        }

        return $results;
    }

    public function modelDefinition(string $class, ?string $controller = null): array
    {
        if ($this->modelError !== null) {
            throw $this->modelError;
        }

        return ['class' => $class, 'controller' => $controller, 'data' => []];
    }

    public function uploadFile(string $folderId, string $filename, string $contents, string $contentType = 'application/octet-stream'): QueryResult
    {
        unset($folderId, $filename, $contents, $contentType);

        return new QueryResult(Query::create(\SpacedTracker\Catalog\RequestClass::FileShareUpload), 201, '{}', [], null, 0.0, []);
    }

    public function sendAuthenticated(RequestInterface $request): ResponseInterface
    {
        unset($request);

        return new Response(200, [], '{}');
    }
}

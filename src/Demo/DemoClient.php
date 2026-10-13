<?php

declare(strict_types=1);

namespace SpacedTracker\Api\Demo;

use SpacedTracker\Catalog\RequestClass;
use SpacedTracker\Model\Row;
use SpacedTracker\Query\Direction;
use SpacedTracker\Query\Query;
use SpacedTracker\Query\QueryResult;
use SpacedTracker\SpaceTrackClientInterface;
use Nyholm\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * In-memory Space-Track client for demos and tests.
 *
 * It never opens a socket and never accepts a caller-supplied password.
 */
final class DemoClient implements SpaceTrackClientInterface
{
    private bool $authenticated = true;

    public function __construct(
        private readonly \DateTimeImmutable $expiresAt = new \DateTimeImmutable('2026-01-01T02:00:00Z'),
    ) {
    }

    public function login(): void
    {
        $this->authenticated = true;
    }

    public function logout(): void
    {
        $this->authenticated = false;
    }

    public function hasSession(): bool
    {
        return $this->authenticated;
    }

    public function sessionExpiresAt(): ?\DateTimeImmutable
    {
        return $this->authenticated ? $this->expiresAt : null;
    }

    public function execute(Query $query): QueryResult
    {
        $rows = DemoCatalog::forClass($query->className());
        $filtered = [];
        foreach ($rows as $row) {
            if ($this->matches($row, $query)) {
                $filtered[] = $row;
            }
        }
        if ($query->orders() !== []) {
            usort($filtered, function (array $left, array $right) use ($query): int {
                foreach ($query->orders() as $order) {
                    $comparison = Row::value($left, $order->field) <=> Row::value($right, $order->field);
                    if ($comparison === 0) {
                        continue;
                    }

                    return $order->direction === Direction::Desc ? -$comparison : $comparison;
                }

                return 0;
            });
        }
        $total = count($filtered);
        $offset = $query->offset() ?? 0;
        if ($query->limitCount() !== null) {
            $filtered = array_slice($filtered, $offset, $query->limitCount());
        } elseif ($offset > 0) {
            $filtered = array_slice($filtered, $offset);
        }
        $metadata = $query->includesMetadata() ? ['Total' => $total, 'Limit' => $query->limitCount()] : null;
        $raw = json_encode($filtered, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return new QueryResult($query, 200, $raw, $filtered, $metadata, 0.0, []);
    }

    public function executeMany(array $queries): array
    {
        $results = [];
        foreach ($queries as $key => $query) {
            $results[$key] = $this->execute($query);
        }

        return $results;
    }

    public function modelDefinition(string $class, ?string $controller = null): array
    {
        try {
            $resolved = RequestClass::resolve($class, $controller);
            $class = $resolved->wireName();
            $controller = $resolved->controller()->value;
        } catch (\InvalidArgumentException $exception) {
            if ($controller === null || $controller === '') {
                throw $exception;
            }
        }

        return [
            'controller' => $controller,
            'class' => $class,
            'data' => [
                ['Field' => 'NORAD_CAT_ID', 'Type' => 'int', 'Null' => 'YES'],
                ['Field' => 'OBJECT_NAME', 'Type' => 'char(25)', 'Null' => 'YES'],
                ['Field' => 'INTLDES', 'Type' => 'char(12)', 'Null' => 'YES'],
                ['Field' => 'OBJECT_TYPE', 'Type' => 'char(12)', 'Null' => 'YES'],
                ['Field' => 'COUNTRY', 'Type' => 'char(6)', 'Null' => 'YES'],
            ],
        ];
    }

    public function uploadFile(string $folderId, string $filename, string $contents, string $contentType = 'application/octet-stream'): QueryResult
    {
        unset($contents, $contentType);
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]*$/', $folderId) !== 1) {
            throw new \InvalidArgumentException('Invalid folder id.');
        }
        $safeName = str_replace(["\0", '/', '\\'], '', $filename);
        if ($safeName === '' || $safeName === '.' || $safeName === '..') {
            throw new \InvalidArgumentException('Invalid upload filename.');
        }
        $query = Query::create(RequestClass::FileShareUpload)->showEmpty(false);

        return new QueryResult($query, 201, '{"ok":true,"demo":true}', [], null, 0.0, []);
    }

    public function sendAuthenticated(RequestInterface $request): ResponseInterface
    {
        $host = strtolower($request->getUri()->getHost());
        if ($host !== '' && !in_array($host, ['www.space-track.org', 'space-track.org'], true)) {
            throw new \InvalidArgumentException('Refusing to send a session cookie to a different host.');
        }

        return new Response(200, ['Content-Type' => 'application/json'], '{"demo":true}');
    }

    /**
     * @param array<string, mixed> $row
     */
    private function matches(array $row, Query $query): bool
    {
        foreach ($query->criteria() as $criterion) {
            if (!$this->matchesValue(Row::value($row, $criterion->field), $criterion->value)) {
                return false;
            }
        }

        return true;
    }

    private function matchesValue(mixed $actual, string $expected): bool
    {
        if ($expected === 'null-val') {
            return $actual === null || $actual === '';
        }
        if (str_starts_with($expected, '~~')) {
            return is_scalar($actual) && stripos((string) $actual, substr($expected, 2)) !== false;
        }
        if (str_starts_with($expected, '^')) {
            return is_scalar($actual) && str_starts_with(strtolower((string) $actual), strtolower(substr($expected, 1)));
        }
        if (str_starts_with($expected, '<>')) {
            return (string) $actual !== substr($expected, 2);
        }
        if (str_starts_with($expected, '>=')) {
            return is_numeric($actual) && (float) $actual >= (float) substr($expected, 2);
        }
        if (str_starts_with($expected, '<=')) {
            return is_numeric($actual) && (float) $actual <= (float) substr($expected, 2);
        }
        if (str_starts_with($expected, '>')) {
            return is_numeric($actual) && (float) $actual > (float) substr($expected, 1);
        }
        if (str_starts_with($expected, '<')) {
            return is_numeric($actual) && (float) $actual < (float) substr($expected, 1);
        }
        if (str_contains($expected, '--')) {
            [$left, $right] = explode('--', $expected, 2);

            return is_numeric($actual) && (float) $actual >= (float) $left && (float) $actual <= (float) $right;
        }
        if (str_contains($expected, ',')) {
            $options = array_map(static fn (string $part): string => strtolower(trim($part)), explode(',', $expected));

            return in_array(strtolower((string) $actual), $options, true);
        }
        if ($actual === null) {
            return false;
        }
        if (is_numeric($actual) && is_numeric($expected)) {
            return (float) $actual === (float) $expected;
        }

        return strcasecmp((string) $actual, $expected) === 0;
    }
}

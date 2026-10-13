<?php

declare(strict_types=1);

namespace SpacedTracker\Api\Endpoint;

use SpacedTracker\Api\Attribute\Delete;
use SpacedTracker\Api\Attribute\Get;
use SpacedTracker\Api\Attribute\Post;
use SpacedTracker\Api\Exception\ApiException;
use SpacedTracker\Api\Http\ContactNormalizer;
use SpacedTracker\Api\Http\DocsPage;
use SpacedTracker\Api\Http\Json;
use SpacedTracker\Api\Http\QueryPayload;
use SpacedTracker\Api\Http\RouteScanner;
use SpacedTracker\Api\OpenApi\OpenApiBuilder;
use SpacedTracker\Catalog\RequestClass;
use SpacedTracker\Query\Operator;
use SpacedTracker\Query\QueryResult;
use SpacedTracker\SpaceTrackClientInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * FastAPI-style route declarations for the catalog API.
 *
 * Each public method with a route attribute is one operation. The router
 * discovers them. There is no separate route table to keep in sync.
 */
final class CatalogEndpoint
{
    public function __construct(
        private readonly SpaceTrackClientInterface $client,
        private readonly QueryPayload $payloads,
        private readonly ContactNormalizer $contacts,
        private readonly Json $json,
        private readonly string $mode,
        private readonly bool $tokenConfigured,
        private readonly string $prefix = '',
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    #[Get('/health', 'Liveness probe. Does not log in to Space-Track.')]
    public function health(): array
    {
        return [
            'status' => 'ok',
            'mode' => $this->mode,
            'auth' => $this->tokenConfigured ? 'bearer' : 'open',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    #[Get('/classes', 'Every supported Space-Track request class.')]
    public function classes(): array
    {
        return ['data' => RequestClass::directory()];
    }

    /**
     * @return array<string, mixed>
     */
    #[Get('/classes/{controller}/{class}/modeldef', 'Model definition for one request class.')]
    public function modelDefinition(string $controller, string $class): array
    {
        try {
            return $this->client->modelDefinition($class, $controller);
        } catch (\InvalidArgumentException $exception) {
            throw ApiException::unprocessable(['path', 'class'], $exception->getMessage());
        }
    }

    /**
     * @return array<string, mixed>
     */
    #[Post('/query', 'Run one Space-Track query and return records plus normalized contacts.')]
    public function query(ServerRequestInterface $request): array
    {
        $payload = $this->jsonObject($request);
        $result = $this->client->execute($this->payloads->build($payload));

        return $this->present($result, $this->orbitFilter($payload));
    }

    /**
     * @return array<string, mixed>
     */
    #[Post('/batch', 'Run up to 10 queries. Keys on each item are preserved.')]
    public function batch(ServerRequestInterface $request): array
    {
        $payload = $this->jsonObject($request);
        $queries = $payload['queries'] ?? null;
        if (!is_array($queries)) {
            throw ApiException::unprocessable(['body', 'queries'], 'queries must be a list.');
        }
        if (count($queries) > 10) {
            throw ApiException::unprocessable(['body', 'queries'], 'Batch size cannot exceed 10.');
        }
        if ($queries === []) {
            throw ApiException::unprocessable(['body', 'queries'], 'queries cannot be empty.');
        }
        $built = [];
        foreach ($queries as $index => $item) {
            if (!is_array($item)) {
                throw ApiException::unprocessable(['body', 'queries', (string) $index], 'Each query must be an object.');
            }
            $key = $item['key'] ?? $index;
            if (!is_string($key) && !is_int($key)) {
                throw ApiException::unprocessable(['body', 'queries', (string) $index, 'key'], 'key must be a string or integer.');
            }
            /** @var array<string, mixed> $item */
            $built[$key] = $this->payloads->build($item);
        }
        $presented = [];
        foreach ($this->client->executeMany($built) as $key => $result) {
            $presented[(string) $key] = $this->present($result, null);
        }

        return ['results' => $presented];
    }

    /**
     * @return array<string, mixed>
     */
    #[Get('/gp', 'Current general perturbations. Filter with norad_cat_id or q.')]
    public function gp(ServerRequestInterface $request): array
    {
        return $this->fromQuery($request, 'gp');
    }

    /**
     * @return array<string, mixed>
     */
    #[Get('/satcat', 'Satellite catalog. Filter with country, object_type, q, or norad_cat_id.')]
    public function satcat(ServerRequestInterface $request): array
    {
        return $this->fromQuery($request, 'satcat');
    }

    /**
     * @return array<string, mixed>
     */
    #[Get('/decay', 'Decay messages.')]
    public function decay(ServerRequestInterface $request): array
    {
        return $this->fromQuery($request, 'decay');
    }

    /**
     * @return array<string, mixed>
     */
    #[Get('/tip', 'Tracking and Impact Prediction messages.')]
    public function tip(ServerRequestInterface $request): array
    {
        return $this->fromQuery($request, 'tip');
    }

    /**
     * @return array<string, mixed>
     */
    #[Get('/boxscore', 'Boxscore counts by country.')]
    public function boxscore(ServerRequestInterface $request): array
    {
        return $this->fromQuery($request, 'boxscore');
    }

    /**
     * @return array<string, mixed>
     */
    #[Get('/launch-sites', 'Launch site codes.')]
    public function launchSites(ServerRequestInterface $request): array
    {
        return $this->fromQuery($request, 'launch_site');
    }

    /**
     * @return array<string, mixed>
     */
    #[Get('/announcements', 'Operator announcements.')]
    public function announcements(ServerRequestInterface $request): array
    {
        return $this->fromQuery($request, 'announcement');
    }

    /**
     * @return array<string, mixed>
     */
    #[Get('/cdm-public', 'Public conjunction data messages.')]
    public function cdmPublic(ServerRequestInterface $request): array
    {
        return $this->fromQuery($request, 'cdm_public');
    }

    /**
     * @return array<string, mixed>
     */
    #[Get('/files', 'List files in the account file share.')]
    public function files(ServerRequestInterface $request): array
    {
        return $this->fromQuery($request, 'file', 'fileshare');
    }

    /**
     * @return array<string, mixed>
     */
    #[Get('/files/folders', 'List file-share folders.')]
    public function folders(ServerRequestInterface $request): array
    {
        return $this->fromQuery($request, 'folder', 'fileshare');
    }

    /**
     * @return array<string, mixed>
     */
    #[Delete('/files/{id}', 'Delete one file-share file by id. The response does not echo the file body.')]
    public function deleteFile(string $id): array
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]*$/', $id) !== 1) {
            throw ApiException::unprocessable(['path', 'id'], 'Invalid file id.');
        }
        $result = $this->client->execute($this->payloads->build([
            'class' => 'delete',
            'controller' => 'fileshare',
            'where' => ['FILE_ID' => $id],
            'show_empty' => false,
        ]));

        return $this->present($result, null);
    }

    /**
     * @return array<string, mixed>
     */
    #[Get('/public/dirs', 'Public file directories. Authentication is optional on Space-Track.')]
    public function publicDirs(ServerRequestInterface $request): array
    {
        return $this->fromQuery($request, 'dirs', 'publicfiles');
    }

    /**
     * @return array<string, mixed>
     */
    #[Get('/public/download', 'Download a public file by the name query parameter.')]
    public function publicDownload(ServerRequestInterface $request): array
    {
        $name = $request->getQueryParams()['name'] ?? null;
        if (!is_string($name) || $name === '') {
            throw ApiException::unprocessable(['query', 'name'], 'name is required.');
        }
        $result = $this->client->execute($this->payloads->build([
            'class' => 'download',
            'controller' => 'publicfiles',
            'where' => ['name' => $name],
            'show_empty' => false,
        ]));

        return $this->present($result, null);
    }

    /**
     * @return array<string, mixed>
     */
    #[Post('/session', 'Log in with the server-side Space-Track account. The cookie is not returned.')]
    public function openSession(ServerRequestInterface $request): array
    {
        unset($request);
        $this->client->login();
        $expires = $this->client->sessionExpiresAt();

        return [
            'authenticated' => $this->client->hasSession(),
            'expires_at' => $expires?->format(\DATE_ATOM),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    #[Delete('/session', 'Log out of Space-Track. The cookie is discarded on the server.')]
    public function closeSession(): array
    {
        $this->client->logout();

        return ['authenticated' => $this->client->hasSession()];
    }

    /**
     * @return array<string, mixed>
     */
    #[Get('/openapi.json', 'OpenAPI 3 document for these routes.')]
    public function openApi(): array
    {
        return OpenApiBuilder::document(RouteScanner::scan(self::class));
    }

    #[Get('/docs', 'Route index. No external scripts or stylesheets.')]
    public function docs(): ResponseInterface
    {
        return $this->json->html(DocsPage::render(RouteScanner::scan(self::class), $this->prefix));
    }

    /**
     * @return array<string, mixed>
     */
    private function fromQuery(ServerRequestInterface $request, string $class, ?string $controller = null): array
    {
        $params = $request->getQueryParams();
        $where = [];
        $map = [
            'norad_cat_id' => 'NORAD_CAT_ID',
            'object_name' => 'OBJECT_NAME',
            'object_type' => 'OBJECT_TYPE',
            'country' => 'COUNTRY',
            'intldes' => 'INTLDES',
            'object_id' => 'OBJECT_ID',
        ];
        foreach ($map as $queryKey => $field) {
            $value = $params[$queryKey] ?? null;
            if (is_string($value) && $value !== '') {
                $where[$field] = $value;
            } elseif (is_int($value)) {
                $where[$field] = $value;
            }
        }
        $search = $params['q'] ?? null;
        if (is_string($search) && $search !== '') {
            $where['OBJECT_NAME'] = Operator::like($search);
        }
        $payload = [
            'class' => $class,
            'where' => $where,
            'limit' => isset($params['limit']) && is_numeric($params['limit']) ? (int) $params['limit'] : 50,
            'format' => isset($params['format']) && is_string($params['format']) ? $params['format'] : 'json',
        ];
        if ($controller !== null) {
            $payload['controller'] = $controller;
        }
        if (isset($params['offset']) && is_numeric($params['offset'])) {
            $payload['offset'] = (int) $params['offset'];
        }
        $orbit = isset($params['orbit']) && is_string($params['orbit']) ? $params['orbit'] : null;

        return $this->present($this->client->execute($this->payloads->build($payload)), $orbit);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function orbitFilter(array $payload): ?string
    {
        $orbit = $payload['orbit'] ?? null;

        return is_string($orbit) && $orbit !== '' ? $orbit : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(QueryResult $result, ?string $orbit): array
    {
        $records = $result->records;
        if ($orbit !== null && strtoupper($orbit) !== 'ALL') {
            $wanted = strtoupper($orbit);
            $records = array_values(array_filter(
                $records,
                fn (array $row): bool => strtoupper($this->contacts->orbitOf($row)) === $wanted,
            ));
        }

        return [
            'class' => $result->query->className(),
            'controller' => $result->query->controller(),
            'count' => count($records),
            'records' => $records,
            'metadata' => $result->metadata,
            'duration_seconds' => $result->durationSeconds,
            'contacts' => $this->contacts->normalize($records),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonObject(ServerRequestInterface $request): array
    {
        $raw = trim((string) $request->getBody());
        if ($raw === '') {
            throw ApiException::badRequest('Request body must be a JSON object.');
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw ApiException::badRequest('Request body must be JSON.');
        }
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw ApiException::badRequest('Request body must be a JSON object.');
        }

        return $decoded;
    }
}

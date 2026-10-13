<?php

declare(strict_types=1);

namespace SpacedTracker\Api\Http;

use SpacedTracker\Api\Exception\ApiException;
use SpacedTracker\Catalog\OutputFormat;
use SpacedTracker\Catalog\RequestClass;
use SpacedTracker\Query\Direction;
use SpacedTracker\Query\Query;

/**
 * Builds an immutable Space-Track query from a JSON object.
 */
final class QueryPayload
{
    /**
     * @param array<string, mixed> $payload
     */
    public function build(array $payload): Query
    {
        $className = $payload['class'] ?? $payload['request_class'] ?? null;
        if (!is_string($className) || $className === '') {
            throw ApiException::unprocessable(['body', 'class'], 'class is required.');
        }
        $controller = $payload['controller'] ?? null;
        if ($controller !== null && !is_string($controller)) {
            throw ApiException::unprocessable(['body', 'controller'], 'controller must be a string.');
        }

        $query = $this->queryFor($className, is_string($controller) ? $controller : null);
        $query = $this->applyWhere($query, $payload['where'] ?? []);
        $query = $this->applyOrder($query, $payload);
        $query = $this->applyWindow($query, $payload);
        $query = $this->applyFormat($query, $payload);
        $query = $this->applyColumns($query, $payload);

        if (($payload['distinct'] ?? false) === true) {
            $query = $query->distinct();
        }
        if (($payload['metadata'] ?? false) === true) {
            $query = $query->withMetadata();
        }
        if (array_key_exists('show_empty', $payload) && $payload['show_empty'] === false) {
            $query = $query->showEmpty(false);
        }
        if (isset($payload['favorites'])) {
            $query = $this->applyFavorites($query, $payload['favorites']);
        }

        return $query;
    }

    private function queryFor(string $className, ?string $controller): Query
    {
        try {
            return Query::create(RequestClass::resolve($className, $controller));
        } catch (\InvalidArgumentException $exception) {
            if ($controller === null || $controller === '') {
                throw ApiException::unprocessable(['body', 'class'], $exception->getMessage());
            }
            try {
                return Query::custom($className, $controller);
            } catch (\InvalidArgumentException $custom) {
                throw ApiException::unprocessable(['body', 'class'], $custom->getMessage());
            }
        }
    }

    /**
     * @param mixed $where
     */
    private function applyWhere(Query $query, mixed $where): Query
    {
        if ($where === [] || $where === null) {
            return $query;
        }
        if (!is_array($where)) {
            throw ApiException::unprocessable(['body', 'where'], 'where must be an object.');
        }
        foreach ($where as $field => $value) {
            if (!is_string($field)) {
                throw ApiException::unprocessable(['body', 'where'], 'where fields must be strings.');
            }
            if (!is_array($value) && !is_scalar($value) && $value !== null) {
                throw ApiException::unprocessable(['body', 'where', $field], 'Predicate values must be scalars, null, or a list.');
            }
            try {
                $query = $query->where($field, $value);
            } catch (\InvalidArgumentException $exception) {
                throw ApiException::unprocessable(['body', 'where', $field], $exception->getMessage());
            }
        }

        return $query;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function applyOrder(Query $query, array $payload): Query
    {
        $orders = $payload['order_by'] ?? [];
        if (is_string($orders) && $orders !== '') {
            $orders = [[
                'field' => $orders,
                'direction' => $payload['direction'] ?? 'asc',
            ]];
        }
        if ($orders === []) {
            return $query;
        }
        if (!is_array($orders)) {
            throw ApiException::unprocessable(['body', 'order_by'], 'order_by must be a string or a list.');
        }
        foreach ($orders as $order) {
            if (is_string($order)) {
                try {
                    $query = $query->orderBy($order, Direction::Asc);
                } catch (\InvalidArgumentException $exception) {
                    throw ApiException::unprocessable(['body', 'order_by'], $exception->getMessage());
                }
                continue;
            }
            if (!is_array($order) || !isset($order['field']) || !is_string($order['field'])) {
                throw ApiException::unprocessable(['body', 'order_by'], 'order_by entries need a field.');
            }
            $direction = strtolower((string) ($order['direction'] ?? 'asc')) === 'desc' ? Direction::Desc : Direction::Asc;
            try {
                $query = $query->orderBy($order['field'], $direction);
            } catch (\InvalidArgumentException $exception) {
                throw ApiException::unprocessable(['body', 'order_by'], $exception->getMessage());
            }
        }

        return $query;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function applyWindow(Query $query, array $payload): Query
    {
        if (!array_key_exists('limit', $payload) || $payload['limit'] === null) {
            return $query;
        }
        if (!is_numeric($payload['limit'])) {
            throw ApiException::unprocessable(['body', 'limit'], 'limit must be an integer.');
        }
        $limit = (int) $payload['limit'];
        if ($limit > 10000) {
            throw ApiException::unprocessable(['body', 'limit'], 'limit cannot exceed 10000.');
        }
        $offset = null;
        if (array_key_exists('offset', $payload) && $payload['offset'] !== null) {
            if (!is_numeric($payload['offset'])) {
                throw ApiException::unprocessable(['body', 'offset'], 'offset must be an integer.');
            }
            $offset = (int) $payload['offset'];
        }
        try {
            return $query->limit($limit, $offset);
        } catch (\InvalidArgumentException $exception) {
            throw ApiException::unprocessable(['body', 'limit'], $exception->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function applyFormat(Query $query, array $payload): Query
    {
        if (!isset($payload['format'])) {
            return $query;
        }
        if (!is_string($payload['format'])) {
            throw ApiException::unprocessable(['body', 'format'], 'format must be a string.');
        }
        $format = OutputFormat::tryFrom($payload['format']);
        if ($format === null) {
            throw ApiException::unprocessable(['body', 'format'], 'Unknown format.');
        }

        return $query->format($format);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function applyColumns(Query $query, array $payload): Query
    {
        if (!isset($payload['columns'])) {
            return $query;
        }
        if (!is_array($payload['columns'])) {
            throw ApiException::unprocessable(['body', 'columns'], 'columns must be a list.');
        }
        $columns = [];
        foreach ($payload['columns'] as $column) {
            if (!is_string($column)) {
                throw ApiException::unprocessable(['body', 'columns'], 'columns must be strings.');
            }
            $columns[] = $column;
        }
        try {
            return $query->columns($columns);
        } catch (\InvalidArgumentException $exception) {
            throw ApiException::unprocessable(['body', 'columns'], $exception->getMessage());
        }
    }

    private function applyFavorites(Query $query, mixed $favorites): Query
    {
        if (!is_array($favorites)) {
            throw ApiException::unprocessable(['body', 'favorites'], 'favorites must be a list.');
        }
        $names = [];
        foreach ($favorites as $name) {
            if (!is_string($name)) {
                throw ApiException::unprocessable(['body', 'favorites'], 'favorites must be strings.');
            }
            $names[] = $name;
        }
        try {
            return $query->favorites(...$names);
        } catch (\InvalidArgumentException $exception) {
            throw ApiException::unprocessable(['body', 'favorites'], $exception->getMessage());
        }
    }
}

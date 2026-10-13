<?php

declare(strict_types=1);

namespace SpacedTracker\Api\OpenApi;

/**
 * Small OpenAPI 3 document derived from the attribute routes.
 *
 * The operation list is the contract. Schemas cover the query body and the
 * FastAPI-style error object. There is no external documentation CDN.
 */
final class OpenApiBuilder
{
    /**
     * @param list<array{method: string, path: string, summary: string, handler?: string}> $routes
     * @return array<string, mixed>
     */
    public static function document(array $routes): array
    {
        $paths = [];
        foreach ($routes as $route) {
            $method = strtolower($route['method']);
            $exempt = in_array($route['path'], ['/health', '/docs', '/openapi.json'], true);
            $operation = [
                'summary' => $route['summary'] !== '' ? $route['summary'] : $route['path'],
                'responses' => [
                    '200' => ['description' => 'Success'],
                    '401' => [
                        'description' => 'Invalid bearer token',
                        'content' => [
                            'application/json' => [
                                'schema' => ['$ref' => '#/components/schemas/Error'],
                            ],
                        ],
                    ],
                    '422' => [
                        'description' => 'Validation error',
                        'content' => [
                            'application/json' => [
                                'schema' => ['$ref' => '#/components/schemas/Error'],
                            ],
                        ],
                    ],
                ],
                'security' => $exempt ? [] : [['bearer' => []]],
            ];
            if ($method === 'post') {
                $operation['requestBody'] = [
                    'required' => true,
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => '#/components/schemas/Query'],
                        ],
                    ],
                ];
            }
            $paths[$route['path']][$method] = $operation;
        }

        return [
            'openapi' => '3.0.3',
            'info' => [
                'title' => 'Spaced Tracker API',
                'version' => '1.0.0',
                'description' => 'HTTP API for the Space-Track catalog. Space-Track credentials stay on the server and are never returned.',
            ],
            'paths' => $paths,
            'components' => [
                'securitySchemes' => [
                    'bearer' => [
                        'type' => 'http',
                        'scheme' => 'bearer',
                    ],
                ],
                'schemas' => [
                    'Query' => [
                        'type' => 'object',
                        'required' => ['class'],
                        'properties' => [
                            'class' => ['type' => 'string', 'example' => 'satcat'],
                            'controller' => ['type' => 'string'],
                            'where' => ['type' => 'object', 'additionalProperties' => true],
                            'order_by' => ['type' => 'string'],
                            'direction' => ['type' => 'string', 'enum' => ['asc', 'desc']],
                            'limit' => ['type' => 'integer'],
                            'offset' => ['type' => 'integer'],
                            'format' => ['type' => 'string'],
                            'orbit' => ['type' => 'string'],
                        ],
                    ],
                    'Error' => [
                        'type' => 'object',
                        'properties' => [
                            'detail' => [
                                'description' => 'A string, or a list of {loc, msg, type} objects for HTTP 422.',
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}

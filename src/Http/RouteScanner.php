<?php

declare(strict_types=1);

namespace SpacedTracker\Api\Http;

use SpacedTracker\Api\Attribute\Delete;
use SpacedTracker\Api\Attribute\Get;
use SpacedTracker\Api\Attribute\Post;

final class RouteScanner
{
    /**
     * @param class-string|object $controller
     * @return list<array{method: string, path: string, summary: string, handler: string}>
     */
    public static function scan(object|string $controller): array
    {
        $class = new \ReflectionClass($controller);
        $routes = [];
        foreach ($class->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            foreach ($method->getAttributes() as $attribute) {
                $instance = $attribute->newInstance();
                if (!$instance instanceof Get && !$instance instanceof Post && !$instance instanceof Delete) {
                    continue;
                }
                $routes[] = [
                    'method' => self::methodOf($instance),
                    'path' => $instance->path,
                    'summary' => $instance->summary,
                    'handler' => $method->getName(),
                ];
            }
        }

        return $routes;
    }

    private static function methodOf(Get|Post|Delete $attribute): string
    {
        if ($attribute instanceof Post) {
            return 'POST';
        }
        if ($attribute instanceof Delete) {
            return 'DELETE';
        }

        return 'GET';
    }
}

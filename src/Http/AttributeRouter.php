<?php

declare(strict_types=1);

namespace SpacedTracker\Api\Http;

use FastRoute\Dispatcher;
use FastRoute\RouteCollector;
use SpacedTracker\Api\Exception\ApiException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Dispatches attribute routes through FastRoute.
 */
final class AttributeRouter
{
    private ?Dispatcher $dispatcher = null;

    public function __construct(
        private readonly object $controller,
        private readonly Json $json,
    ) {
    }

    public function dispatch(ServerRequestInterface $request): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        if ($path === '') {
            $path = '/';
        } elseif ($path !== '/') {
            $path = rtrim($path, '/') ?: '/';
        }
        $info = $this->dispatcher()->dispatch(strtoupper($request->getMethod()), $path);
        if ($info[0] === Dispatcher::NOT_FOUND) {
            throw ApiException::notFound();
        }
        if ($info[0] === Dispatcher::METHOD_NOT_ALLOWED) {
            $allowed = [];
            foreach ($info[1] as $method) {
                if (is_string($method)) {
                    $allowed[] = $method;
                }
            }

            return $this->json->error(405, 'Method not allowed.')->withHeader('Allow', implode(', ', $allowed));
        }
        $handler = $info[1];
        /** @var array<string, string> $vars */
        $vars = is_array($info[2]) ? $info[2] : [];
        if (!is_string($handler)) {
            throw ApiException::notFound();
        }
        $result = $this->invoke($handler, $vars, $request);
        if ($result instanceof ResponseInterface) {
            return $result;
        }
        if (!is_array($result)) {
            throw new \LogicException('An endpoint must return an array or a PSR-7 response.');
        }

        return $this->json->respond(200, $result);
    }

    /**
     * @param array<string, string> $vars
     */
    private function invoke(string $handler, array $vars, ServerRequestInterface $request): mixed
    {
        $method = new \ReflectionMethod($this->controller, $handler);
        $arguments = [];
        foreach ($method->getParameters() as $parameter) {
            $type = $parameter->getType();
            if ($type instanceof \ReflectionNamedType && $type->getName() === ServerRequestInterface::class) {
                $arguments[] = $request;
                continue;
            }
            $name = $parameter->getName();
            if (array_key_exists($name, $vars)) {
                $arguments[] = $vars[$name];
                continue;
            }
            if ($parameter->isDefaultValueAvailable()) {
                $arguments[] = $parameter->getDefaultValue();
                continue;
            }
            throw ApiException::badRequest(sprintf('Missing route parameter "%s".', $name));
        }

        return $method->invokeArgs($this->controller, $arguments);
    }

    private function dispatcher(): Dispatcher
    {
        if ($this->dispatcher instanceof Dispatcher) {
            return $this->dispatcher;
        }
        $routes = RouteScanner::scan($this->controller);
        $this->dispatcher = \FastRoute\simpleDispatcher(static function (RouteCollector $collector) use ($routes): void {
            foreach ($routes as $route) {
                $collector->addRoute($route['method'], $route['path'], $route['handler']);
            }
        });

        return $this->dispatcher;
    }
}

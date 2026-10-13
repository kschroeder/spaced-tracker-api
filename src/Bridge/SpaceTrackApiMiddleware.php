<?php

declare(strict_types=1);

namespace SpacedTracker\Api\Bridge;

use SpacedTracker\Api\Http\Application;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Mounts the Space-Track API inside another PSR-15 pipeline.
 *
 * Requests outside the application prefix are passed to the next handler.
 * Give the application a prefix before embedding it, otherwise it handles
 * every path.
 */
final class SpaceTrackApiMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly Application $application)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->application->matches($request->getUri()->getPath())) {
            return $handler->handle($request);
        }

        return $this->application->handle($request);
    }
}

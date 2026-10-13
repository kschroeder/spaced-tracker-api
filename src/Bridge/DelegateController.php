<?php

declare(strict_types=1);

namespace SpacedTracker\Api\Bridge;

use SpacedTracker\Api\Http\Application;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Callable controller for frameworks that mount a single invokable class.
 */
final class DelegateController
{
    public function __construct(private readonly Application $application)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        return $this->application->handle($request);
    }
}

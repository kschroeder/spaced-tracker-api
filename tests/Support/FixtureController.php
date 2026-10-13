<?php

declare(strict_types=1);

namespace SpacedTracker\Api\Tests\Support;

use SpacedTracker\Api\Attribute\Get;
use SpacedTracker\Api\Attribute\Post;

final class FixtureController
{
    #[Get('/', 'Root')]
    public function root(): array
    {
        return ['root' => true];
    }

    #[Get('/ping', 'Ping')]
    public function ping(): array
    {
        return ['ok' => true];
    }

    #[Get('/items/{id}', 'Item')]
    public function item(string $id): array
    {
        return ['id' => $id];
    }

    #[Get('/optional', 'Optional')]
    public function optional(string $label = 'fallback'): array
    {
        return ['label' => $label];
    }

    #[Get('/broken', 'Broken')]
    public function broken(string $missing): array
    {
        unset($missing);

        return [];
    }

    #[Get('/plain', 'Plain')]
    public function plain(): string
    {
        return 'nope';
    }

    #[Note('ignored')]
    #[Post('/ping', 'Post ping')]
    public function postPing(): array
    {
        return ['posted' => true];
    }

    #[Note('only')]
    public function noted(): array
    {
        return [];
    }

    public function unrouted(): array
    {
        return [];
    }
}

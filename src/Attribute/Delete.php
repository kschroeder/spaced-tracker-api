<?php

declare(strict_types=1);

namespace SpacedTracker\Api\Attribute;

#[\Attribute(\Attribute::TARGET_METHOD)]
final class Delete
{
    public function __construct(
        public readonly string $path,
        public readonly string $summary = '',
    ) {
    }
}

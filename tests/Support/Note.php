<?php

declare(strict_types=1);

namespace SpacedTracker\Api\Tests\Support;

#[\Attribute(\Attribute::TARGET_METHOD)]
final class Note
{
    public function __construct(public string $text)
    {
    }
}

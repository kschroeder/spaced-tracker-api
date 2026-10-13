<?php

declare(strict_types=1);

namespace SpacedTracker\Api\Tests\Http;

use SpacedTracker\Api\Http\ApiConfig;
use PHPUnit\Framework\TestCase;

final class ApiConfigTest extends TestCase
{
    public function testEnvironmentSelectsDemoLiveTokenOriginsAndPrefix(): void
    {
        $live = ApiConfig::fromEnvironment([
            'SPACETRACK_IDENTITY' => 'ada@example.com',
            'SPACED_TRACKER_DEMO' => 'off',
            'SPACED_TRACKER_API_TOKEN' => '',
            'SPACED_TRACKER_CORS_ORIGINS' => '*',
            'SPACED_TRACKER_API_PREFIX' => '/',
        ]);
        self::assertSame('live', $live->mode);
        self::assertNull($live->apiToken);
        self::assertSame([], $live->corsOrigins);
        self::assertSame('', $live->prefix);

        $forced = $live->withPrefix('api');
        self::assertSame('/api', $forced->prefix);
        self::assertSame('', $live->prefix);
        self::assertSame('live', $forced->mode);

        $demo = ApiConfig::fromEnvironment([
            'SPACETRACK_IDENTITY' => 'ada@example.com',
            'SPACED_TRACKER_DEMO' => 'YES',
            'SPACED_TRACKER_API_TOKEN' => '  token  ',
            'SPACED_TRACKER_CORS_ORIGINS' => 'http://a.test, http://a.test, *, , http://b.test',
            'SPACED_TRACKER_API_PREFIX' => '/api/v1',
        ]);
        self::assertSame('demo', $demo->mode);
        self::assertSame('token', $demo->apiToken);
        self::assertSame(['http://a.test', 'http://b.test'], $demo->corsOrigins);
        self::assertSame('/api/v1', $demo->prefix);

        foreach (['1', 'true', 'yes', 'on'] as $flag) {
            $config = ApiConfig::fromEnvironment([
                'SPACETRACK_IDENTITY' => 'ada@example.com',
                'SPACED_TRACKER_DEMO' => $flag,
            ]);
            self::assertSame('demo', $config->mode, $flag);
        }
        self::assertSame('demo', ApiConfig::fromEnvironment(['SPACETRACK_IDENTITY' => ''])->mode);
    }

    public function testFromEnvironmentReadsTheProcessEnvironment(): void
    {
        $keys = [
            'SPACED_TRACKER_DEMO',
            'SPACETRACK_IDENTITY',
            'SPACED_TRACKER_API_TOKEN',
            'SPACED_TRACKER_CORS_ORIGINS',
            'SPACED_TRACKER_API_PREFIX',
        ];
        $previous = [];
        foreach ($keys as $key) {
            $value = getenv($key);
            $previous[$key] = $value === false ? null : $value;
            putenv($key);
        }
        try {
            putenv('SPACED_TRACKER_DEMO=yes');
            putenv('SPACETRACK_IDENTITY=ada@example.com');
            putenv('SPACED_TRACKER_API_TOKEN=  token  ');
            putenv('SPACED_TRACKER_CORS_ORIGINS=http://a.test, http://b.test');
            putenv('SPACED_TRACKER_API_PREFIX=api');
            $config = ApiConfig::fromEnvironment();
        } finally {
            foreach ($previous as $key => $value) {
                if ($value === null) {
                    putenv($key);
                } else {
                    putenv($key . '=' . $value);
                }
            }
        }

        self::assertSame('demo', $config->mode);
        self::assertSame('token', $config->apiToken);
        self::assertSame(['http://a.test', 'http://b.test'], $config->corsOrigins);
        self::assertSame('/api', $config->prefix);
    }

    public function testModeAndPrefixAreRejectedWhenTheyAreNotUrlPaths(): void
    {
        try {
            new ApiConfig('open', null, []);
            self::fail('An unknown mode must be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('demo or live', $exception->getMessage());
        }
        foreach (['/api/../secret', 'api/../secret', '/api/ /v1'] as $prefix) {
            try {
                ApiConfig::normalizePrefix($prefix);
                self::fail($prefix . ' must be rejected.');
            } catch (\InvalidArgumentException $exception) {
                self::assertStringContainsString('URL path', $exception->getMessage());
            }
        }
    }
}

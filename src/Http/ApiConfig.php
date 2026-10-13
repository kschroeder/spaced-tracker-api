<?php

declare(strict_types=1);

namespace SpacedTracker\Api\Http;

/**
 * Runtime settings for the HTTP API.
 *
 * Demo mode is on when SPACED_TRACKER_DEMO is truthy or SPACETRACK_IDENTITY
 * is empty. An empty API token leaves the API open and is reported by /health
 * as auth=open. Set SPACED_TRACKER_API_TOKEN before exposing the port.
 */
final readonly class ApiConfig
{
    /**
     * @param list<string> $corsOrigins
     */
    public function __construct(
        public string $mode,
        public ?string $apiToken,
        public array $corsOrigins,
        public string $prefix = '',
    ) {
        if (!in_array($this->mode, ['demo', 'live'], true)) {
            throw new \InvalidArgumentException('API mode must be demo or live.');
        }
    }

    /**
     * @param array<string, string>|null $env
     */
    public static function fromEnvironment(?array $env = null): self
    {
        $env ??= self::environment();
        $demoFlag = strtolower($env['SPACED_TRACKER_DEMO'] ?? '');
        $identity = $env['SPACETRACK_IDENTITY'] ?? '';
        $forcedDemo = in_array($demoFlag, ['1', 'true', 'yes', 'on'], true);
        $token = trim($env['SPACED_TRACKER_API_TOKEN'] ?? '');
        $origins = $env['SPACED_TRACKER_CORS_ORIGINS'] ?? 'http://localhost:5173,http://127.0.0.1:5173';
        $prefix = $env['SPACED_TRACKER_API_PREFIX'] ?? '';

        return new self(
            $forcedDemo || $identity === '' ? 'demo' : 'live',
            $token === '' ? null : $token,
            self::origins($origins),
            self::normalizePrefix($prefix),
        );
    }

    public function withPrefix(string $prefix): self
    {
        return new self($this->mode, $this->apiToken, $this->corsOrigins, self::normalizePrefix($prefix));
    }

    public static function normalizePrefix(string $prefix): string
    {
        $prefix = trim($prefix);
        if ($prefix === '' || $prefix === '/') {
            return '';
        }
        $prefix = '/' . trim($prefix, '/');
        if (preg_match('#^/[A-Za-z0-9._/-]+$#', $prefix) !== 1 || str_contains($prefix, '..')) {
            throw new \InvalidArgumentException('API prefix must be a URL path.');
        }

        return $prefix;
    }

    /**
     * @return list<string>
     */
    private static function origins(string $origins): array
    {
        $items = [];
        foreach (explode(',', $origins) as $origin) {
            $origin = trim($origin);
            if ($origin === '' || $origin === '*') {
                continue;
            }
            $items[] = $origin;
        }

        return array_values(array_unique($items));
    }

    /**
     * @return array<string, string>
     */
    private static function environment(): array
    {
        return getenv();
    }
}

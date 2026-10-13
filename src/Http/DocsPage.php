<?php

declare(strict_types=1);

namespace SpacedTracker\Api\Http;

/**
 * Self-contained route index. It does not load a script or stylesheet from the network.
 */
final class DocsPage
{
    /**
     * @param list<array{method: string, path: string, summary: string, handler?: string}> $routes
     */
    public static function render(array $routes, string $prefix = ''): string
    {
        $rows = '';
        foreach ($routes as $route) {
            $rows .= '<tr><td>' . self::escape($route['method']) . '</td><td><code>'
                . self::escape($route['path']) . '</code></td><td>'
                . self::escape($route['summary']) . '</td></tr>';
        }

        return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>Spaced Tracker API</title>'
            . '<style>'
            . 'body{margin:0;background:#101400;color:#d6ff4a;font:16px/1.45 ui-monospace,monospace;}'
            . 'main{max-width:960px;margin:0 auto;padding:24px;}'
            . 'h1{font-weight:400;letter-spacing:.12em;}'
            . 'table{width:100%;border-collapse:collapse;}'
            . 'th,td{border:1px solid #8fb82a;padding:8px;text-align:left;vertical-align:top;}'
            . 'code{color:#d6ff4a;}'
            . '</style></head><body><main>'
            . '<h1>SPACED TRACKER API</h1>'
            . '<p>Attribute-declared routes for the Space-Track catalog. '
            . 'Credentials stay on the server. Machine-readable contract: <a href="'
            . self::escape(rtrim($prefix, '/') . '/openapi.json') . '">openapi.json</a>.</p>'
            . '<table><thead><tr><th>Method</th><th>Path</th><th>Summary</th></tr></thead><tbody>'
            . $rows
            . '</tbody></table></main></body></html>';
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

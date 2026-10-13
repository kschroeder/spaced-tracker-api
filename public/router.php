<?php

declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$path = is_string($path) ? rawurldecode($path) : '/';
$public = realpath(__DIR__);
$escaped = $path === '' || $path === '/' || str_contains($path, "\0") || preg_match('#(^|/)\.\.(/|$)#', $path) === 1;
if (!$escaped && $public !== false) {
    $resolved = realpath($public . '/' . ltrim($path, '/'));
    $prefix = $public . DIRECTORY_SEPARATOR;
    if (is_string($resolved) && is_file($resolved) && str_starts_with($resolved, $prefix)) {
        return false;
    }
}

require __DIR__ . '/index.php';

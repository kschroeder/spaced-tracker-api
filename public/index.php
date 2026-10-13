<?php

declare(strict_types=1);

$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (!is_file($autoload)) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo '{"detail":"Composer autoload was not found."}';
    return;
}
require $autoload;

SpacedTracker\Api\Http\Application::fromEnvironment()->run();

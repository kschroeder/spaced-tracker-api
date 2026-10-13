<?php

declare(strict_types=1);

namespace SpacedTracker\Api\Http;

use SpacedTracker\Api\Demo\DemoClient;
use SpacedTracker\Api\Endpoint\CatalogEndpoint;
use SpacedTracker\Api\Exception\ApiException;
use SpacedTracker\ClientBuilder;
use SpacedTracker\Exception\AdvisoryLimitException;
use SpacedTracker\Exception\AuthenticationException;
use SpacedTracker\Exception\QueryException;
use SpacedTracker\Exception\RateLimitException;
use SpacedTracker\Exception\SpaceTrackException;
use SpacedTracker\Log\RedactingLogger;
use SpacedTracker\SpaceTrackClientInterface;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Single entry point for the Space-Track HTTP API.
 *
 * Embed it as a PSR-15 request handler, or call run() from the PHP built-in
 * server. The handler never writes the Space-Track session cookie to the client.
 */
final class Application implements RequestHandlerInterface
{
    private readonly CatalogEndpoint $endpoint;

    private readonly AttributeRouter $router;

    private readonly Json $json;

    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly SpaceTrackClientInterface $client,
        private readonly ApiConfig $config,
        private readonly LoggerInterface $innerLogger = new NullLogger(),
    ) {
        $this->logger = new RedactingLogger($innerLogger);
        $this->json = new Json();
        $this->endpoint = new CatalogEndpoint(
            $client,
            new QueryPayload(),
            new ContactNormalizer(),
            $this->json,
            $config->mode,
            $config->apiToken !== null,
            $config->prefix,
        );
        $this->router = new AttributeRouter($this->endpoint, $this->json);
    }

    /**
     * @param array<string, string>|null $env
     */
    public static function fromEnvironment(?array $env = null, ?LoggerInterface $logger = null): self
    {
        $config = ApiConfig::fromEnvironment($env);
        $client = $config->mode === 'demo'
            ? new DemoClient()
            : ClientBuilder::fromEnvironment($env)->build();

        return new self($client, $config, $logger ?? new NullLogger());
    }

    public function withPrefix(string $prefix): self
    {
        return new self($this->client, $this->config->withPrefix($prefix), $this->innerLogger);
    }

    public function prefix(): string
    {
        return $this->config->prefix;
    }

    public function matches(string $path): bool
    {
        $prefix = $this->config->prefix;
        if ($prefix === '') {
            return true;
        }

        return $path === $prefix || str_starts_with($path, $prefix . '/');
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $started = hrtime(true);
        $origin = $request->getHeaderLine('Origin');
        $path = $request->getUri()->getPath();
        try {
            $path = $this->stripPrefix($path);
            if (strtoupper($request->getMethod()) === 'OPTIONS') {
                $response = $this->json->empty(204);
            } elseif (!$this->authorized($request, $path)) {
                $response = $this->json->error(401, 'Invalid API token.');
            } else {
                $stripped = $request->withUri($request->getUri()->withPath($path));
                $response = $this->router->dispatch($stripped);
            }
        } catch (ApiException $exception) {
            $response = $this->json->error($exception->status, $exception->detail);
        } catch (AuthenticationException) {
            $response = $this->json->error(401, 'Space-Track authentication failed.');
        } catch (QueryException $exception) {
            $status = $exception->statusCode >= 400 && $exception->statusCode <= 599 ? $exception->statusCode : 502;
            $response = $this->json->error($status, $exception->getMessage() !== '' ? $exception->getMessage() : 'Space-Track query failed.');
        } catch (RateLimitException | AdvisoryLimitException $exception) {
            $response = $this->json->error(429, $exception->getMessage());
        } catch (SpaceTrackException $exception) {
            $response = $this->json->error(502, $exception->getMessage() !== '' ? $exception->getMessage() : 'Space-Track request failed.');
        } catch (\Throwable $exception) {
            $this->logger->error('spaced-tracker.api.exception', ['exception' => $exception::class]);
            $response = $this->json->error(500, 'Internal server error.');
        }

        $response = $this->withCors($response, $origin);
        $this->logger->info('spaced-tracker.api.request', [
            'method' => $request->getMethod(),
            'path' => $path,
            'status' => $response->getStatusCode(),
            'duration_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
            'mode' => $this->config->mode,
        ]);

        return $response;
    }

    public function run(?ServerRequestInterface $request = null): void
    {
        $this->emit($this->handle($request ?? self::captureRequest()));
    }

    public function emit(ResponseInterface $response): void
    {
        if (!headers_sent()) {
            http_response_code($response->getStatusCode());
            foreach ($response->getHeaders() as $name => $values) {
                $replace = true;
                foreach ($values as $value) {
                    header($name . ': ' . $value, $replace);
                    $replace = false;
                }
            }
        }
        echo (string) $response->getBody();
    }

    public static function captureRequest(): ServerRequestInterface
    {
        $factory = new Psr17Factory();
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if (!is_string($method) || $method === '') {
            $method = 'GET';
        }
        $uri = self::requestTarget();
        $serverRequest = $factory->createServerRequest($method, $factory->createUri($uri));
        $query = [];
        $queryString = parse_url($uri, PHP_URL_QUERY);
        if (is_string($queryString) && $queryString !== '') {
            parse_str($queryString, $query);
        }
        $serverRequest = $serverRequest->withQueryParams($query);
        $body = file_get_contents('php://input');
        if (is_string($body) && $body !== '') {
            $serverRequest = $serverRequest->withBody($factory->createStream($body));
        }
        foreach (['CONTENT_TYPE' => 'Content-Type', 'HTTP_AUTHORIZATION' => 'Authorization', 'HTTP_ORIGIN' => 'Origin'] as $serverKey => $header) {
            $value = $_SERVER[$serverKey] ?? '';
            if (is_string($value) && $value !== '') {
                $serverRequest = $serverRequest->withHeader($header, $value);
            }
        }

        return $serverRequest;
    }

    private function stripPrefix(string $path): string
    {
        if ($path === '') {
            $path = '/';
        }
        $prefix = $this->config->prefix;
        if ($prefix === '') {
            return $path;
        }
        if ($path === $prefix) {
            return '/';
        }
        if (str_starts_with($path, $prefix . '/')) {
            $stripped = substr($path, strlen($prefix));

            return $stripped === '' ? '/' : $stripped;
        }

        throw ApiException::notFound();
    }

    private function authorized(ServerRequestInterface $request, string $path): bool
    {
        if (in_array($path, ['/health', '/docs', '/openapi.json'], true)) {
            return true;
        }
        $token = $this->config->apiToken;
        if ($token === null) {
            return true;
        }
        $header = $request->getHeaderLine('Authorization');
        if (!str_starts_with($header, 'Bearer ')) {
            return false;
        }

        return hash_equals($token, substr($header, 7));
    }

    private function withCors(ResponseInterface $response, string $origin): ResponseInterface
    {
        $response = $response->withHeader('Vary', 'Origin');
        if ($origin === '' || !in_array($origin, $this->config->corsOrigins, true)) {
            return $response;
        }

        return $response
            ->withHeader('Access-Control-Allow-Origin', $origin)
            ->withHeader('Access-Control-Allow-Methods', 'GET, POST, DELETE, OPTIONS')
            ->withHeader('Access-Control-Allow-Headers', 'Authorization, Content-Type');
    }

    private static function requestTarget(): string
    {
        $https = $_SERVER['HTTPS'] ?? '';
        $scheme = is_string($https) && $https !== '' && $https !== 'off' ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? '127.0.0.1';
        if (!is_string($host) || $host === '' || preg_match('/^[A-Za-z0-9._:-]+$/', $host) !== 1) {
            $host = '127.0.0.1';
        }
        $target = $_SERVER['REQUEST_URI'] ?? '/';
        if (!is_string($target) || $target === '' || str_contains($target, "\n") || str_contains($target, "\r")) {
            $target = '/';
        }

        return $scheme . '://' . $host . $target;
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Scribase;

use App\Scribase\ScribaseClient;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * A scripted fake of the Scribase management API that records every request.
 * Response bodies mirror shapes captured from the live API.
 */
final class MockApi
{
    /** @var list<array{method: string, path: string, body: array<string, mixed>|null}> */
    public array $requests = [];

    /**
     * @param array<string, \Closure(array<string, mixed>|null): array{0: int, 1: array<mixed>}|array{0: int, 1: array<mixed>}> $routes "METHOD /path" => [status, body]
     */
    public function __construct(private array $routes)
    {
    }

    public function client(): ScribaseClient
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $path = (string) parse_url($url, \PHP_URL_PATH);
            $body = isset($options['body']) && '' !== $options['body'] ? json_decode((string) $options['body'], true) : null;
            $this->requests[] = ['method' => $method, 'path' => $path, 'body' => $body];

            $route = $this->routes["{$method} {$path}"] ?? null;
            if (null === $route) {
                return new MockResponse((string) json_encode(['code' => 'route_not_found']), ['http_code' => 404]);
            }
            [$status, $payload] = $route instanceof \Closure ? $route($body) : $route;

            return new MockResponse((string) json_encode($payload), ['http_code' => $status]);
        });

        return new ScribaseClient($http, 'test-token', 'https://api.scribase.test', 2, 0);
    }

    /**
     * @return list<array{method: string, path: string, body: array<string, mixed>|null}>
     */
    public function requestsTo(string $method, string $pathSuffix): array
    {
        return array_values(array_filter($this->requests, static fn (array $r): bool => $r['method'] === $method && str_ends_with($r['path'], $pathSuffix)));
    }

    /**
     * @return array{0: int, 1: array<string, mixed>}
     */
    public static function environment(string $id, string $kind, string $phase = 'ready'): array
    {
        return [200, ['organization_id' => 'org', 'project_id' => 'proj', 'environment_id' => $id, 'kind' => $kind, 'phase' => $phase]];
    }
}

<?php

declare(strict_types=1);

namespace App\Scribase;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Thin client for Scribase's public `/v1` management API.
 *
 * Every method returns `[statusCode, decodedBody]` and never throws for an HTTP
 * error response; a transport failure (DNS, TLS, timeout) is reported as status 0
 * with an `error` key so tools can surface it to the model instead of crashing.
 */
class ScribaseClient
{
    public const USER_AGENT = 'scribase-branch-pilot/0.1 (+https://scribase.com)';

    private const TIMEOUT = 30.0;
    private const TERMINAL_STATES = ['succeeded', 'failed', 'cancelled'];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[\SensitiveParameter]
        #[Autowire(env: 'SCRIBASE_API_KEY')]
        private readonly string $apiKey,
        #[Autowire(env: 'SCRIBASE_API_BASE')]
        private readonly string $apiBase = 'https://api.scribase.com',
        #[Autowire(env: 'int:SCRIBASE_POLL_ATTEMPTS')]
        private readonly int $pollAttempts = 20,
        #[Autowire(env: 'int:SCRIBASE_POLL_INTERVAL')]
        private readonly int $pollIntervalSeconds = 3,
    ) {
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, string>     $headers
     *
     * @return array{0: int, 1: mixed}
     */
    public function request(string $method, string $path, ?array $body = null, array $headers = []): array
    {
        if ('' === $this->apiKey) {
            return [0, ['error' => 'SCRIBASE_API_KEY is empty. Run "bin/console pilot:bootstrap" to mint a free temporary project.']];
        }

        $options = [
            'timeout' => self::TIMEOUT,
            'headers' => array_merge([
                'Authorization' => 'Bearer '.$this->apiKey,
                'Accept' => 'application/json',
                // A bare default User-Agent is blocked by the WAF in front of api.scribase.com.
                'User-Agent' => self::USER_AGENT,
            ], $headers),
        ];
        if (null !== $body) {
            $options['json'] = $body;
        }

        try {
            $response = $this->httpClient->request($method, rtrim($this->apiBase, '/').$path, $options);
            // HttpClient is lazy: transport errors surface when the response is read.
            $status = $response->getStatusCode();
            $raw = $response->getContent(false);
        } catch (TransportExceptionInterface $e) {
            return [0, ['error' => 'could not reach the Scribase API: '.$e->getMessage()]];
        }

        if ('' === trim($raw)) {
            return [$status, []];
        }

        try {
            return [$status, json_decode($raw, true, flags: \JSON_THROW_ON_ERROR)];
        } catch (\JsonException) {
            return [$status, ['error' => substr($raw, 0, 500)]];
        }
    }

    public static function idempotencyKey(): string
    {
        return Uuid::v4()->toRfc4122();
    }

    /**
     * Polls an async operation until it reaches a terminal state, bounded so an
     * unattended tool call can never hang forever.
     *
     * @return array{0: ?string, 1: array<string, mixed>}
     */
    public function pollOperation(string $operationId, string $organizationId): array
    {
        $state = null;
        $last = [];
        for ($i = 0; $i < $this->pollAttempts; ++$i) {
            if ($this->pollIntervalSeconds > 0) {
                sleep($this->pollIntervalSeconds);
            }
            [$status, $body] = $this->request('GET', "/v1/operations/{$operationId}", headers: ['X-Scribase-Organization-ID' => $organizationId]);
            if (200 === $status && \is_array($body)) {
                $last = $body;
                $state = \is_string($body['state'] ?? null) ? $body['state'] : null;
                if (\in_array($state, self::TERMINAL_STATES, true)) {
                    break;
                }
            }
        }

        return [$state, $last];
    }

    /**
     * Reads an environment's metadata (kind, base, status).
     *
     * @return array{0: int, 1: mixed}
     */
    public function getEnvironment(string $org, string $project, string $environment): array
    {
        return $this->request('GET', "/v1/organizations/{$org}/projects/{$project}/environments/{$environment}");
    }

    /**
     * @return array<string, mixed>
     */
    public function readEnvironmentAccess(string $org, string $project, string $environment): array
    {
        [$status, $keys] = $this->request('GET', "/v1/organizations/{$org}/projects/{$project}/environments/{$environment}/api-keys");
        if (200 !== $status || !\is_array($keys)) {
            return ['status' => 'ready', 'warning' => 'environment is ready, but its API keys could not be read', 'detail' => $keys];
        }

        [$connStatus, $conn] = $this->request('GET', "/v1/organizations/{$org}/projects/{$project}/environments/{$environment}/connection");

        return [
            'status' => 'ready',
            'api_url' => $keys['api_url'] ?? null,
            'anon_key' => $keys['anon_key'] ?? null,
            'publishable_key' => $keys['publishable_key'] ?? null,
            'service_role_key' => $keys['service_role_key'] ?? null,
            'secret_key' => $keys['secret_key'] ?? null,
            'connection_uri' => (200 === $connStatus && \is_array($conn)) ? ($conn['connection_uri'] ?? null) : null,
        ];
    }
}

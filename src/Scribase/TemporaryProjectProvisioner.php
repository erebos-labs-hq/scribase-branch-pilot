<?php

declare(strict_types=1);

namespace App\Scribase;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Mints a free, no-login, no-card Scribase "temporary project" (72h lifetime)
 * through `POST /v1/temporary-projects`, which is gated by a small SHA-256
 * proof-of-work challenge instead of an account.
 */
final class TemporaryProjectProvisioner
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire(env: 'SCRIBASE_API_BASE')]
        private readonly string $apiBase = 'https://api.scribase.com',
    ) {
    }

    /**
     * Finds the smallest nonce so that sha256("<challenge>:<nonce>") starts with $bits zero bits.
     */
    public static function solve(string $challenge, int $bits): int
    {
        $zeroBytes = intdiv($bits, 8);
        $remainder = $bits % 8;
        for ($nonce = 0; ; ++$nonce) {
            $hash = hash('sha256', $challenge.':'.$nonce, true);
            if (0 !== $zeroBytes && str_repeat("\x00", $zeroBytes) !== substr($hash, 0, $zeroBytes)) {
                continue;
            }
            if ($remainder > 0 && 0 !== (\ord($hash[$zeroBytes]) >> (8 - $remainder))) {
                continue;
            }

            return $nonce;
        }
    }

    /**
     * @param callable(string): void|null $log
     *
     * @return array{organization_id: string, project_id: string, environment_id: string, agent_token: string, id: string, status: string}
     */
    public function provision(string $name, ?callable $log = null, int $maxPolls = 60): array
    {
        $log ??= static function (string $line): void {};
        $headers = ['User-Agent' => ScribaseClient::USER_AGENT, 'Accept' => 'application/json'];
        $base = rtrim($this->apiBase, '/');

        $challenge = $this->httpClient->request('POST', $base.'/v1/temporary-projects/challenge', ['headers' => $headers])->toArray();
        $nonce = self::solve((string) $challenge['challenge'], (int) $challenge['bits']);
        $log(\sprintf('solved proof-of-work: %d bits, nonce %d', (int) $challenge['bits'], $nonce));

        $response = $this->httpClient->request('POST', $base.'/v1/temporary-projects', [
            'headers' => $headers,
            'json' => ['name' => $name, 'challenge' => $challenge['challenge'], 'nonce' => (string) $nonce],
        ]);
        $created = $response->toArray(false);
        if (201 !== $response->getStatusCode()) {
            throw new \RuntimeException(\sprintf('Scribase refused the temporary project (HTTP %d): %s', $response->getStatusCode(), json_encode($created['code'] ?? $created['detail'] ?? $created)));
        }
        $log(\sprintf('temporary project %s/%s accepted, waiting for it to be ready', $created['organization_id'], $created['project_id']));

        for ($i = 0; $i < $maxPolls; ++$i) {
            sleep(5);
            $status = $this->httpClient->request('GET', $base.'/v1/temporary-projects/'.$created['id'], [
                'headers' => $headers + ['Authorization' => 'Bearer '.$created['agent_token']],
            ])->toArray(false);
            $state = (string) ($status['status'] ?? 'unknown');
            $log('status: '.$state);
            if ('ready' === $state) {
                return [
                    'id' => (string) $created['id'],
                    'organization_id' => (string) $created['organization_id'],
                    'project_id' => (string) $created['project_id'],
                    'environment_id' => (string) $created['environment_id'],
                    'agent_token' => (string) $created['agent_token'],
                    'status' => $state,
                ];
            }
            if ('failed' === $state) {
                break;
            }
        }

        throw new \RuntimeException('The temporary project did not become ready in time.');
    }
}

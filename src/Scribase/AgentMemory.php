<?php

declare(strict_types=1);

namespace App\Scribase;

/**
 * Persistent agent memory stored as rows in the project's own Postgres
 * (`public.agent_memories`), not in a context window. Every value interpolated
 * into SQL goes through {@see SqlLiteral::encode()}; the model never sends SQL here.
 */
class AgentMemory
{
    public const ACTIONS = ['store', 'recall', 'search', 'forget'];
    private const TABLE = 'public.agent_memories';
    private const SCOPE_MAX = 128;
    private const CONTENT_MAX = 8192;
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    public function __construct(private readonly ScribaseClient $client)
    {
    }

    /**
     * @param array<string, mixed>|null $metadata
     *
     * @return array<string, mixed>
     */
    public function handle(string $action, string $org, string $project, string $environment, string $scope, ?string $content = null, ?array $metadata = null, ?string $memoryId = null, ?string $query = null, ?int $limit = null): array
    {
        $action = strtolower(trim($action));
        if (!\in_array($action, self::ACTIONS, true)) {
            return ['error' => 'action must be one of: '.implode(', ', self::ACTIONS).'.'];
        }
        $scope = trim($scope) ?: 'default';
        if (\strlen($scope) > self::SCOPE_MAX) {
            return ['error' => \sprintf('scope must be at most %d characters.', self::SCOPE_MAX)];
        }

        return match ($action) {
            'store' => $this->store($org, $project, $environment, $scope, $content, $metadata),
            'forget' => $this->forget($org, $project, $environment, $scope, $memoryId),
            default => $this->recall($org, $project, $environment, $scope, 'search' === $action ? $query : null, $limit),
        };
    }

    /**
     * @param array<string, mixed>|null $metadata
     *
     * @return array<string, mixed>
     */
    private function store(string $org, string $project, string $env, string $scope, ?string $content, ?array $metadata): array
    {
        $content = trim($content ?? '');
        if ('' === $content) {
            return ['error' => "content is required for action='store'."];
        }
        if (\strlen($content) > self::CONTENT_MAX) {
            return ['error' => \sprintf('content must be at most %d characters.', self::CONTENT_MAX)];
        }
        $sql = \sprintf(
            'insert into %s (scope, content, metadata) values (%s, %s, (%s)::jsonb) returning id, created_at;',
            self::TABLE,
            SqlLiteral::encode($scope),
            SqlLiteral::encode($content),
            SqlLiteral::encode(json_encode($metadata ?? new \stdClass(), \JSON_THROW_ON_ERROR)),
        );
        [$status, $body] = $this->execute($org, $project, $env, $sql);
        if (400 === $status && \is_array($body) && self::isMissingTable($body)) {
            if (null !== $error = $this->createTable($org, $project, $env)) {
                return $error;
            }
            [$status, $body] = $this->execute($org, $project, $env, $sql);
        }
        if (200 !== $status || !\is_array($body) || 'committed' !== ($body['status'] ?? 'committed')) {
            return ['error' => 'failed to store memory', 'status' => $status, 'detail' => $body];
        }
        $row = $body['statements'][0]['rows'][0] ?? [];

        return ['status' => 'stored', 'scope' => $scope, 'memory_id' => $row['id'] ?? null, 'created_at' => $row['created_at'] ?? null];
    }

    /**
     * @return array<string, mixed>
     */
    private function forget(string $org, string $project, string $env, string $scope, ?string $memoryId): array
    {
        $memoryId = trim($memoryId ?? '');
        if (1 !== preg_match(self::UUID, $memoryId)) {
            return ['error' => "memoryId must be the UUID a prior action='store' returned."];
        }

        // `id` is a uuid column and the encoded literal is text: Postgres has no
        // implicit uuid = text operator, so the explicit cast is required.
        $sql = \sprintf('delete from %s where id = (%s)::uuid and scope = %s returning id;', self::TABLE, SqlLiteral::encode($memoryId), SqlLiteral::encode($scope));
        [$status, $body] = $this->execute($org, $project, $env, $sql);
        if (200 !== $status || !\is_array($body) || 'committed' !== ($body['status'] ?? 'committed')) {
            return ['error' => 'failed to forget memory', 'status' => $status, 'detail' => $body];
        }
        $first = $body['statements'][0] ?? [];
        $found = !empty($first['rows']) || (\is_int($first['affected_rows'] ?? null) && $first['affected_rows'] > 0);

        return ['status' => $found ? 'forgotten' : 'not_found', 'scope' => $scope, 'memory_id' => $memoryId];
    }

    /**
     * @return array<string, mixed>
     */
    private function recall(string $org, string $project, string $env, string $scope, ?string $search, ?int $limit): array
    {
        $limit = max(1, min($limit ?? 20, 200));
        $where = 'scope = '.SqlLiteral::encode($scope);
        if (null !== $search && '' !== trim($search)) {
            $where .= ' and content ilike '.SqlLiteral::encode('%'.trim($search).'%');
        }
        $sql = \sprintf('select id, content, metadata, created_at from %s where %s order by created_at desc limit %d;', self::TABLE, $where, $limit);

        [$status, $body] = $this->client->request('POST', "/v1/organizations/{$org}/projects/{$project}/environments/{$env}/database/query", ['query' => $sql, 'limit' => $limit]);
        if (400 === $status && \is_array($body) && self::isMissingTable($body)) {
            return ['status' => 'ok', 'scope' => $scope, 'memories' => [], 'note' => 'no memories stored yet'];
        }
        if (200 !== $status || !\is_array($body)) {
            return ['error' => 'failed to read memories', 'status' => $status, 'detail' => $body];
        }

        return ['status' => 'ok', 'scope' => $scope, 'memories' => $body['rows'] ?? []];
    }

    /**
     * @return array{0: int, 1: mixed}
     */
    private function execute(string $org, string $project, string $env, string $sql): array
    {
        return $this->client->request('POST', "/v1/organizations/{$org}/projects/{$project}/environments/{$env}/database/execute", ['query' => $sql]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function createTable(string $org, string $project, string $env): ?array
    {
        $ddl = 'create table if not exists '.self::TABLE.' (id uuid primary key default gen_random_uuid(), '
            ."scope text not null default 'default', content text not null, "
            ."metadata jsonb not null default '{}'::jsonb, created_at timestamptz not null default now()); "
            .'create index if not exists agent_memories_scope_idx on '.self::TABLE.' (scope, created_at desc); '
            .'alter table '.self::TABLE.' enable row level security; '
            // Only the service role (the management API) may touch agent memory, never anon/auth clients.
            .'create policy agent_memories_service_role on '.self::TABLE.' for all to service_role using (auth.role() = \'service_role\') with check (auth.role() = \'service_role\');';
        [$status, $body] = $this->execute($org, $project, $env, $ddl);

        // The execute route answers 200 + status=confirmation_required without running anything
        // when a script looks destructive, so only status=committed counts as success.
        return 200 === $status && \is_array($body) && 'committed' === ($body['status'] ?? 'committed') ? null : ['error' => 'failed to prepare the memory table', 'status' => $status, 'detail' => $body];
    }

    /**
     * @param array<mixed> $body
     */
    private static function isMissingTable(array $body): bool
    {
        return \in_array($body['code'] ?? null, ['relation_does_not_exist', 'undefined_table'], true)
            || str_contains(\is_string($body['detail'] ?? null) ? $body['detail'] : '', '42P01');
    }
}

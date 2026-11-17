<?php

declare(strict_types=1);

namespace App\Scribase;

use Symfony\Component\Uid\Uuid;

/**
 * Copy-on-write branch lifecycle: create, apply a migration (branch only),
 * plan a merge, merge, discard. Shared by the MCP tools, the resources and the
 * human-facing console commands so every entry point enforces the same rules.
 */
class BranchService
{
    public const PRODUCTION = 'production';
    public const REGION = 'eu-frankfurt';
    public const POSTGRES_MAJOR = 17;
    public const MAX_TTL_HOURS = 24;
    public const DATA_MODES = ['snapshot', 'sanitized', 'schema_only'];

    public function __construct(private readonly ScribaseClient $client)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function create(string $org, string $project, ?string $branchId = null, string $base = self::PRODUCTION, string $dataMode = 'schema_only', int $ttlHours = 4): array
    {
        $branchId = trim($branchId ?? '') ?: 'pilot-'.strtolower(substr(Uuid::v4()->toBase32(), 0, 8));

        if (!ScribaseTarget::validId($branchId)) {
            return ['error' => 'branchId must be lowercase letters, digits and dashes (max 63 chars).'];
        }
        if ($branchId === $base) {
            return ['error' => 'a branch cannot be its own base environment.'];
        }
        if (!\in_array($dataMode, self::DATA_MODES, true)) {
            return ['error' => 'dataMode must be one of: '.implode(', ', self::DATA_MODES).'.'];
        }
        if ($ttlHours < 1 || $ttlHours > self::MAX_TTL_HOURS) {
            return ['error' => \sprintf('ttlHours must be between 1 and %d.', self::MAX_TTL_HOURS)];
        }

        [$status, $body] = $this->client->request('POST', '/v1/environments', [
            'organization_id' => $org, 'project_id' => $project, 'environment_id' => $branchId,
            'kind' => 'preview', 'data_mode' => $dataMode, 'region' => self::REGION,
            'postgres_major' => self::POSTGRES_MAJOR, 'ttl_hours' => $ttlHours, 'base_environment_id' => $base,
        ], ['Idempotency-Key' => ScribaseClient::idempotencyKey()]);

        if (!\in_array($status, [200, 202], true) || !\is_array($body)) {
            return ['error' => 'failed to create branch', 'status' => $status, 'detail' => $body];
        }
        $operationId = $body['id'] ?? null;
        if (!\is_string($operationId)) {
            return ['error' => 'branch create accepted but no operation id was returned', 'detail' => $body];
        }

        [$state] = $this->client->pollOperation($operationId, $org);
        if ('succeeded' !== $state) {
            return [
                'status' => \in_array($state, ['failed', 'cancelled'], true) ? 'failed' : 'provisioning',
                'branch_id' => $branchId,
                'operation_id' => $operationId,
                'message' => "Branch operation ended in state {$state}. Call scribase_branch_create again with the same branchId to resume.",
            ];
        }

        return [
            'status' => 'ready',
            'branch_id' => $branchId,
            'base_environment_id' => $base,
            'data_mode' => $dataMode,
            'ttl_hours' => $ttlHours,
            'next_step' => "Apply migrations with scribase_apply_on_branch(branchId: '{$branchId}'), inspect with scribase_query, then ask for scribase_promote_branch or scribase_branch_discard. It self-deletes after {$ttlHours}h.",
        ];
    }

    /**
     * Returns null when the environment is a preview branch, an error array otherwise.
     *
     * @return array<string, mixed>|null
     */
    public function assertIsBranch(string $org, string $project, string $environmentId): ?array
    {
        if (self::PRODUCTION === $environmentId) {
            return ['error' => 'refused: "production" is never a branch. Create a branch first with scribase_branch_create.'];
        }

        [$status, $env] = $this->client->getEnvironment($org, $project, $environmentId);
        if (404 === $status) {
            return ['error' => "branch '{$environmentId}' does not exist."];
        }
        if (200 !== $status || !\is_array($env)) {
            return ['error' => 'could not read the environment', 'status' => $status, 'detail' => $env];
        }
        if ('preview' !== ($env['kind'] ?? null)) {
            return ['error' => \sprintf("refused: environment '%s' is a %s environment, not a preview branch.", $environmentId, \is_string($env['kind'] ?? null) ? $env['kind'] : 'non-branch')];
        }
        if ('ready' !== ($env['phase'] ?? null)) {
            return ['error' => \sprintf("branch '%s' is not ready yet (phase: %s).", $environmentId, \is_string($env['phase'] ?? null) ? $env['phase'] : 'unknown')];
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function lint(string $org, string $project, string $environmentId, string $up, ?string $down = null, string $name = 'pilot_lint_check'): array
    {
        return $this->migrate($org, $project, $environmentId, $up, $down, $name, false);
    }

    /**
     * Applies a migration, but only on a preview branch, and only if the
     * linter finds nothing blocking first.
     *
     * @return array<string, mixed>
     */
    public function applyOnBranch(string $org, string $project, string $branchId, string $up, ?string $down = null, string $name = 'pilot_migration'): array
    {
        if (null !== $refusal = $this->assertIsBranch($org, $project, $branchId)) {
            return $refusal;
        }

        $lint = $this->lint($org, $project, $branchId, $up, $down, $name);
        if (isset($lint['error'])) {
            return $lint;
        }
        if ('blocked' === $lint['status']) {
            return ['status' => 'refused_by_linter', 'applied' => false] + $lint;
        }

        $result = $this->migrate($org, $project, $branchId, $up, $down, $name, true);
        if (isset($result['error'])) {
            return $result;
        }

        return ['status' => 'applied', 'branch_id' => $branchId] + $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function migrate(string $org, string $project, string $environmentId, string $up, ?string $down, string $name, bool $apply): array
    {
        $up = trim($up);
        if ('' === $up) {
            return ['error' => 'up is required (the migration forward SQL).'];
        }
        $name = preg_replace('/[^a-z0-9_]+/', '_', strtolower(trim($name))) ?: 'pilot_migration';

        $migration = ['version' => gmdate('YmdHis'), 'name' => $name, 'up' => $up];
        if (null !== $down && '' !== trim($down)) {
            $migration['down'] = $down;
        }

        [$status, $body] = $this->client->request('POST', "/v1/organizations/{$org}/projects/{$project}/environments/{$environmentId}/migrations", [
            'migration' => $migration,
            'apply' => $apply,
        ]);
        if (200 !== $status || !\is_array($body)) {
            return ['error' => $apply ? 'migration apply failed' : 'migration lint request failed', 'status' => $status, 'detail' => $body];
        }

        $findings = \is_array($body['lintFindings'] ?? null) ? $body['lintFindings'] : [];
        $blocking = array_values(array_filter($findings, static fn ($f): bool => \is_array($f) && 'BLOCK' === ($f['severity'] ?? null)));
        $warnings = array_values(array_filter($findings, static fn ($f): bool => \is_array($f) && 'WARN' === ($f['severity'] ?? null)));

        $result = [
            'status' => [] !== $blocking ? 'blocked' : ([] !== $warnings ? 'warnings' : 'clean'),
            'safe_to_apply' => [] === $blocking,
            'blocking_findings' => $blocking,
            'warning_findings' => $warnings,
        ];
        if ($apply) {
            $result['applied_versions'] = $body['applied'] ?? [];
        }

        return $result;
    }

    /**
     * Plans (dry run) or performs the three-way schema merge of a branch into its base.
     *
     * @return array<string, mixed>
     */
    public function merge(string $org, string $project, string $branchId, string $base = self::PRODUCTION, bool $dryRun = true): array
    {
        if ($branchId === $base) {
            return ['error' => 'a branch cannot be merged into itself.'];
        }

        [$status, $body] = $this->client->request('POST', "/v1/organizations/{$org}/projects/{$project}/branches/merge", [
            'base' => $base, 'head' => $branchId, 'force' => false, 'dry_run' => $dryRun,
        ]);
        if (200 !== $status || !\is_array($body)) {
            return ['error' => 'merge failed', 'status' => $status, 'detail' => $body];
        }

        $changes = array_map(
            static fn ($c): string => \is_array($c) ? \sprintf('%s %s', $c['action'] ?? '?', $c['object'] ?? '?') : (string) json_encode($c),
            \is_array($body['changes'] ?? null) ? $body['changes'] : [],
        );
        $conflicts = \is_array($body['conflicts'] ?? null) ? $body['conflicts'] : [];
        $applied = (bool) ($body['applied'] ?? false);

        return [
            'status' => match (true) {
                $applied => 'merged',
                [] !== $conflicts => 'blocked_by_conflicts',
                [] === $changes => 'no_changes',
                default => 'ready_to_merge',
            },
            'branch_id' => $branchId,
            'base_environment_id' => $base,
            'dry_run' => $dryRun,
            'applied' => $applied,
            'changes' => $changes,
            'conflicts' => $conflicts,
            'migrations_to_replay' => $body['migrations']['to_replay'] ?? [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function discard(string $org, string $project, string $branchId): array
    {
        if (null !== $refusal = $this->assertIsBranch($org, $project, $branchId)) {
            return $refusal;
        }

        [$status, $body] = $this->client->request('DELETE', "/v1/organizations/{$org}/projects/{$project}/environments/{$branchId}", headers: ['Idempotency-Key' => ScribaseClient::idempotencyKey()]);
        if (202 !== $status || !\is_array($body) || !\is_string($body['id'] ?? null)) {
            return ['error' => 'failed to discard branch', 'status' => $status, 'detail' => $body];
        }

        [$state] = $this->client->pollOperation($body['id'], $org);

        return ['status' => 'succeeded' === $state ? 'discarded' : 'discarding', 'branch_id' => $branchId];
    }
}

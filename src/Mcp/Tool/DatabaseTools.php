<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Scribase\BranchService;
use App\Scribase\ScribaseClient;
use App\Scribase\ScribaseTarget;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\ToolAnnotations;

final class DatabaseTools
{
    public function __construct(
        private readonly ScribaseClient $client,
        private readonly BranchService $branches,
        private readonly ScribaseTarget $target,
    ) {
    }

    /**
     * Runs Scribase's migration-safety linter (missing RLS, permissive policies, GRANT to PUBLIC, SECURITY DEFINER, ...) without applying anything.
     *
     * @param string      $up            forward SQL to lint
     * @param string|null $down          optional reverse SQL
     * @param string      $environmentId environment whose current schema the rules read
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: 'scribase_lint_migration', annotations: new ToolAnnotations(title: 'Lint a migration', readOnlyHint: true, openWorldHint: true))]
    public function lint(string $up, ?string $down = null, string $environmentId = BranchService::PRODUCTION): array
    {
        $target = $this->target->resolve();
        if (isset($target['error'])) {
            return ToolResult::of($target);
        }

        return ToolResult::of($this->branches->lint($target[0], $target[1], trim($environmentId), $up, $down));
    }

    /**
     * Runs one read-only SQL statement. Scribase enforces read-only server-side (keyword check plus a READ ONLY transaction).
     *
     * @param string   $query         a single read-only SQL statement
     * @param string   $environmentId environment (or branch) to query
     * @param int|null $limit         row cap, 1-1000
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: 'scribase_query', annotations: new ToolAnnotations(title: 'Read-only SQL', readOnlyHint: true, openWorldHint: true))]
    public function query(string $query, string $environmentId = BranchService::PRODUCTION, ?int $limit = null): array
    {
        $target = $this->target->resolve();
        if (isset($target['error'])) {
            return ToolResult::of($target);
        }
        if ('' === trim($query)) {
            return ToolResult::of(['error' => 'query is required (a single read-only SQL statement).']);
        }

        $payload = ['query' => trim($query)];
        if (null !== $limit) {
            $payload['limit'] = max(1, min($limit, 1000));
        }

        [$status, $body] = $this->client->request('POST', \sprintf('/v1/organizations/%s/projects/%s/environments/%s/database/query', $target[0], $target[1], trim($environmentId)), $payload);
        if (200 !== $status) {
            return ToolResult::of(['error' => 'query failed', 'status' => $status, 'detail' => $body]);
        }

        return \is_array($body) ? $body : ['result' => $body];
    }
}

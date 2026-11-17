<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Pilot\PromotionGate;
use App\Scribase\BranchService;
use App\Scribase\ScribaseTarget;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server\RequestContext;

/**
 * The branch-per-task workflow exposed over MCP. An agent can create a branch,
 * change it freely and throw it away; touching the base environment always
 * goes through {@see PromotionGate}, which asks a human first.
 */
final class BranchTools
{
    public function __construct(
        private readonly BranchService $branches,
        private readonly ScribaseTarget $target,
        private readonly PromotionGate $gate,
    ) {
    }

    /**
     * Cuts a fresh copy-on-write Postgres branch from a base environment (production by default) for one task.
     *
     * @param string|null $branchId          lowercase id for the new branch; generated when omitted
     * @param string      $baseEnvironmentId environment to branch from
     * @param string      $dataMode          schema_only, sanitized or snapshot
     * @param int         $ttlHours          the branch deletes itself after this many hours (1-24)
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: 'scribase_branch_create', annotations: new ToolAnnotations(title: 'Create a throwaway branch', readOnlyHint: false, destructiveHint: false, openWorldHint: true))]
    public function create(?string $branchId = null, string $baseEnvironmentId = BranchService::PRODUCTION, string $dataMode = 'schema_only', int $ttlHours = 4): array
    {
        $target = $this->target->resolve();
        if (isset($target['error'])) {
            return ToolResult::of($target);
        }

        return ToolResult::of($this->branches->create($target[0], $target[1], $branchId, BranchService::environment($baseEnvironmentId), $dataMode, $ttlHours));
    }

    /**
     * Lints a migration, then applies it to a preview branch. Refuses production and any non-branch environment, and refuses migrations with blocking lint findings.
     *
     * @param string      $up       forward SQL of the migration
     * @param string|null $branchId the preview branch to change; defaults to the open branch
     * @param string|null $down     optional reverse SQL
     * @param string      $name     short snake_case name for the migration
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: 'scribase_apply_on_branch', annotations: new ToolAnnotations(title: 'Apply a migration to a branch (never production)', readOnlyHint: false, destructiveHint: true, openWorldHint: true))]
    public function apply(string $up, ?string $branchId = null, ?string $down = null, string $name = 'pilot_migration'): array
    {
        $target = $this->target->resolve();
        if (isset($target['error'])) {
            return ToolResult::of($target);
        }

        $branch = $this->branches->resolveBranch($target[0], $target[1], $branchId);
        if (\is_array($branch)) {
            return ToolResult::of($branch);
        }

        return ToolResult::of($this->branches->applyOnBranch($target[0], $target[1], $branch, $up, $down, $name));
    }

    /**
     * Plans the merge of a branch into its base and, only after a human confirms through MCP elicitation, performs it. Without a human it never merges.
     *
     * @param string|null $branchId   the preview branch to promote; defaults to the open branch
     * @param string $baseEnvironmentId the environment to merge into
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: 'scribase_promote_branch', annotations: new ToolAnnotations(title: 'Promote a branch (asks a human first)', readOnlyHint: false, destructiveHint: true, openWorldHint: true))]
    public function promote(RequestContext $context, ?string $branchId = null, string $baseEnvironmentId = BranchService::PRODUCTION): array
    {
        $target = $this->target->resolve();
        if (isset($target['error'])) {
            return ToolResult::of($target);
        }

        $branch = $this->branches->resolveBranch($target[0], $target[1], $branchId);
        if (\is_array($branch)) {
            return ToolResult::of($branch);
        }

        return ToolResult::of($this->gate->promote($target[0], $target[1], $branch, BranchService::environment($baseEnvironmentId), $context->getClientGateway()));
    }

    /**
     * Deletes a preview branch right away instead of waiting for its TTL. Refuses non-branch environments.
     *
     * @param string|null $branchId the preview branch to delete; defaults to the open branch
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: 'scribase_branch_discard', annotations: new ToolAnnotations(title: 'Discard a branch', readOnlyHint: false, destructiveHint: true, idempotentHint: true, openWorldHint: true))]
    public function discard(?string $branchId = null): array
    {
        $target = $this->target->resolve();
        if (isset($target['error'])) {
            return ToolResult::of($target);
        }

        $branch = $this->branches->resolveBranch($target[0], $target[1], $branchId);
        if (\is_array($branch)) {
            return ToolResult::of($branch);
        }

        return ToolResult::of($this->branches->discard($target[0], $target[1], $branch));
    }
}

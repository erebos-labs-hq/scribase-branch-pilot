<?php

declare(strict_types=1);

namespace App\Mcp\Resource;

use App\Pilot\PromotionGate;
use App\Scribase\BranchService;
use App\Scribase\SchemaReader;
use App\Scribase\ScribaseTarget;
use Mcp\Capability\Attribute\McpResource;
use Mcp\Capability\Attribute\McpResourceTemplate;

final class ScribaseResources
{
    public const GUIDE = <<<'MD'
        # Branch Pilot workflow

        Every schema change happens on a throwaway copy-on-write Postgres branch, never directly on production.

        1. `scribase_lint_migration` the SQL against production to catch unsafe patterns early.
        2. `scribase_branch_create` a branch for the task (schema_only is enough for DDL).
        3. `scribase_apply_on_branch` the migration. It refuses production and blocking lint findings.
        4. Verify with `scribase_query` on the branch and read `scribase://environments/{branch}/schema`.
        5. Read `scribase://branches/{branch}/diff` to see exactly what a merge would do.
        6. `scribase_promote_branch` asks the human (MCP elicitation) before merging. If they decline,
           or no human is reachable, nothing is merged. Otherwise `scribase_branch_discard` the branch.
        7. Store what you learned with `scribase_memory` so the next session starts informed.
        MD;

    public function __construct(
        private readonly SchemaReader $schema,
        private readonly BranchService $branches,
        private readonly ScribaseTarget $target,
    ) {
    }

    #[McpResource(uri: 'scribase://guide/branch-workflow', name: 'branch-workflow', description: 'How an agent should change a database with Branch Pilot.', mimeType: 'text/markdown')]
    public function guide(): string
    {
        return self::GUIDE;
    }

    #[McpResource(uri: 'scribase://branches', name: 'open-branches', description: 'Branches currently open in the project.', mimeType: 'application/json')]
    public function branches(): string
    {
        $target = $this->target->resolve();
        if (isset($target['error'])) {
            return $target['error'];
        }

        return (string) json_encode(['branches' => $this->branches->list($target[0], $target[1])], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);
    }

    #[McpResourceTemplate(uriTemplate: 'scribase://environments/{environment}/schema', name: 'environment-schema', description: 'Tables, columns, RLS and policies of an environment or branch.', mimeType: 'text/markdown')]
    public function schema(string $environment): string
    {
        $target = $this->target->resolve();
        if (isset($target['error'])) {
            return $target['error'];
        }

        return $this->schema->markdown($target[0], $target[1], $environment);
    }

    #[McpResourceTemplate(uriTemplate: 'scribase://branches/{branch}/diff', name: 'branch-diff', description: 'What merging this branch into production would change (dry run, nothing is applied).', mimeType: 'text/markdown')]
    public function diff(string $branch): string
    {
        $target = $this->target->resolve();
        if (isset($target['error'])) {
            return $target['error'];
        }

        $plan = $this->branches->merge($target[0], $target[1], $branch, BranchService::PRODUCTION, dryRun: true);
        if (isset($plan['error'])) {
            return \sprintf("# Diff of `%s`\n\n%s\n", $branch, json_encode($plan, \JSON_PRETTY_PRINT));
        }

        return \sprintf("# Diff of `%s` -> production\n\nStatus: **%s**\n\n%s\n", $branch, $plan['status'], PromotionGate::describe($plan));
    }
}

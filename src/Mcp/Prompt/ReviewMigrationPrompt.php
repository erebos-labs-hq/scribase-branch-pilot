<?php

declare(strict_types=1);

namespace App\Mcp\Prompt;

use App\Mcp\Resource\ScribaseResources;
use Mcp\Capability\Attribute\McpPrompt;

final class ReviewMigrationPrompt
{
    /**
     * @param string $sql  the migration to review (forward SQL)
     * @param string $goal what the change is for, in one sentence
     *
     * @return list<array{role: string, content: string}>
     */
    #[McpPrompt(name: 'review-migration', description: 'Rehearse a migration on a throwaway branch, report, and ask a human before promoting.')]
    public function __invoke(string $sql, string $goal = 'not specified'): array
    {
        return [
            ['role' => 'user', 'content' => self::text($sql, $goal)],
        ];
    }

    public static function text(string $sql, string $goal = 'not specified'): string
    {
        return <<<TXT
            You are the Scribase Branch Pilot. Rehearse this migration safely, then report.

            Goal: {$goal}

            ```sql
            {$sql}
            ```

            Steps (call the tools, do not just describe them):
            1. scribase_memory action=search scope=migrations to recall lessons from earlier reviews.
            2. scribase_lint_migration on production. If it is blocked, explain the findings, propose a fixed SQL and continue with the fixed SQL.
            3. scribase_branch_create (dataMode schema_only, ttlHours 1).
            4. scribase_apply_on_branch with the (possibly fixed) SQL. Use the branch_id returned in step 3 (or omit branchId: the open branch is used).
            5. scribase_query with environmentId = that branch_id to verify the new objects exist (for example query pg_tables / pg_policies).
            6. scribase_promote_branch (same branch). A human decides; never claim it was merged unless the tool says status=merged.
            7. If not merged, scribase_branch_discard the branch.
            8. scribase_memory action=store scope=migrations with a one-line lesson.

            Finish with a short report: lint result, what changed on the branch, the human decision, final state.

            Workflow reference:
            TXT.ScribaseResources::GUIDE;
    }
}

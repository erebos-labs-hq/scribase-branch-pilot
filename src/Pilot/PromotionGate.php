<?php

declare(strict_types=1);

namespace App\Pilot;

use App\Scribase\BranchService;
use Mcp\Server\ClientGateway;

/**
 * The only path from a branch into its base environment.
 *
 * 1. Refuses anything that is not a ready preview branch.
 * 2. Plans the merge as a dry run and shows the plan to a human.
 * 3. Merges only on an explicit "yes". No human reachable means no merge.
 */
class PromotionGate
{
    public function __construct(private readonly BranchService $branches)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function promote(string $org, string $project, string $branchId, string $base, ClientGateway|HumanApproval $approval): array
    {
        if ($approval instanceof ClientGateway) {
            $approval = new ElicitationApproval($approval);
        }

        if (null !== $refusal = $this->branches->assertIsBranch($org, $project, $branchId)) {
            return $refusal;
        }

        $plan = $this->branches->merge($org, $project, $branchId, $base, dryRun: true);
        if (isset($plan['error']) || 'ready_to_merge' !== $plan['status']) {
            return $plan;
        }

        $decision = $approval->confirm(self::describe($plan));

        if (null === $decision) {
            return [
                'status' => 'awaiting_human',
                'plan' => $plan,
                'message' => 'No human approval channel is available on this connection, so nothing was merged. '
                    ."A human can review and promote with: bin/console pilot:promote {$branchId}",
            ];
        }
        if (false === $decision) {
            return ['status' => 'declined_by_human', 'plan' => $plan, 'message' => 'The human declined. Nothing was merged; the branch is untouched.'];
        }

        return $this->branches->merge($org, $project, $branchId, $base, dryRun: false) + ['approved_by' => 'human'];
    }

    /**
     * @param array<string, mixed> $plan
     */
    public static function describe(array $plan): string
    {
        $changes = \is_array($plan['changes'] ?? null) ? $plan['changes'] : [];
        $lines = array_map(static fn ($c): string => '  - '.(\is_string($c) ? $c : (string) json_encode($c)), $changes);

        return \sprintf(
            "Promote branch '%s' into '%s'? The merge will:\n%s",
            \is_string($plan['branch_id'] ?? null) ? $plan['branch_id'] : '?',
            \is_string($plan['base_environment_id'] ?? null) ? $plan['base_environment_id'] : '?',
            implode("\n", $lines),
        );
    }
}

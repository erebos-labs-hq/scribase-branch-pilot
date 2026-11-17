<?php

declare(strict_types=1);

namespace App\Command;

use App\Pilot\HumanApproval;
use App\Pilot\PromotionGate;
use App\Scribase\AgentMemory;
use App\Scribase\BranchService;
use App\Scribase\SchemaReader;
use App\Scribase\ScribaseClient;
use App\Scribase\ScribaseTarget;
use Symfony\AI\McpBundle\Client\McpClientInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Target;

/**
 * End-to-end checks against the real Scribase API using the project in .env.local.
 * Every promotion here is declined by a scripted "human", so production is never changed.
 */
#[AsCommand('pilot:verify-live', 'Run the live end-to-end checklist against your Scribase project (never changes production).')]
final class VerifyLiveCommand
{
    private int $failures = 0;

    public function __construct(
        private readonly ScribaseClient $client,
        private readonly BranchService $branches,
        private readonly PromotionGate $gate,
        private readonly AgentMemory $memory,
        private readonly SchemaReader $schema,
        private readonly ScribaseTarget $target,
        #[Target('pilot')]
        private readonly McpClientInterface $mcp,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $target = $this->target->resolve();
        if (isset($target['error'])) {
            $io->error($target['error']);

            return 1;
        }
        [$org, $project] = $target;
        $table = 'verify_'.bin2hex(random_bytes(3));
        $unsafe = "create table public.{$table} (id uuid primary key default gen_random_uuid(), owner uuid not null references auth.users(id), body text not null);";
        $safe = $unsafe." create index on public.{$table} (owner); alter table public.{$table} enable row level security; "
            ."create policy {$table}_owner on public.{$table} for all using (owner = auth.uid()) with check (owner = auth.uid());";

        $io->title("Live checks on {$org}/{$project}");

        $io->section('MCP server over stdio');
        $tools = array_map(static fn ($t): string => $t->name, $this->mcp->get('scribase')->getTools());
        $this->check($io, 7 === \count($tools), 'stdio MCP server lists 7 tools: '.implode(', ', $tools));

        $io->section('Lint');
        $this->check($io, 'blocked' === ($this->branches->lint($org, $project, 'production', $unsafe)['status'] ?? null), 'a table without RLS is blocked');
        $this->check($io, 'clean' === ($this->branches->lint($org, $project, 'production', $safe)['status'] ?? null), 'the same table with RLS + owner policy is clean');

        $io->section('Guards');
        $this->check($io, str_starts_with((string) ($this->branches->applyOnBranch($org, $project, 'production', $safe)['error'] ?? ''), 'refused'), 'applying to production is refused');
        [$status] = $this->client->request('POST', "/v1/organizations/{$org}/projects/{$project}/environments/production/database/query", ['query' => "drop table if exists public.{$table};"]);
        $this->check($io, 400 === $status, 'a write through the read-only query route is rejected by Scribase');

        $io->section('Branch lifecycle');
        $branch = $this->branches->create($org, $project, 'verify-'.substr($table, 7), ttlHours: 1);
        $branchId = \is_string($branch['branch_id'] ?? null) ? $branch['branch_id'] : '';
        $this->check($io, 'ready' === ($branch['status'] ?? null), "branch {$branchId} is ready".(isset($branch['error']) ? ': '.json_encode($branch) : ''));
        if ('ready' === ($branch['status'] ?? null)) {
            $this->check($io, 'refused_by_linter' === ($this->branches->applyOnBranch($org, $project, $branchId, $unsafe)['status'] ?? null), 'an unsafe migration is refused on the branch too');
            $this->check($io, 'applied' === ($this->branches->applyOnBranch($org, $project, $branchId, $safe, name: 'verify')['status'] ?? null), 'the safe migration is applied on the branch');
            $this->check($io, str_contains($this->schema->markdown($org, $project, $branchId), $table), 'the branch schema resource shows the new table');
            $this->check($io, !str_contains($this->schema->markdown($org, $project, 'production'), $table), 'production does not have it');

            $declined = $this->gate->promote($org, $project, $branchId, 'production', new class implements HumanApproval {
                public function confirm(string $question): ?bool
                {
                    return false;
                }
            });
            $this->check($io, 'declined_by_human' === ($declined['status'] ?? null), 'a declined promotion merges nothing');
            $this->check($io, !str_contains($this->schema->markdown($org, $project, 'production'), $table), 'production is still unchanged');
            $this->check($io, 'discarded' === ($this->branches->discard($org, $project, $branchId)['status'] ?? null), 'the branch is discarded');
        }

        $io->section('Agent memory');
        $scope = 'verify-'.$table;
        $first = $this->memory->handle('store', $org, $project, 'production', $scope, "Quote ' backslash \\ and Türkçe");
        $this->check($io, 'stored' === ($first['status'] ?? null), 'store a memory with hostile and non-ASCII characters');
        $this->memory->handle('store', $org, $project, 'production', $scope, 'second memory about hackathons');
        $this->check($io, 2 === \count((array) ($this->memory->handle('recall', $org, $project, 'production', $scope)['memories'] ?? [])), 'recall returns both');
        $this->check($io, 1 === \count((array) ($this->memory->handle('search', $org, $project, 'production', $scope, query: 'HACKATHON')['memories'] ?? [])), 'search is case-insensitive');
        $this->check($io, [] === ($this->memory->handle('recall', $org, $project, 'production', $scope.'-other')['memories'] ?? null), 'another scope sees nothing');
        $memoryId = \is_string($first['memory_id'] ?? null) ? $first['memory_id'] : '';
        $this->check($io, 'forgotten' === ($this->memory->handle('forget', $org, $project, 'production', $scope, memoryId: $memoryId)['status'] ?? null), 'forget by id');

        if (0 === $this->failures) {
            $io->success('All live checks passed.');

            return 0;
        }
        $io->error("{$this->failures} check(s) failed.");

        return 1;
    }

    private function check(SymfonyStyle $io, bool $ok, string $label): void
    {
        $io->writeln(($ok ? '<info>PASS</info> ' : '<error>FAIL</error> ').$label);
        if (!$ok) {
            ++$this->failures;
        }
    }
}

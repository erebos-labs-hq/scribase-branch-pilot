<?php

declare(strict_types=1);

namespace App\Tests\Scribase;

use App\Scribase\BranchService;
use PHPUnit\Framework\TestCase;

final class BranchServiceTest extends TestCase
{
    private const MIGRATIONS = 'POST /v1/organizations/org/projects/proj/environments/feature/migrations';
    private const SQL = 'create table public.t (id uuid primary key);';

    public function testApplyRefusesProductionWithoutCallingTheApi(): void
    {
        $api = new MockApi([]);
        $result = (new BranchService($api->client()))->applyOnBranch('org', 'proj', 'production', self::SQL);

        self::assertStringStartsWith('refused', $result['error']);
        self::assertSame([], $api->requests);
    }

    public function testApplyRefusesAnEnvironmentThatIsNotAPreviewBranch(): void
    {
        $api = new MockApi(['GET /v1/organizations/org/projects/proj/environments/staging' => MockApi::environment('staging', 'production')]);
        $result = (new BranchService($api->client()))->applyOnBranch('org', 'proj', 'staging', self::SQL);

        self::assertStringContainsString('not a preview branch', $result['error']);
        self::assertSame([], $api->requestsTo('POST', '/migrations'));
    }

    public function testApplyRefusesWhenTheLinterBlocks(): void
    {
        $api = new MockApi([
            'GET /v1/organizations/org/projects/proj/environments/feature' => MockApi::environment('feature', 'preview'),
            self::MIGRATIONS => [200, ['lintFindings' => [['rule' => 'missing_rls', 'severity' => 'BLOCK', 'subject' => 'public.t']], 'plan' => []]],
        ]);
        $result = (new BranchService($api->client()))->applyOnBranch('org', 'proj', 'feature', self::SQL);

        self::assertSame('refused_by_linter', $result['status']);
        self::assertFalse($result['applied']);
        $calls = $api->requestsTo('POST', '/migrations');
        self::assertCount(1, $calls, 'only the lint call, never an apply');
        self::assertFalse($calls[0]['body']['apply'] ?? null);
    }

    public function testApplyLintsThenAppliesOnACleanBranch(): void
    {
        $api = new MockApi([
            'GET /v1/organizations/org/projects/proj/environments/feature' => MockApi::environment('feature', 'preview'),
            self::MIGRATIONS => static fn (?array $body): array => [200, ['lintFindings' => [], 'applied' => true === ($body['apply'] ?? null) ? [20261117000000] : null]],
        ]);
        $result = (new BranchService($api->client()))->applyOnBranch('org', 'proj', 'feature', self::SQL, name: 'Add T!');

        self::assertSame('applied', $result['status']);
        self::assertSame([20261117000000], $result['applied_versions']);
        $calls = $api->requestsTo('POST', '/migrations');
        self::assertSame([false, true], array_map(static fn (array $c) => $c['body']['apply'] ?? null, $calls));
        self::assertSame('add_t_', $calls[1]['body']['migration']['name'] ?? null);
    }

    public function testApplyRefusesABranchThatIsNotReady(): void
    {
        $api = new MockApi(['GET /v1/organizations/org/projects/proj/environments/feature' => MockApi::environment('feature', 'preview', 'provisioning')]);
        $result = (new BranchService($api->client()))->applyOnBranch('org', 'proj', 'feature', self::SQL);

        self::assertStringContainsString('not ready', $result['error']);
    }

    public function testCreateSendsAPreviewEnvironmentAndPollsIt(): void
    {
        $api = new MockApi([
            'POST /v1/environments' => [202, ['id' => 'op-1', 'state' => 'pending']],
            'GET /v1/operations/op-1' => [200, ['state' => 'succeeded']],
        ]);
        $result = (new BranchService($api->client()))->create('org', 'proj', 'feature');

        self::assertSame('ready', $result['status']);
        $body = $api->requestsTo('POST', '/v1/environments')[0]['body'];
        self::assertSame('preview', $body['kind'] ?? null);
        self::assertSame('production', $body['base_environment_id'] ?? null);
        self::assertSame('schema_only', $body['data_mode'] ?? null);
    }

    public function testCreateValidatesInputBeforeAnyRequest(): void
    {
        $api = new MockApi([]);
        $branches = new BranchService($api->client());

        self::assertArrayHasKey('error', $branches->create('org', 'proj', 'production'));
        self::assertArrayHasKey('error', $branches->create('org', 'proj', 'Bad Id'));
        self::assertArrayHasKey('error', $branches->create('org', 'proj', 'ok', ttlHours: 48));
        self::assertArrayHasKey('error', $branches->create('org', 'proj', 'ok', dataMode: 'everything'));
        self::assertSame([], $api->requests);
    }

    public function testMergeSummarisesALiveShapedPlan(): void
    {
        $api = new MockApi(['POST /v1/organizations/org/projects/proj/branches/merge' => [200, [
            'applied' => false, 'dry_run' => true, 'clean' => true, 'conflicts' => [],
            'changes' => [['object' => 'table public.bookmarks', 'action' => 'create', 'statement' => '...'], ['object' => 'policy public.bookmarks.owner', 'action' => 'create', 'statement' => '...']],
            'migrations' => ['to_replay' => [['version' => '1', 'name' => 'add_bookmarks']]],
        ]]]);
        $plan = (new BranchService($api->client()))->merge('org', 'proj', 'feature');

        self::assertSame('ready_to_merge', $plan['status']);
        self::assertSame(['create table public.bookmarks', 'create policy public.bookmarks.owner'], $plan['changes']);
        self::assertTrue($api->requests[0]['body']['dry_run'] ?? null);
        self::assertFalse($api->requests[0]['body']['force'] ?? null);
    }

    public function testListReturnsOpenBranchesNewestFirst(): void
    {
        $api = new MockApi(['GET /v1/organizations/org/projects/proj/branches' => [200, ['branches' => [
            ['environment_id' => 'old', 'created_at' => 100, 'merged_at' => null, 'base' => 'production'],
            ['environment_id' => 'new', 'created_at' => 200, 'merged_at' => 300, 'base' => 'production'],
        ], 'lineage_failures' => []]]]);

        $branches = (new BranchService($api->client()))->list('org', 'proj');

        self::assertSame(['new', 'old'], array_column($branches, 'branch_id'));
        self::assertSame([true, false], array_column($branches, 'merged'));
    }

    public function testTheOneBranchLimitNamesTheBranchToReuse(): void
    {
        $api = new MockApi([
            'POST /v1/environments' => [403, ['code' => 'temporary_branch_limit', 'detail' => 'a temporary project can have 1 branch at a time']],
            'GET /v1/organizations/org/projects/proj/branches' => [200, ['branches' => [['environment_id' => 'pilot-abc', 'created_at' => 1]]]],
        ]);

        $result = (new BranchService($api->client()))->create('org', 'proj', 'feature');

        self::assertStringContainsString('one branch at a time', $result['error']);
        self::assertSame(['pilot-abc'], $result['open_branches']);
    }

    public function testWithoutAnIdTheNewestUnmergedBranchIsUsed(): void
    {
        $api = new MockApi(['GET /v1/organizations/org/projects/proj/branches' => [200, ['branches' => [
            ['environment_id' => 'merged-one', 'created_at' => 300, 'merged_at' => 301],
            ['environment_id' => 'open-one', 'created_at' => 200, 'merged_at' => null],
        ]]]]);
        $branches = new BranchService($api->client());

        self::assertSame('open-one', $branches->resolveBranch('org', 'proj', null));
        self::assertSame('explicit', $branches->resolveBranch('org', 'proj', ' explicit '));
        self::assertIsArray($branches->resolveBranch('org', 'proj', '../escape'));
    }

    public function testDiscardRefusesProduction(): void
    {
        $api = new MockApi([]);
        self::assertArrayHasKey('error', (new BranchService($api->client()))->discard('org', 'proj', 'production'));
        self::assertSame([], $api->requests);
    }
}

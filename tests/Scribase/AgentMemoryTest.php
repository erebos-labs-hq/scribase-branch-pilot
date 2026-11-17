<?php

declare(strict_types=1);

namespace App\Tests\Scribase;

use App\Scribase\AgentMemory;
use PHPUnit\Framework\TestCase;

final class AgentMemoryTest extends TestCase
{
    private const EXECUTE = 'POST /v1/organizations/org/projects/proj/environments/production/database/execute';

    public function testStoreSendsHexEncodedValuesOnly(): void
    {
        $api = new MockApi([self::EXECUTE => [200, ['status' => 'committed', 'statements' => [['rows' => [['id' => '11111111-1111-1111-1111-111111111111', 'created_at' => 'now']]]]]]]);
        $result = (new AgentMemory($api->client()))->handle('store', 'org', 'proj', 'production', 'default', "it's a \\ test'); drop table x; --");

        self::assertSame('stored', $result['status']);
        $sql = $api->requests[0]['body']['query'] ?? '';
        self::assertStringNotContainsString('drop table', $sql);
        self::assertStringContainsString("convert_from(decode('", $sql);
    }

    public function testStoreCreatesTheTableOnlyWhenItIsMissing(): void
    {
        $inserts = 0;
        $api = new MockApi([self::EXECUTE => static function (?array $body) use (&$inserts): array {
            if (str_starts_with((string) ($body['query'] ?? ''), 'create table')) {
                return [200, ['status' => 'committed', 'statements' => []]];
            }

            return 1 === ++$inserts
                ? [400, ['code' => 'statement_failed', 'detail' => 'relation "public.agent_memories" does not exist (SQLSTATE 42P01)']]
                : [200, ['status' => 'committed', 'statements' => [['rows' => [['id' => '11111111-1111-1111-1111-111111111111']]]]]];
        }]);

        $result = (new AgentMemory($api->client()))->handle('store', 'org', 'proj', 'production', 'default', 'hello');

        self::assertSame('stored', $result['status']);
        self::assertCount(3, $api->requests);
        self::assertStringContainsString('enable row level security', $api->requests[1]['body']['query'] ?? '');
        self::assertStringNotContainsString('using (true)', $api->requests[1]['body']['query'] ?? '');
    }

    public function testAConfirmationRequiredAnswerIsNotTreatedAsSuccess(): void
    {
        // The execute route answers HTTP 200 with status=confirmation_required and runs nothing.
        $api = new MockApi([self::EXECUTE => [200, ['status' => 'confirmation_required', 'statement_count' => 1, 'destructive' => []]]]);
        $result = (new AgentMemory($api->client()))->handle('forget', 'org', 'proj', 'production', 'default', memoryId: '11111111-1111-1111-1111-111111111111');

        self::assertSame('failed to forget memory', $result['error']);
    }

    public function testForgetCastsTheIdToUuid(): void
    {
        $api = new MockApi([self::EXECUTE => [200, ['status' => 'committed', 'statements' => [['rows' => [['id' => 'x']]]]]]]);
        $result = (new AgentMemory($api->client()))->handle('forget', 'org', 'proj', 'production', 'default', memoryId: '11111111-1111-1111-1111-111111111111');

        self::assertSame('forgotten', $result['status']);
        self::assertMatchesRegularExpression("/where id = \\(convert_from\\(decode\\('[0-9a-f]+', 'hex'\\), 'UTF8'\\)\\)::uuid and scope = /", $api->requests[0]['body']['query'] ?? '');
    }

    public function testValidation(): void
    {
        $api = new MockApi([]);
        $memory = new AgentMemory($api->client());

        self::assertArrayHasKey('error', $memory->handle('wipe', 'org', 'proj', 'production', 'default'));
        self::assertArrayHasKey('error', $memory->handle('store', 'org', 'proj', 'production', 'default', '  '));
        self::assertArrayHasKey('error', $memory->handle('forget', 'org', 'proj', 'production', 'default', memoryId: 'not-a-uuid'));
        self::assertArrayHasKey('error', $memory->handle('recall', 'org', 'proj', 'production', str_repeat('s', 200)));
        self::assertSame([], $api->requests);
    }

    public function testRecallOnAFreshProjectIsEmptyNotAnError(): void
    {
        $api = new MockApi(['POST /v1/organizations/org/projects/proj/environments/production/database/query' => [400, ['code' => 'query_failed', 'detail' => 'relation "public.agent_memories" does not exist (SQLSTATE 42P01)']]]);
        $result = (new AgentMemory($api->client()))->handle('recall', 'org', 'proj', 'production', 'default');

        self::assertSame([], $result['memories']);
    }
}

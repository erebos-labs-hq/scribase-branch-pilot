<?php

declare(strict_types=1);

namespace App\Tests\Pilot;

use App\Pilot\HumanApproval;
use App\Pilot\PromotionGate;
use App\Scribase\BranchService;
use App\Tests\Scribase\MockApi;
use PHPUnit\Framework\TestCase;

final class PromotionGateTest extends TestCase
{
    private MockApi $api;

    protected function setUp(): void
    {
        $this->api = new MockApi([
            'GET /v1/organizations/org/projects/proj/environments/feature' => MockApi::environment('feature', 'preview'),
            'POST /v1/organizations/org/projects/proj/branches/merge' => static fn (?array $body): array => [200, [
                'applied' => false === ($body['dry_run'] ?? true),
                'dry_run' => $body['dry_run'] ?? true,
                'conflicts' => [],
                'changes' => [['object' => 'table public.bookmarks', 'action' => 'create']],
            ]],
        ]);
    }

    private function gate(): PromotionGate
    {
        return new PromotionGate(new BranchService($this->api->client()));
    }

    private static function human(?bool $answer, ?string &$asked = null): HumanApproval
    {
        return new class($answer, $asked) implements HumanApproval {
            public function __construct(private readonly ?bool $answer, public ?string &$asked)
            {
            }

            public function confirm(string $question): ?bool
            {
                $this->asked = $question;

                return $this->answer;
            }
        };
    }

    /**
     * @return list<bool>
     */
    private function mergeModes(): array
    {
        return array_map(static fn (array $r): bool => (bool) ($r['body']['dry_run'] ?? null), $this->api->requestsTo('POST', '/branches/merge'));
    }

    public function testItMergesOnlyAfterTheHumanApproves(): void
    {
        $asked = null;
        $result = $this->gate()->promote('org', 'proj', 'feature', 'production', self::human(true, $asked));

        self::assertSame('merged', $result['status']);
        self::assertSame('human', $result['approved_by']);
        self::assertSame([true, false], $this->mergeModes(), 'a dry run first, then the real merge');
        self::assertStringContainsString('create table public.bookmarks', (string) $asked);
    }

    public function testADeclineNeverMerges(): void
    {
        $result = $this->gate()->promote('org', 'proj', 'feature', 'production', self::human(false));

        self::assertSame('declined_by_human', $result['status']);
        self::assertSame([true], $this->mergeModes());
    }

    public function testNoReachableHumanMeansNoMerge(): void
    {
        $result = $this->gate()->promote('org', 'proj', 'feature', 'production', self::human(null));

        self::assertSame('awaiting_human', $result['status']);
        self::assertStringContainsString('pilot:promote feature', $result['message']);
        self::assertSame([true], $this->mergeModes());
    }

    public function testProductionCannotBePromoted(): void
    {
        $result = $this->gate()->promote('org', 'proj', 'production', 'production', self::human(true));

        self::assertArrayHasKey('error', $result);
        self::assertSame([], $this->mergeModes());
    }
}

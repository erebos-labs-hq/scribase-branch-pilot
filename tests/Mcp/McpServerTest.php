<?php

declare(strict_types=1);

namespace App\Tests\Mcp;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Talks JSON-RPC to the real MCP HTTP endpoint of the kernel.
 */
final class McpServerTest extends WebTestCase
{
    private KernelBrowser $browser;
    private ?string $session = null;

    protected function setUp(): void
    {
        $this->browser = self::createClient();
        $init = $this->rpc('initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => ['elicitation' => new \stdClass()], 'clientInfo' => ['name' => 'test', 'version' => '1']]);
        self::assertSame('scribase-branch-pilot', $init['result']['serverInfo']['name'] ?? null);
        $this->session = $this->browser->getResponse()->headers->get('Mcp-Session-Id');
        $this->rpc('notifications/initialized', null, notification: true);
    }

    /**
     * @param array<string, mixed>|null $params
     *
     * @return array<string, mixed>
     */
    private function rpc(string $method, ?array $params = null, bool $notification = false): array
    {
        static $id = 0;
        $payload = ['jsonrpc' => '2.0', 'method' => $method];
        if (!$notification) {
            $payload['id'] = ++$id;
        }
        if (null !== $params) {
            $payload['params'] = $params;
        }
        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json, text/event-stream', 'HTTP_MCP_PROTOCOL_VERSION' => '2025-06-18'];
        if (null !== $this->session) {
            $headers['HTTP_MCP_SESSION_ID'] = $this->session;
        }
        $this->browser->request('POST', '/mcp', server: $headers, content: (string) json_encode($payload));
        $content = (string) $this->browser->getResponse()->getContent();
        if ($notification || '' === trim($content)) {
            return [];
        }
        // Responses may be framed as a server-sent event.
        if (preg_match('/^data: (.+)$/m', $content, $m)) {
            $content = $m[1];
        }

        return (array) json_decode($content, true);
    }

    public function testItExposesTheBranchWorkflow(): void
    {
        $tools = array_column($this->rpc('tools/list')['result']['tools'] ?? [], null, 'name');
        self::assertEqualsCanonicalizing([
            'scribase_branch_create', 'scribase_apply_on_branch', 'scribase_promote_branch', 'scribase_branch_discard',
            'scribase_lint_migration', 'scribase_query', 'scribase_memory',
        ], array_keys($tools));
        self::assertTrue($tools['scribase_query']['annotations']['readOnlyHint'] ?? null);
        self::assertTrue($tools['scribase_promote_branch']['annotations']['destructiveHint'] ?? null);
        self::assertSame(['branchId', 'up'], $tools['scribase_apply_on_branch']['inputSchema']['required'] ?? null);
        self::assertArrayNotHasKey('context', $tools['scribase_promote_branch']['inputSchema']['properties'] ?? [], 'RequestContext is injected, not a model argument');

        $templates = array_column($this->rpc('resources/templates/list')['result']['resourceTemplates'] ?? [], 'uriTemplate');
        self::assertContains('scribase://environments/{environment}/schema', $templates);
        self::assertContains('scribase://branches/{branch}/diff', $templates);

        $prompts = array_column($this->rpc('prompts/list')['result']['prompts'] ?? [], 'name');
        self::assertSame(['review-migration'], $prompts);
    }

    public function testTheWorkflowGuideIsReadable(): void
    {
        $result = $this->rpc('resources/read', ['uri' => 'scribase://guide/branch-workflow']);
        self::assertStringContainsString('scribase_promote_branch', $result['result']['contents'][0]['text'] ?? '');
    }

    public function testTheReviewPromptEmbedsTheMigration(): void
    {
        $result = $this->rpc('prompts/get', ['name' => 'review-migration', 'arguments' => ['sql' => 'create table public.x (id int);', 'goal' => 'demo']]);
        $text = $result['result']['messages'][0]['content']['text'] ?? '';
        self::assertStringContainsString('create table public.x', $text);
        self::assertStringContainsString('Goal: demo', $text);
    }

    public function testApplyingToProductionIsRefusedOverMcp(): void
    {
        $result = $this->rpc('tools/call', ['name' => 'scribase_apply_on_branch', 'arguments' => ['branchId' => 'production', 'up' => 'drop table users;']]);
        $text = $result['result']['content'][0]['text'] ?? '';
        self::assertStringContainsString('refused', $text);
        self::assertTrue($result['result']['isError'] ?? null, 'a refusal is an MCP tool error, not a success');
    }
}

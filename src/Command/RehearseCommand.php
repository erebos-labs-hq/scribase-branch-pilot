<?php

declare(strict_types=1);

namespace App\Command;

use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Content\TextResourceContents;
use Mcp\Schema\Result\CallToolResult;
use Symfony\AI\McpBundle\Client\McpClientInterface;
use Symfony\AI\McpBundle\Client\ServerConnectionInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Target;

/**
 * The same workflow the agent follows, scripted, so the whole MCP round trip
 * (tools, resources, elicitation) can be checked without any LLM key.
 */
#[AsCommand('pilot:rehearse', 'Without an LLM: rehearse a migration file on a throwaway branch through the MCP server, then ask you before promoting.')]
final class RehearseCommand
{
    public function __construct(
        #[Target('pilot')]
        private readonly McpClientInterface $client,
    ) {
    }

    public function __invoke(SymfonyStyle $io, #[Argument('Path to a .sql migration')] string $file, #[Option('Keep the branch instead of discarding it at the end')] bool $keep = false): int
    {
        $sql = is_file($file) ? trim((string) file_get_contents($file)) : '';
        if ('' === $sql) {
            $io->error("Cannot read SQL from {$file}.");

            return 1;
        }

        $mcp = $this->client->get('scribase');
        $io->title('Scribase Branch Pilot (scripted, over MCP stdio)');
        $io->writeln(\sprintf('Connected to <info>%s</info>: %d tools, %d resource templates, %d prompts.', $mcp->getServerInfo()?->name, \count($mcp->getTools()), \count($mcp->getResourceTemplates()), \count($mcp->getPrompts())));

        $io->section('1. Lint against production');
        $lint = $this->call($io, $mcp, 'scribase_lint_migration', ['up' => $sql]);
        if ('blocked' === ($lint['status'] ?? null)) {
            $io->error('The linter blocks this migration. Fix the findings above (or let the agent do it: pilot:review).');

            return 1;
        }

        $io->section('2. Create a throwaway branch');
        $branch = $this->call($io, $mcp, 'scribase_branch_create', ['dataMode' => 'schema_only', 'ttlHours' => 1]);
        $branchId = \is_string($branch['branch_id'] ?? null) ? $branch['branch_id'] : null;
        if (null === $branchId || 'ready' !== ($branch['status'] ?? null)) {
            return 1;
        }

        $io->section('3. Apply on the branch only');
        $applied = $this->call($io, $mcp, 'scribase_apply_on_branch', ['branchId' => $branchId, 'up' => $sql, 'name' => pathinfo($file, \PATHINFO_FILENAME)]);

        $io->section('4. Branch schema (MCP resource)');
        $io->writeln($this->read($mcp, "scribase://environments/{$branchId}/schema"));

        $io->section('5. Promote (the server asks you through MCP elicitation)');
        $promoted = 'applied' === ($applied['status'] ?? null)
            ? $this->call($io, $mcp, 'scribase_promote_branch', ['branchId' => $branchId])
            : ['status' => 'skipped'];

        if (!$keep) {
            $io->section('6. Discard the branch');
            $this->call($io, $mcp, 'scribase_branch_discard', ['branchId' => $branchId]);
        }

        $io->section('7. Remember the outcome');
        $this->call($io, $mcp, 'scribase_memory', ['action' => 'store', 'scope' => 'migrations', 'content' => \sprintf('%s: lint %s, branch %s, promotion %s', basename($file), $lint['status'] ?? '?', $applied['status'] ?? '?', $promoted['status'] ?? '?')]);

        $io->success(\sprintf('Done. Promotion status: %s', \is_string($promoted['status'] ?? null) ? $promoted['status'] : '?'));

        return 0;
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function call(SymfonyStyle $io, ServerConnectionInterface $mcp, string $tool, array $arguments): array
    {
        $result = $mcp->callTool($tool, $arguments);
        $data = self::decode($result);
        $io->writeln(\sprintf('<comment>%s</comment> -> %s', $tool, json_encode(self::summarize($data), \JSON_UNESCAPED_SLASHES)));

        return $data;
    }

    private function read(ServerConnectionInterface $mcp, string $uri): string
    {
        $text = '';
        foreach ($mcp->readResource($uri)->contents as $content) {
            if ($content instanceof TextResourceContents) {
                $text .= $content->text;
            }
        }

        return $text;
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(CallToolResult $result): array
    {
        if (\is_array($result->structuredContent ?? null)) {
            return $result->structuredContent;
        }
        foreach ($result->content as $content) {
            if ($content instanceof TextContent) {
                $decoded = json_decode($content->text, true);

                return \is_array($decoded) ? $decoded : ['text' => $content->text];
            }
        }

        return [];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private static function summarize(array $data): array
    {
        return array_intersect_key($data, array_flip(['status', 'error', 'branch_id', 'safe_to_apply', 'blocking_findings', 'applied_versions', 'changes', 'message', 'memory_id', 'detail']));
    }
}

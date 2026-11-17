<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Scribase\AgentMemory;
use App\Scribase\BranchService;
use App\Scribase\ScribaseTarget;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\ToolAnnotations;

final class MemoryTool
{
    public function __construct(
        private readonly AgentMemory $memory,
        private readonly ScribaseTarget $target,
    ) {
    }

    /**
     * Persistent agent memory stored as rows in the project's own Postgres, so it survives across sessions and clients.
     *
     * @param string                    $action        store, recall, search or forget
     * @param string                    $scope         namespace, e.g. one per agent or per task
     * @param string|null               $content       "store": text to remember
     * @param array<string, mixed>|null $metadata      "store": optional JSON object
     * @param string|null               $memoryId      "forget": id returned by store
     * @param string|null               $query         "search": case-insensitive substring
     * @param int|null                  $limit         "recall"/"search": max rows (1-200)
     * @param string                    $environmentId environment holding the memory table
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: 'scribase_memory', annotations: new ToolAnnotations(title: 'Agent memory in Postgres', readOnlyHint: false, destructiveHint: false, openWorldHint: true))]
    public function __invoke(string $action, string $scope = 'default', ?string $content = null, ?array $metadata = null, ?string $memoryId = null, ?string $query = null, ?int $limit = null, string $environmentId = BranchService::PRODUCTION): array
    {
        $target = $this->target->resolve();
        if (isset($target['error'])) {
            return ToolResult::of($target);
        }
        if (!ScribaseTarget::validId(BranchService::environment($environmentId))) {
            return ToolResult::of(['error' => 'environmentId must be lowercase letters, digits and dashes.']);
        }

        return ToolResult::of($this->memory->handle($action, $target[0], $target[1], BranchService::environment($environmentId), $scope, $content, $metadata, $memoryId, $query, $limit));
    }
}

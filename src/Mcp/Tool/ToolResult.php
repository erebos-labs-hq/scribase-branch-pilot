<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use Mcp\Exception\ToolCallException;

/**
 * Turns an `['error' => ...]` result into an MCP tool error (`isError: true`),
 * so clients and models can tell a refusal from a success.
 */
final class ToolResult
{
    /**
     * @param array<string, mixed> $result
     *
     * @return array<string, mixed>
     */
    public static function of(array $result): array
    {
        if (isset($result['error'])) {
            throw new ToolCallException((string) json_encode($result, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));
        }

        return $result;
    }
}

#!/usr/bin/env bash
# Talk to the stdio MCP server like any MCP client would, and print what it exposes.
cd "$(dirname "$0")/.."
printf '%s\n' \
  '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"demo","version":"1"}}}' \
  '{"jsonrpc":"2.0","method":"notifications/initialized"}' \
  '{"jsonrpc":"2.0","id":2,"method":"tools/list"}' \
  '{"jsonrpc":"2.0","id":3,"method":"resources/list"}' \
  '{"jsonrpc":"2.0","id":4,"method":"resources/templates/list"}' \
  '{"jsonrpc":"2.0","id":5,"method":"prompts/list"}' \
| php bin/console mcp:server scribase --no-debug 2>/dev/null \
| jq -r '
  if .id == 1 then "server   \(.result.serverInfo.name) \(.result.serverInfo.version)"
  elif .id == 2 then (.result.tools[] | "tool     \(.name)\(if .annotations.destructiveHint then "  (destructive)" else "" end)")
  elif .id == 3 then (.result.resources[] | "resource \(.uri)")
  elif .id == 4 then (.result.resourceTemplates[] | "resource \(.uriTemplate)")
  elif .id == 5 then (.result.prompts[] | "prompt   \(.name)")
  else empty end'

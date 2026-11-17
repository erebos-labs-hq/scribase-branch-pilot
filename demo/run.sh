#!/usr/bin/env bash
# Scripted terminal demo. Every command below really runs against a live Scribase temporary project.
# Usage: demo/run.sh <tools|unsafe|safe|all> [y|n]   (y/n: the answer given at the human promotion gate)
cd "$(dirname "$0")/.."
SCENE="${1:-all}"
ANSWER="${2:-n}"
export TERM=xterm-256color
type_cmd() {
  printf '\033[1;32m$\033[0m '
  local s="$1"; for ((i=0; i<${#s}; i++)); do printf '%s' "${s:i:1}"; sleep 0.025; done
  printf '\n'; sleep 0.4
}
pause() { sleep "${1:-1.5}"; }

if [[ $SCENE == tools || $SCENE == all ]]; then
clear
type_cmd "demo/mcp-list.sh    # what the MCP server exposes"
demo/mcp-list.sh
pause 3
fi

if [[ $SCENE == unsafe || $SCENE == all ]]; then
clear
type_cmd "cat examples/migrations/add_bookmarks_unsafe.sql"
cat examples/migrations/add_bookmarks_unsafe.sql
pause 2
type_cmd "bin/console pilot:rehearse examples/migrations/add_bookmarks_unsafe.sql"
php bin/console pilot:rehearse examples/migrations/add_bookmarks_unsafe.sql </dev/null
pause 3
fi

if [[ $SCENE == safe || $SCENE == all ]]; then
clear
type_cmd "bin/console pilot:rehearse examples/migrations/add_bookmarks.sql"
(sleep 4; echo "$ANSWER") | php bin/console pilot:rehearse examples/migrations/add_bookmarks.sql
pause 4
fi

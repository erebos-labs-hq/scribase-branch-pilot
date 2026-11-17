# Scribase Branch Pilot

**Branch-per-task Postgres for AI agents.** A Symfony AI agent and an MCP server that rehearse every
schema change on a throwaway copy-on-write Postgres branch, show you exactly what a merge would do,
and only touch production after a human says yes, using MCP elicitation.

Built for the [Symfony AI & MCP Hackathon](https://symfony.com/hackathon) with Symfony 7.4,
`symfony/ai-bundle`, `symfony/mcp-bundle` and the official PHP MCP SDK (`mcp/sdk`), on top of
[Scribase](https://scribase.com) (hosted Postgres with branching, a migration linter and free,
no-login temporary projects).

```
 you ──► pilot:review add_bookmarks.sql
            │
            ▼
   Symfony AI Agent (Hugging Face or Ollama)
            │  MCP client (stdio, mcp-bundle "clients")
            ▼
   MCP server: bin/console mcp:server scribase   (also served over HTTP at /mcp)
     tools      scribase_lint_migration · scribase_branch_create · scribase_apply_on_branch
                scribase_query · scribase_promote_branch · scribase_branch_discard · scribase_memory
     resources  scribase://guide/branch-workflow · scribase://branches
                scribase://environments/{environment}/schema
                scribase://branches/{branch}/diff
     prompt     review-migration
            │                                   ▲
            ▼                                   │ elicitation/create: "Promote branch X? The merge will: ..."
   Scribase API (branches, linter, merges)      └── answered by YOU at the terminal, never by the model
```

## Why

Coding agents are great at writing migrations and terrible at knowing when not to run them. Branch Pilot
gives the agent a sandbox it can break freely and a gate it cannot open on its own:

- **Lint first.** Scribase's migration linter blocks tables without RLS, `USING (true)` policies,
  `GRANT ... TO PUBLIC`, `SECURITY DEFINER` and more. The agent is told to fix the SQL, not ignore it.
- **Branch, never production.** `scribase_apply_on_branch` reads the environment's kind from the API and
  refuses anything that is not a ready `preview` branch, and refuses migrations with blocking findings.
- **Human-only promotion.** `scribase_promote_branch` runs a dry-run merge, then sends an MCP
  `elicitation/create` request with the plan. The request goes to the MCP *client's* UI (your terminal
  here, or any client that supports elicitation). The LLM never sees it and cannot answer it. No human
  reachable (a client without elicitation) means nothing is merged, and the tool explains how a human can
  promote with `bin/console pilot:promote <branch>`.
- **Memory in your own database.** `scribase_memory` stores lessons as rows in your project's Postgres,
  so the next session (or another MCP client) starts informed.

## 5-minute quickstart

Requirements: PHP 8.2+ and Composer. Nothing to sign up for.

```bash
git clone <this repository> scribase-branch-pilot && cd scribase-branch-pilot
composer install

# 1. A free Scribase temporary project (72h, no login, no card). Solves a small proof-of-work and
#    writes SCRIBASE_API_KEY / SCRIBASE_ORG / SCRIBASE_PROJECT to .env.local.
bin/console pilot:bootstrap

# 2. See the whole MCP round trip with no LLM at all: lint, branch, apply, schema resource,
#    then the server asks YOU (through MCP elicitation) whether to promote.
bin/console pilot:rehearse examples/migrations/add_bookmarks.sql

# 3. An unsafe migration is stopped by the linter:
bin/console pilot:rehearse examples/migrations/add_bookmarks_unsafe.sql

# 4. Live end-to-end checklist (18 checks; every promotion is declined, production is never changed):
bin/console pilot:verify-live
```

### Let the agent do it

Pick one model provider and put it in `.env.local`:

```bash
# Hugging Face Inference Providers: a fine-grained token with "Make calls to Inference Providers"
PILOT_PLATFORM=huggingface
HUGGINGFACE_API_KEY=hf_...
HUGGINGFACE_MODEL=Qwen/Qwen3-Coder-30B-A3B-Instruct   # any chat model with tool calling

# or a free local model with Ollama
PILOT_PLATFORM=ollama
OLLAMA_MODEL=qwen2.5:7b    # after: ollama pull qwen2.5:7b (3B models hallucinate branch ids)
```

```bash
bin/console pilot:review examples/migrations/add_bookmarks_unsafe.sql --goal "let users save bookmarks"
```

The agent recalls earlier lessons, lints, rewrites the unsafe SQL with RLS and an owner policy, cuts a
branch, applies, verifies with read-only SQL, asks to promote (you answer at the terminal), cleans up and
stores a lesson. Every MCP tool call it makes is printed as it happens, so you see what the model *did*,
not just what it *says* it did. Whatever the model claims, only a human "yes" can merge.

### Use it from any MCP client

stdio (Claude Desktop, Cursor, MCP Inspector, ...):

```json
{
  "mcpServers": {
    "scribase-branch-pilot": {
      "command": "php",
      "args": ["/absolute/path/to/scribase-branch-pilot/bin/console", "mcp:server", "scribase", "--no-debug"]
    }
  }
}
```

HTTP (Streamable HTTP, localhost only by default):

```bash
php -S 127.0.0.1:8000 -t public    # MCP endpoint: http://127.0.0.1:8000/mcp
npx @modelcontextprotocol/inspector  # then connect to the URL above
```

## How it is built

| Piece | Where | What it shows |
|---|---|---|
| MCP server | `config/packages/mcp.yaml`, `src/Mcp/` | `symfony/mcp-bundle` discovers `#[McpTool]`, `#[McpResource]`, `#[McpResourceTemplate]` and `#[McpPrompt]` services; one server, stdio + HTTP transports, tool annotations (`readOnlyHint`, `destructiveHint`), refusals returned as MCP tool errors (`isError`). |
| Human gate | `src/Pilot/PromotionGate.php`, `ElicitationApproval.php` | A tool injects `RequestContext` and calls `ClientGateway::elicit()` mid-execution; the SDK suspends the tool in a Fiber until the client answers. |
| MCP client | `mcp.clients.pilot` in `mcp.yaml`, `src/Pilot/ConsoleElicitation.php` | The app is also an MCP client of its own server, with an elicitation callback that asks the human at the terminal. |
| Agent | `config/packages/ai.yaml`, `src/Command/ReviewCommand.php` | Two AI Bundle agents (Hugging Face, Ollama) whose tools come from the MCP server via `tools: - mcp_server: pilot.scribase`. The agent uses the exact same tools, through the protocol, that an external client would. |
| Scribase layer | `src/Scribase/` | Typed client, branch lifecycle with guards, hex-encoded SQL literals for memory (no quote or backslash can ever reach SQL), PoW provisioning of free projects. |

Commands: `pilot:bootstrap`, `pilot:rehearse`, `pilot:review`, `pilot:promote` (human-only CLI promotion),
`pilot:branches [--discard]`, `pilot:verify-live`, plus the bundle's `mcp:server`.

The server is bound to the one project in `.env.local`: tools never take organization or project ids, so a
model cannot point it at another project, and ids are validated before they reach an API path. Branch tools
default to the open branch when `branchId` is omitted.

## Quality

```bash
composer test      # PHPUnit: 42 tests, no network (mocked API shapes captured from the live API)
composer phpstan   # PHPStan level 8, no baseline
```

The MCP tests talk JSON-RPC to the real `/mcp` HTTP endpoint of the kernel (tools, resources, prompt,
a refused production write and a rejected path-escaping id over MCP).

## Notes and limits

- A temporary project allows **one branch at a time**; `pilot:rehearse` discards its branch at the end.
  Temporary projects expire after 72 hours unless claimed. Scribase also limits anonymous temporary
  projects per network address per day.
- Promotion merges **schema** (tables, columns, indexes, constraints, policies) with Scribase's three-way
  merge. Data written on a branch is not copied back.
- Secrets (`SCRIBASE_API_KEY`, `HUGGINGFACE_API_KEY`) live in `.env.local`, which is git-ignored. The API
  key is never a tool argument, so a model cannot read or exfiltrate it through a tool call.
- Agent quality depends on the model. Small CPU models (qwen2.5 3B/7B on Ollama) drive the tools but
  often invent branch ids or loop; the tools answer with the real open branch to steer them, and the
  promotion gate means a confused model still cannot merge anything. Use a tool-calling model of 30B+
  (the Hugging Face default) for a clean run. With Ollama, cap `num_predict` (for example 2048) so a
  runaway generation cannot stall the loop.

## License

MIT, Erebos Labs.

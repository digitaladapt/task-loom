# TaskLoom — Spec

**Status:** approved · **Repo:** `task-loom` · **Baseline:** PHP 8.5 · Symfony 8.1
**Structure:** per `guiding-light/docs/STRUCTURE-FOR-NEW-PROJECTS.md`
**Companions:** `DESIGN_CONSIDERATIONS.md` (why) · `ROADMAP.md` (when)

### Locked decisions

1. **Tasks are editable drafts until enabled, then immutable — for everyone.** A `task.update`
   on an enabled task never mutates it; it creates a replacement draft (§4.4). This applies
   to **user** updates too, not just agent updates: we keep the record of every version.
2. **`php-mcp/client` and `php-mcp/server` are adopted.** No spike; SDK use is the design,
   with the hand-rolled path available only as a documented contingency if it fails us.
3. **Project name: TaskLoom**, repo `task-loom`.
4. **Context trimming never prunes the initial user section (the task prompt).** Only
   excessively old **assistant** messages from the LLM itself are pruned (§5.6).

---

## 1. What TaskLoom is

A local-first agentic harness: a Symfony application that runs LLM tasks against a
deliberately minimal set of MCP tools, where **the model only ever sees the tools its
current task needs**, every failure is captured as structured data, and the system can
propose changes to its own tasks — which never take effect until a human approves them.

TaskLoom is the successor to task-loop (Python) and task-weaver (PHP/Symfony). See
`DESIGN_CONSIDERATIONS.md` for the lineage, the task-weaver post-mortem, and the
alternatives that were rejected on the way here.

---

## 2. Requirements

1. **Security — least context by construction.** A task's model sees only the tools that
   task needs, fixed at run start. This is simultaneously the prompt-injection mitigation
   (injected instructions can only invoke tools already in a tiny toolbox, and those tools
   are individually narrow) and the performance win (fewer schema tokens; small models pick
   reliably between 5 tools, not 25).
2. **Robustness — failure is loud, categorized data; completion is justified.** Malformed
   tool calls self-correct via feedback retries; persistent failures surface as queryable,
   classifiable events; "done" requires a declared result, not a bare assertion.
3. **Local-first concurrency.** Configurable number of concurrent LLM requests
   (`LLM_MAX_CONCURRENCY`), with natural interleave while tool calls are in flight.
4. **Autonomy with human oversight.** Agents may create and edit tasks via MCP tools, but
   every agent-authored change lands disabled; a human enables it. Replacements, not
   mutations, for enabled tasks.
5. **Continuous improvement cycle.** A reviewer task reads run logs and proposes task
   edits — arriving as disabled drafts in the approval queue.

### Non-goals (v1)

- No worker tier / container sandboxing / claim protocol.
- No `session`-kind long-running tasks (designed for, deferred — §9).
- No scheduler tick (manual "Run now" only; tick lands in v1.1).
- No stdio or legacy SSE MCP transports.
- No LLM-driven context compaction; static trimming with fail-closed budget only.

---

## 3. Architecture — one app, in-process loop

```
┌────────────────────────────────────────────────────────────┐
│                      TaskLoom (Symfony)                    │
│                                                            │
│  Admin UI (Twig)      Run Engine              Task Tools   │
│  ─────────────        ───────────             ──────────   │
│  task CRUD            compile prompt           MCP server  │
│  approval queue       → LLM (OpenAI-compat)   (task.create,│
│  run history/logs     → validate tool calls    task.update,│
│  run-now / inspect    → execute via MCP client task.list,  │
│                       → append results         task.get,   │
│  Scheduler (v1.1)     → checkpoint             run.read_log)│
│                       → retry / fail / done                │
│                                                            │
│  LLM slot semaphore · attempt ledger · task store          │
│  Doctrine + SQLite · structured JSON logs + request IDs    │
└──────────┬─────────────────────────┬────────────────────────┘
           │ MCP client              │ MCP server
           ▼                         ▼
   ┌──────────────┐          ┌──────────────┐
   │ MCP servers  │          │ agents       │
   │ (tools)      │          │ (incl. the   │
   │ streamable   │          │ reviewer     │
   │ HTTP /       │          │ task +       │
   │ OpenAPI      │          │ external     │
   └──────────────┘          │ agents)      │
                             └──────────────┘
```

**The security boundary** is tool scoping plus the MCP servers themselves — not a
sandboxed container:

- **Tool scoping** — the model cannot call a tool that isn't in its toolbox; and
- **The MCP servers themselves** — tools are individually narrow (a weather tool reads
  weather; it cannot touch the filesystem), and credentials live only in the harness
  environment, injected at dispatch time. The model never sees server URLs or secrets.

One process means one log stream, one transaction boundary, one place to look when
something goes wrong.

**Dual MCP role.** The harness is an MCP **client** (executes task tools on registered
servers) and an MCP **server** (exposes task-management tools for agents — the reviewer
task, and optionally external agents such as Claude Code). The two roles share nothing
except the process.

---

## 4. Security model

### 4.1 Toolbox fixed at run start

- A task declares its toolbox: **explicit tool list**, or **tags** resolved against the
  discovered catalog (`task.tags ∩ tool.tags`).
- Resolution happens once, at run start. **No mid-run tool expansion** — deliberate.
  There is no `request_tool` escape hatch in v1.
- The prompt contains: grounding block + task brief + toolbox schemas + trimmed window.
  Nothing else. Nothing a tool returns is ever treated as instructions.

### 4.2 Untrusted content rules

- Tool results are **data, not instructions**. They are capped
  (`max_tool_output_percentage` of the context limit), truncated with an explicit
  `…[truncated]` marker, stored raw in the DB, and only a pruned window returns to the model.
- The grounding block is harness-authored and identical in shape every run (date, time,
  timezone, units, optional location). Global config, never per-task, never tool-influenced.
- MCP server credentials are resolved from the harness environment at call time and are
  scrubbed from all logged traces and error messages.

### 4.3 Gated autonomy — the human is the enable switch

- **Hardcoded:** any task create/update performed by an agent (via the task MCP tools or
  the API with an agent identity) persists with `enabled = false`. There is no
  configuration, flag, or prompt instruction that can change this — it is in the
  persistence layer, not the prompt.
- Approval is a manual, UI-driven action (`POST /tasks/{id}/approve`), restricted to
  user-authenticated sessions. Agent identities cannot reach it.

### 4.4 Replacement, not mutation — once enabled, a task is an immutable record

- **Draft tasks are editable.** While `enabled = false`, a task can be freely edited by
  its author (user or agent) — iterating on a draft is cheap and leaves no trace worth
  keeping.
- **Enabled tasks are immutable. For everyone.** Once enabled, a task is never mutated —
  not by agents, and **not by users either**. Any update to an enabled task A creates
  draft task B with `replacement_for_id = A`. A keeps running, untouched.
  Every enabled version is a preserved record: what ran, when, and what it looked like —
  for run-log forensics and the improvement cycle alike. (The only mutations an enabled
  task ever receives are lifecycle flags — disabling/archiving/superseding — never its
  content: title, brief, toolbox, schedule, kind.)
- **Approving B** (single transaction): B is enabled, A is disabled and archived
  (`archived_at`, `superseded_by = B`). Never a moment where both or neither run.
- **Rejecting B**: B is archived; A is unaffected. The chain is auditable:
  A → B → C … via `replacement_for_id` / `superseded_by`.

```
Task: id, title, brief, kind (run|session), toolbox (tags|explicit),
      schedule (nullable), enabled, created_by (user|agent),
      replacement_for_id (nullable FK), superseded_by_id (nullable FK),
      archived_at, created_at, updated_at
```

---

## 5. Robustness model

Ordered by what the task-weaver post-mortem demands: **see the failure first, then
recover from it.**

### 5.1 Validate before dispatch

Tool arguments are schema-checked in the harness *before* hitting the MCP server. A
malformed call never leaves the process. The model receives a structured, actionable
error as the tool result:

```json
{"error": "invalid_arguments", "tool": "weather", "detail": "missing required property: location", "attempt": 2}
```

so the loop **self-corrects** — the model sees what was wrong and retries correctly,
instead of the run stalling on a silent server-side 400.

### 5.2 Retry with feedback, then budgets

- **Per-tool-call retries** (default 2) with the error fed back to the model.
- **Repetition circuit-breaker:** the same tool failing with the same error class N times
  (default 3) → the run is paused, the task is flagged `needs_attention`, and it lands in
  the approval/attention queue. No infinite loops burning local GPU.
- **Per-run step budget** and **context budget** — both fail closed with a clear, named
  error, never a silent truncation.

### 5.3 The attempt ledger (the centerpiece)

Every LLM request, tool call, validation error, retry, and completion is a typed event row:

```
RunEvent: id, run_id, task_id, seq, type, created_at
          type ∈ {llm_request, llm_response, tool_call, tool_result,
                   tool_validation_error, tool_retry, circuit_breaker,
                   context_trim, checkpoint, completion, failure}
          payload (JSON), error_class (nullable), attempt_no, duration_ms
```

- **Error taxonomy is a fixed enum**, not free text: `invalid_arguments`,
  `tool_not_found`, `server_error`, `server_timeout`, `llm_error`, `llm_malformed_response`,
  `context_exhausted`, `budget_exceeded`, `unknown`. Run reports aggregate by class, so
  "malformed tool calls" vs "server 500s" vs "context overflow" are distinguishable at a
  glance — the thing task-weaver never gave us.
- Run reports are per-run and per-task rollups, exposed in the admin UI.

### 5.4 Justified completion

The run prompt requires the model to finish with a **structured completion declaration** —
a final tool call or terminal message containing the task's result (e.g. the Morning
Briefing text), not a bare "step complete". A run that hits its step budget **without** a
completion declaration is marked `incomplete`, never `succeeded`. This kills the
"step complete, no explanation, no output" failure mode structurally: success is a
schema-validated artifact, not a vibe.

### 5.5 Checkpoint / resume

Every exchange is persisted before the next LLM request. A crash (or restart) resumes the
run from its last checkpoint rather than restarting from zero. The transcript in the DB is
the full, honest record — only what's sent to the model is trimmed.

### 5.6 Context budgeting (one deliberate change from task-loop)

- **The initial user section — the task prompt — is never pruned.** It is the run's
  constitution: grounding + task brief + toolbox schemas always travel at the head of
  every request, in full. Pruning it would silently change the task mid-run.
- **Only excessively old assistant messages from the LLM itself are pruned:** prior
  thinking/reasoning blocks are never re-sent, and only the last
  `window_tail_exchanges` tool-call exchanges (assistant tool-call turn + its tool
  results) are kept after the prompt head.
- Cap every tool result to `max_tool_output_percentage` of the context limit.
- If pruning still can't fit the budget: fail with
  `context exhausted: estimated tokens N > limit M`. Never summarize-to-continue.

---

## 6. Concurrency

- **`LLM_MAX_CONCURRENCY=X`** — a semaphore on LLM calls, not on tasks.
- A task holds a slot **only while on the wire to the model**; it releases during tool
  execution (I/O wait), so another queued task's LLM step can interleave.
- Ready queue is FIFO and persisted; a crash/restart keeps waiting tasks waiting.
- `X=1` (typical local setup) → tasks effectively serialize; fairness is plain FIFO.
- Tools never call the LLM in v1 → no nested-acquire deadlock, by construction.

---

## 7. Data model (v1)

```
Task          (as in §4.4)
Run           id, task_id, status (queued|running|paused|succeeded|incomplete|failed|needs_attention),
              toolbox_snapshot (JSON — the resolved tools, frozen at run start),
              started_at, finished_at, checkpoint (JSON), step_count, error_class
RunEvent      (§5.3)
ToolCall      id, run_event_id, tool, arguments (JSON), result (JSON),
              error_class, attempt_no, duration_ms, server
McpServer     id, name, url, protocol (mcp|openapi), enabled, cred_var (env var name — never the value)
Tool          id, server_id, name, description, tags, schema (JSON), side_effect
```

- `toolbox_snapshot` on the run is the audit trail of *exactly what the model could see* —
  useful both for security review and for reproducing failures.
- The tool catalog is **discovered** from servers at sync time: explicit/pinned definitions
  win over discovered ones; drift is logged; a down server never wipes known tools.

---

## 8. Admin UI (Twig, phone-first)

- Task list with run status; create/edit with tag picker + explicit tool list
- **Approval queue** — agent-proposed tasks and replacements, with diff view against
  `replacement_for_id`, approve/reject
- Run history: per-run timeline of RunEvents (filterable by error class), the full
  transcript, and the completion artifact
- Scheduler view (who holds the LLM slot, who's queued) + **Run now** (the only trigger in v1)
- Attention queue: `needs_attention` / `incomplete` runs, grouped by error class

---

## 9. Task archetypes

Two kinds, same engine:

| | `run` | `session` |
|---|---|---|
| Examples | Morning Briefing; log-review reviewer task | "Build an email MCP server" |
| Shape | completes in one loop pass; few–dozen exchanges | long-lived, recurring work sessions |
| Context | trimmed window | persistent **workspace** (files/scratchpad outside context) + compaction summary carried between sessions |
| Completion | justified completion declaration, then done | model declares milestone/done; per-session budgets |

**v1 ships `run` only.** `session` needs its own design pass (workspace layout, compaction
contract, resumption semantics) and is the v1.x headline. Note the reviewer task is a `run`
task — so the improvement cycle is provable in v1 even without sessions.

---

## 10. The improvement cycle

A seeded **reviewer task** — an ordinary `run` task whose toolbox is the harness's own task
tools:

- `task.list`, `task.get`, `run.read_log` (read-only)
- `task.create`, `task.update` (write; always gated — §4.3)

On a schedule (or manually in v1), it reads recent run logs and failure-class rollups and
proposes edits: tighten a brief, adjust a toolbox that repeatedly validated wrong
arguments, split a task. Proposals land as **disabled drafts** in the approval queue, with
`replacement_for_id` chains for enabled tasks. The human gate is the cycle's control point —
the system can *propose* anything, but only a human makes it live.

---

## 11. Stack, dependencies, conformance

| Layer | Choice |
|---|---|
| Language / framework | PHP 8.5 (`^8.5`, pinned three ways) · Symfony 8.1.* · Flex |
| Persistence | Doctrine ORM + DBAL, SQLite (single file, WAL, `bin/backup-db.sh` pattern from task-weaver) |
| UI | Twig (phone-first, per guiding-light web-app archetype; Turbo/Stimulus as needed) |
| Async/queue | Symfony Messenger — run-now dispatches a message from day one; the scheduler tick (v1.1) is the same mechanism |
| LLM | OpenAI-compatible endpoint (Ollama / vLLM / llama.cpp), `symfony/http-client` |
| Logging | Monolog, structured JSON + request IDs (guiding-light phase 1) |
| MCP | `php-mcp/client` + `php-mcp/server` (see below) |

**Non-Symfony dependencies — approved (user sign-off):**

1. **`php-mcp/client` and `php-mcp/server`** (the PHP MCP SDK) — used for both the
   client role (tool execution over Streamable HTTP) and the server role (task tools).
   Adopted by decision, no spike required. If the SDK fails us in practice, the
   hand-rolled streamable-HTTP fallback (initialize / tools/list / tools/call — small)
   is the documented contingency, not the plan.
2. **`dragonmantank/cron-expression`** — proven in task-weaver; needed for v1.1 scheduling.

Transports supported: **MCP Streamable HTTP** and **OpenAPI (HTTP/JSON)**. No stdio, no SSE.

**Conformance:** full STRUCTURE-FOR-NEW-PROJECTS.md compliance from day one — vendored
`phpstan.neon.dist` / `.php-cs-fixer.dist.php` / `.editorconfig` / `phpunit.dist.xml`,
PHPStan baseline at birth, `composer audit` in CI, `/health` + `/ready` split, MIT license
(holder `digitaladapt`), docs in `docs/design/`.

---

## 12. Repository layout

Root holds project identity only; `docker/` = image inputs; `docs/` = human-facing:

```
task-loom/
├── Dockerfile  compose.yaml  .dockerignore  .gitignore  .editorconfig
├── composer.json  composer.lock  symfony.lock  importmap.php
├── README.md  LICENSE  CONTRIBUTING.md  CHANGELOG.md  SECURITY.md
├── bin/           # console, backup-db.sh
├── config/        # services.yaml, packages/, routes/
├── public/        # index.php
├── src/
│   ├── Controller/       # admin UI + run-now + approve
│   ├── Entity/           # Task, Run, RunEvent, ToolCall, McpServer, Tool
│   ├── Repository/
│   ├── Toolbox/          # catalog, discovery/sync, tag resolution, snapshot
│   ├── Mcp/              # client (tool execution), server (task tools)
│   ├── RunEngine/        # loop, prompt compiler, validation, retry,
│   │                     # circuit breaker, completion, checkpoint
│   ├── Llm/              # OpenAI-compatible client, slot semaphore, queue
│   ├── Context/          # grounding, trimming, budget
│   └── Security/         # agent identity, gated writes, scrubbing
├── templates/
├── tests/
├── migrations/
└── docs/
    ├── design/           # SPEC.md, DESIGN_CONSIDERATIONS.md, ROADMAP.md
    └── examples/
```
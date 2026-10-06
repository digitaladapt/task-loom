# TaskLoom — Spec

**Status:** approved · **Repo:** `task-loom` · **Baseline:** PHP 8.5 · Symfony 8.1
**Structure:** per `guiding-light/docs/STRUCTURE-FOR-NEW-PROJECTS.md`
**Companions:** `DESIGN_CONSIDERATIONS.md` (why) · `ROADMAP.md` (when)

### Locked decisions

1. **Tasks are editable drafts until enabled, then immutable — for everyone.** A `task_update`
   on an enabled task never mutates it; it creates a replacement draft (§4.4). This applies
   to **user** updates too, not just agent updates: we keep the record of every version.
2. **`php-mcp/client` and `php-mcp/server` are adopted.** No spike; SDK use is the design,
   with the hand-rolled path available only as a documented contingency if it fails us.
3. **Project name: TaskLoom**, repo `task-loom`.
4. **Context trimming never prunes the initial user section (the task prompt).** Only
   excessively old **assistant** messages from the LLM itself are pruned (§5.6).
5. **The step model is run-per-step.** Multi-step tasks decompose into a DAG of steps; each
   step is its own run of the one engine, and the final consumer is the task itself
   (§13). The final step is never a separate entity.

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
│  approval queue       → LLM (OpenAI-compat)   (task_create,│
│  run history/logs     → validate tool calls    task_update,│
│  run-now / inspect    → execute via MCP client task_list,  │
│                       → append results         task_get,   │
│  Scheduler (v1.1)     → checkpoint             run_review, │
│  app:run:review       → retry / fail / done    run_read_log)│
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
- The prompt contains: preamble + the task (title, and optionally the brief) + toolbox
  schemas + completion instruction + grounding block + trimmed window. Nothing else.
  Nothing a tool returns is ever treated as instructions.
- **The brief travels once.** It is sent in the **user message** (`Complete the following
  task.\n\n<brief>`) and is *not* repeated in the system head by default
  (`TASKLOOM_PROMPT_BRIEF_IN_SYSTEM=1` restores the repetition). The head still names the
  task: `## Task` carries `Title: <title>` and, for a step or the final consumer, the role
  note — the identity the assistant replies from — without duplicating the brief.
- **Section order is fixed and harness-owned:** preamble → `## Task` → `## Inputs`
  (stepped runs) → `## Toolbox` → `## Completion` → `## Grounding`. Grounding is last,
  nearest the model's first reply: most of what a run states back is stamped with the
  date, time, zone and units it reads there. The `## Toolbox` section renders only when
  the toolbox toggle is on; the order holds among whatever renders.
- **Four parts are deployment-configurable** (`TASKLOOM_SYSTEM_PROMPT[_FILE]`,
  `TASKLOOM_COMPLETION_PROMPT[_FILE]`, `TASKLOOM_PROMPT_TOOLBOX_LIST`,
  `TASKLOOM_PROMPT_BRIEF_IN_SYSTEM`). The two toggles default **off**, because each
  guards a *duplicated* rendering, and duplicate content in the prompt was never intended:
  the toolbox prose list repeats information the tool definitions already carry (sent on
  every request regardless), and the system-head brief repeats the user message. Unset, a
  deployment gets the lean head. (`TASKLOOM_PROMPT_TOOLBOX_LIST` was on by default before
  v1.2; flipping it is a deliberate breaking change — see the CHANGELOG.) The default
  preamble says "the tools provided", not "the tools listed below", so it no longer
  dangles with the list off. A replacement preamble owns the untrusted-content sentence;
  the structural defenses (§4.2, frozen toolbox) are unaffected.
- **The completion *rule* is not configurable.** The engine refuses a contentless
  terminal message and fails closed at the step budget (§5.4); the completion text is
  instruction, not mechanism.

### 4.2 Untrusted content rules

- Tool results are **data, not instructions**. They are capped
  (`max_tool_output_percentage` of the context limit), truncated with an explicit
  `…[truncated]` marker, stored raw in the DB, and only a pruned window returns to the model.
- The grounding block is harness-authored and identical in shape every run (date, time,
  timezone, units, optional location). Global config, never per-task, never tool-influenced.
  It reports the deployment's clock: the wall-clock time and zone named in
  `TASKLOOM_TIMEZONE`, the units in `TASKLOOM_UNITS` (`metric` / `imperial`; unset →
  metric), and the place in `TASKLOOM_LOCATION` (free text, one line; unset omits the
  line). Each is validated at construction and fails loudly, naming the variable — a
  deployment whose schedules fire at 08:00 Chicago time must not tell its runs it is
  08:00 UTC, and a value spanning lines is refused because the block's fixed shape is
  one fact per line.
- MCP server credentials are resolved from the harness environment at call time and are
  scrubbed from all logged traces and error messages.

### 4.3 Gated autonomy — the human is the enable switch

- **Hardcoded:** any task create/update performed by an agent (via the task MCP tools or
  the API with an agent identity) persists with `enabled = false`. There is no
  configuration, flag, or prompt instruction that can change this — it is in the
  persistence layer, not the prompt.
- Approval is a manual, UI-driven action (`POST /tasks/{id}/approve`), restricted to
  user-authenticated sessions. Agent identities cannot reach it.
- **Two front doors, two credentials (v1.x).** The admin UI is a *session*: the
  browser signs in with `TASKLOOM_ADMIN_PASSWORD` (`GET/POST /login`), may keep
  the password in localStorage for seamless re-login (`POST /login/api-key` —
  CSRF-required, constant-time comparison), and signs out via `/logout`
  (CSRF-protected). The MCP endpoint at `POST /mcp` is *stateless* and
  authenticates **`Authorization: Bearer <TASKLOOM_MCP_API_KEY>`** — an agent
  credential deliberately separate from the human one, so it can be rotated
  without touching the login. Both credentials fail closed when unset; HTTP
  Basic is gone (migration note in the README). A UI session cannot open the
  MCP endpoint and an MCP key cannot reach the UI.

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
  thinking/reasoning blocks are never re-sent, and only the newest
  `window_tail_exchanges` tool-call exchanges (assistant tool-call turn + its tool
  results) are kept after the prompt head.
- **The tail is fitted to the budget, not counted into it.** A run that reads many
  items — one exchange per tool round-trip, each under the per-result cap — can
  accumulate a tail that exceeds the limit on its own while every exchange in it is
  individually fine. Rather than fail such a run closed for a reason the operator
  cannot tune out, the window sheds its oldest whole exchanges (never a single
  message: a `tool` result without its `tool_calls` is a malformed request) until what
  remains fits, and records a `context_trim` event saying how many were kept and
  dropped. The whole-exchange drop is deterministic static trimming, not the
  LLM-driven compaction of DESIGN_CONSIDERATIONS §2.3.
- Cap every tool result to `max_tool_output_percentage` of the context limit.
- **Cap the Inputs block too, because the head is never pruned** (§13.4): a stepped
  run's dependency outputs are each limited to an equal share of
  `max_input_artifact_percentage`, so a wide task cannot grow its runs' never-pruned
  floor without bound. This is the tool-result cap's mirror on the other side of the
  head.
- The budget counts the **whole request**, including the tool definitions (which travel
  beside the messages on every call) and the assistant tool-call arguments. The token
  estimate is deliberately conservative (3.5 chars ≈ 1 token, not 4): it is a guard
  rail, and under-counting would let a request overflow the model's real window, where
  the failure is a hard provider error rather than this clean one.
- The prompt head is never truncated, so if the head and tool definitions alone cannot
  fit, the run still fails with
  `context exhausted: the prompt head and tool definitions alone are an estimated N tokens > limit M`.
  Never summarize-to-continue.

---

## 6. Concurrency

- **`LLM_MAX_CONCURRENCY=X`** — a semaphore on LLM calls, not on tasks.
- A task holds a slot **only while on the wire to the model**; it releases during tool
  execution (I/O wait), so another queued task's LLM step can interleave.
- Ready queue is FIFO and persisted; a crash/restart keeps waiting tasks waiting.
- `X=1` (typical local setup) → tasks effectively serialize; fairness is plain FIFO.
- Tools never call the LLM in v1 → no nested-acquire deadlock, by construction.
- **Where the semaphore physically lives:** the engine is turn-based — one LLM
  request per queued message — so the semaphore is *worker count*, not a counter
  in the app. The container's entrypoint (`serve`) starts
  `TASKLOOM_LLM_MAX_CONCURRENCY` `messenger:consume llm` workers and
  `TASKLOOM_TOOL_MAX_CONCURRENCY` tool workers, restarting any that exit;
  each worker holds at most one request on the wire. One container therefore
  is the whole deployment (web + MCP endpoint + fleet) — see
  `docs/design/SINGLE_CONTAINER_RUNTIME.md`. The claim protocol (§6.1 of the
  run engine) is what makes duplicate deliveries and dying workers safe
  regardless of how many workers exist.

### 6.1 The claim is a lease, not a lock

The execution claim (`run.lock_version` / `run.claimed_at`) is a lease with a
staleness window (`CLAIM_STALE_SECONDS`). A claim abandoned by a worker that
died is taken over after that window — no coordination needed, and it cannot
be wrong, because waiting is always safe. The cost is that the window is also
the recovery latency.

### 6.2 Boot recovery — the reap, and who may do it

One situation justifies shortcutting that window: the process that owns the
claim is *known* to be gone, because the process group that ran it is being
restarted. `app:run:requeue --startup` clears those claims and then re-derives
and dispatches what those runs are owed.

**The authority is granted, not inferred.** "We are booting, so no worker can
be alive" is a statement about a process group, not about the run table, and
this repo already ships two ways to be wrong about it: the one-shot `migrate`
service, which never touches the run table, and any deployment whose workers
and web interface are separate process groups sharing one database. So the
reap happens only when the entrypoint's `serve` path — the only path that
starts workers — sets `TASKLOOM_FLEET_OWNER=1`. An unrecognized value fails
closed and is reported; a process that is not the owner does nothing and says
so.

**The decision is made on what could still be in flight** — and this is the
second correction to this section, which is worth recording because both wrong
versions were plausible and the field killed each within a day.

**First it inferred abandonment from claim age**, reusing
`CLAIM_STALE_SECONDS`. Safe, and useless: a container `down`'d and `up`'d
inside a minute leaves claims seconds old. The sweep cleared nothing (`0
cleared, 2 left held`), the requeue beside it re-derived the owed turns
correctly and dispatched them, and every one of those messages was dropped on
arrival — `claim()` requires the same hour to take over, so the re-dispatched
work bounced off a lock nobody would release for another 59 minutes. Recovery
that looked like recovery and did nothing.

**Then it tried per-start identity**: record which fleet took each claim
(`run.claim_fleet`) and clear the ones stamped with the previous start's id, on
the theory that a restart has a new identity and so a leftover claim is a dead
predecessor's. This cannot work, and one boot line showed why:

```
Claims: cleared 0 (left by this fleet), 2 held by another fleet, ...
```

Every restart is a new identity, so **every** leftover claim from the previous
run is "another fleet", and the sweep declines all of them by construction. The
bug was even visible in the test written to prove the mechanism, which passed
the same id on both sides — a situation that never occurs in production. An
identity can say "not me"; it can never say "me, from last time".

**The rule that works asks a different question: could anyone still be holding
this?** At the moment the sweep runs, no worker in this container exists — the
entrypoint runs it before spawning anything, which is the only window where the
question has a clean answer. And a claim is held for exactly one message: one
LLM request, or one set of tool calls. Nothing legitimately holds one longer,
and the request cannot outlive its own timeout. So a claim older than
`TASKLOOM_FLEET_GRAB_AFTER` is a claim whose owner is gone — whether it died ten
seconds or ten hours ago, on this host or another.

The default is **0** (any age), which is correct for the deployment in §6 — one
container, so every claim present at boot is the previous run's. The one
topology where that is wrong is **more than one fleet against the same
database** (a workers-only host beside a UI, or two replicas): then a claim
found at boot may belong to a peer working right now, and only age can prove
otherwise. Setting the bound above the longest a turn can run (safely
`TASKLOOM_LLM_TIMEOUT + 60`) restores the proof. It is opt-in because it is the
rarer shape, and the boot line always prints the bound in force:

```
Claims: cleared 2 (any age), 0 left to the lease, 0 of those without an owner label.
```

The label is kept even though it no longer decides anything, because it is what
makes a leftover claim *legible* afterwards — `claim_fleet` on the row, and
the count of cleared claims that had none, answer "who held this, and is the
label covering what I think it covers?".

**The reap's premise is per lane, and the owner flag is coarser than it.**
"At boot nothing in this process group can be in flight" is not a fact about
the process group; it is a fact about the lanes that process group *consumes*. A
claim is held for one LLM request **or** one set of tool calls, so a `serve`
with `TASKLOOM_LLM_MAX_CONCURRENCY=0` — the worker-fleet-with-no-model

shape, for a host whose model lives in another container — is a fleet owner by
the flag (it does start workers) while the llm lane belongs to a peer it cannot
see. At the default bound it would clear that peer's claims, re-dispatch its
runs, and reset `lock_version`, after which the peer's committed turn is
discarded as `Stale` and the work is done twice — live side effects and all.
Tool claims are the same case on the other lane, and worse in consequence.

So the boot sweep runs only when the fleet consumes **every** lane the claim
protocol spans; a `serve` that sets either `TASKLOOM_LLM_MAX_CONCURRENCY` or
`TASKLOOM_TOOL_MAX_CONCURRENCY` to `0` skips it and says so. The rule is stated
on the fleet's shape rather than on the claims, because the reap itself is a
blanket `UPDATE run ... WHERE claimed_at IS NOT NULL` with no notion of which
lane a claim belongs to — so "do I own everything I am about to clear?" is not
a question the sweep can answer per row, and a co-located tools worker beside a
peer-owned llm lane is declined even though it is the only consumer of every
llm claim present. This costs the
documented path nothing: the single container's stop-time failure — a turn that
outlived its shutdown window — is repaired by its *successor*, which runs the
full fleet and does sweep. An operator who wants the sweep on a partial fleet
says so with `TASKLOOM_FLEET_GRAB_AFTER`, the same knob-and-judgement answer the
multi-replica topology gets. (The alternative considered was to insist on the
bound whenever fewer than two lanes are consumed; the entrypoint cannot
validate an opt-in operator did not set, so the safe primitive — decline, and
report it — is what shipped.)

Three further properties: the sweep is **idempotent** (every dispatch is work
committed state already implies, so it can run on every start); the owner flag
and the identity are deliberately **not** part of the compose env contract
(compose services share an environment anchor, so a compose-supplied owner flag
would be handed to the one-shot `migrate` service, and a compose-supplied
identity would be identical across restarts — the one thing a label must never
be); and a dropped delivery that loses a race to a **live** claim logs at
warning, not debug, so the case this bug hid behind cannot hide again.

What this still does not build is the **worker heartbeat** — the honest
mechanism for a claim held by a fleet that is genuinely alive elsewhere, which
is the case the opt-in bound handles by inference. See
`docs/design/GRACEFUL_RESTART.md`.

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
Step          (v1.1 — §13) id, task_id, position, title, brief, toolbox_mode, toolbox (JSON),
              depends_on (list of step ids — the DAG edges)
```

- `toolbox_snapshot` on the run is the audit trail of *exactly what the model could see* —
  useful both for security review and for reproducing failures.
- The tool catalog is **discovered** from servers at sync time: explicit/pinned definitions
  win over discovered ones; drift is logged; a down server never wipes known tools.
- `Run.step_count` counts engine turns within a run; it is not the step model (§13).
  A task with no steps runs as exactly one run, unchanged from v1.

---

## 8. Admin UI (Twig, phone-first)

- Task list with run status; create/edit with tag picker + explicit tool list
- **Approval queue** — agent-proposed tasks and replacements, with diff view against
  `replacement_for_id`, approve/reject
- Run history: per-run timeline of RunEvents (filterable by error class), the full
  transcript, and the completion artifact
- Scheduler view (who holds the LLM slot, who's queued) + **Run now** (the manual
  trigger; scheduled tasks fire through the scheduler tick, §14)
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

A **reviewer task** — an ordinary `run` task whose toolbox is the harness's own task
tools. Nothing in the engine knows about reviewers: the cycle is a deployment choice,
wired by connecting task-loom's own `/mcp` endpoint as a catalog server and giving the
task the tools below. The same tools serve an external agent or a human at a terminal,
so the reviewer may or may not itself be a task.

Read-only:

- `task_list`, `task_get` — the task under review, briefs and step graph included
- `run_review(taskId, runId?, budgetChars?, history?)` — a deterministic digest of the
  latest **settled** run: the tool-call funnel (calls, distinct calls, repeats, retries),
  per-tool repetition with whether the repeated results were byte-identical, the error
  rollup attributed to tools, token spend, and the completion artifact. No LLM inside:
  "the same call four times with the same answer" is a `GROUP BY`, not a judgement — and
  it is precisely the signal an LLM asked to summarise a transcript would smooth away.
  For a stepped task the digest rolls up parent + step children + final consumer, since a
  parent executes no turns of its own (§13.3).
- `run_read_log(runId, include, budgetChars?, entryChars?)` — the ledger raw: `artifact`
  (the default), `thinking`, `tool_args`, `tool_results`, `errors`, `prompt`. Always
  reports what its budget left out (`budget.elided`), because a reviewer that cannot tell
  "this run had no reasoning" from "I was not shown the reasoning" will invent a finding
  to cover the gap.

Write (always gated — §4.3):

- `task_create`, `task_update`

On a schedule (or manually), the reviewer reads recent runs and failure-class rollups and
proposes edits: tighten a brief, adjust a toolbox that repeatedly validated wrong
arguments, split a task. Proposals land as **disabled drafts** in the approval queue, with
`replacement_for_id` chains for enabled tasks. The human gate is the cycle's control point —
the system can *propose* anything, but only a human makes it live.

What the reviewer does with the digest is its instruction's business, not the harness's: an
instruction may have it file findings in its completion artifact, or call `task_update` to
build a replacement draft. Both are just uses of the tools above.

`app:run:review <task-id>` prints the same digest at a terminal (digest by default,
`--read-log` for the raw kinds), so the surface is debuggable and cron-usable without an
MCP round trip.

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
| MCP | `mcp/sdk` (the official PHP MCP SDK), pinned exactly — see below |

**Non-Symfony dependencies — approved (user sign-off):**

1. **`mcp/sdk`** — the official PHP MCP SDK (a collaboration between Symfony and
the PHP Foundation), used for both the client role (tool execution over
Streamable HTTP) and the server role (task tools). Superseded the originally
approved `php-mcp/client` + `php-mcp/server` in the SDK migration
(`docs/design/MCP_SDK_MIGRATION.md`): upstream `php-mcp/*` is unmaintained, and
its client could not speak Streamable HTTP at all — it opened a legacy HTTP+SSE
stream that modern servers answer with 405, which an in-repo transport worked
around for ~380 lines until the official SDK removed the need. Pinned to an
exact version (`0.8.1`) because it is pre-1.0; relax to `^1.0` once 1.0 ships.
2. **`dragonmantank/cron-expression`** — proven in task-weaver; needed for v1.1 scheduling.

Transports supported: **MCP Streamable HTTP** and **OpenAPI (HTTP/JSON)**. No stdio, no SSE.

### The two MCP roles

- **Client** (`ToolExecutor`, `McpServerReader`) — calls tools on external
  servers. HTTP only; stdio is not reachable (SPEC §11), enforced by
  constructing a `HttpTransport` directly rather than by configuration.
- **Server** (`App\Mcp\Server\TaskTools` via `TaskServerFactory`) — exposes the
  task tools at **`POST /mcp`**, served from inside the app like any other
  route. There is no standalone process and no separate port: the SDK's HTTP
transport is a PSR-7 request handler, not a web server.

  The endpoint sits behind its own **stateless firewall** (`^/mcp`) and
  authenticates `Authorization: Bearer <TASKLOOM_MCP_API_KEY>` — the agent
  credential, separate from the UI's session password (§4.3). A UI session
  cookie cannot reach the endpoint, and the key cannot reach the UI. The gate
  itself (§4.3) is behavioural and lives in `TaskCrud`, below the transport.

  MCP sessions are required (the SDK answers non-`initialize` requests without
  one with `400`/`-32600`) and are stored in the `mcp_sessions` cache pool so a
  handshake survives between requests.

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

---

## 13. The step model (v1.1) — a DAG of runs

*Deferred no longer. The rationale and the rejected shapes live in
`DESIGN_CONSIDERATIONS.md` §2.2; this section is the design.*

### 13.1 What a step is

A step is a **brief + a toolbox + dependency edges** — nothing else. No per-step budgets,
retries, circuit-breaker thresholds, or other behavioral knobs: per-run budgets (§5.2) are
already effectively per-step under this model. The Step entity is deliberately dumb; all
behavior lives in the engine. This is the direct counter to the task-weaver lesson that
step *machinery* accretes (considerations doc §2.2).

A **task with zero steps** behaves exactly as v1: `begin()` creates one run and the task's
own brief/toolbox are the run's brief/toolbox. The task brief/toolbox is the **final
consumer** of the step graph — the only thing that runs after all steps are terminal.
There is no separate "final step" entity; one step shape only. Steps are fully optional.

### 13.2 The graph

```
Step: id, task_id, position (display order), title, brief,
      toolbox_mode, toolbox (JSON),
      depends_on (list of step ids)
```

- `depends_on` is the canonical storage — the edges of a DAG. The authoring/wire/display
  format is **nested arrays** (`[[step, step], [step]]` — each level runs in parallel,
  levels run in sequence). Arrays in, edges stored, arrays rendered back out. The two are
  equivalent in expressiveness (both are leveled DAGs); arrays are structural no-loop by
  construction, but `depends_on` matches how "comes after step X" is actually thought
  about and keeps a single step editable without re-leveling the authoring tree.
- **Validation** (no cycles, no self-deps, deps reference steps of the same task) runs at
  task create/update — and again at enable/approve time, where it is the enforcement
  gate. An invalid graph never runs, and never becomes an enabled task.

### 13.3 Execution — run-per-step, no orchestrator

A task run is a **parent Run** plus one child Run per step. The parent is the unit the
admin UI shows as "the run of the task"; child runs carry the actual engine work and
aggregate into the parent's status.

- `begin(task)` creates the parent run, then dispatches child runs for the **root steps**
  (no unmet dependencies). Tasks without steps skip straight to a single ordinary run —
  the same code path, one child that *is* the whole task.

- **Advancement is state-derived, event-driven.** When a step's run reaches a terminal
  state, the same transaction that commits that state: (a) computes steps whose
  dependencies are now all satisfied and dispatches their runs, and (b) when all steps
  are terminal, finalizes the parent. No orchestrator process, no orchestrator run, no
  new liveness to babysit — the same dispatch-from-committed-state pattern the turn
  engine already trusts (the engine's delivery contract, §5.5 + the claim protocol,
  §6).

- **Parallelism is free.** Sibling steps are independent runs: the
  `TASKLOOM_LLM_MAX_CONCURRENCY` semaphore already counts them, execution claims already
  protect them, the FIFO queue already interleaves them. Under run-per-step, the
  dispatcher that launches root steps and the dispatcher that launches "all satisfied
  steps" are the same code. Serializing would be the extra work; the machinery that would
  make parallel dangerous — shared per-run state — does not exist here.

- **Crash safety.** A dispatch lost in ways the transport can't see is repaired by the
  same state-derived sweep pattern as `app:run:requeue` — re-derive owed work from
  committed state, dispatch; claim + state checks make an extra dispatch a no-op.

### 13.4 Output flow

A **step output** is one thing: the step run's justified completion artifact (the
`completion` RunEvent's result, §5.4). Not exchanges, not tool results. One string,
labeled with the step title, frozen at terminal.

- Each run's frozen prompt head (§5.6 — it is never pruned) gets an **Inputs block**:
  the declared outputs of its dependencies, labeled by step title. For the final
  consumer, the inputs are **all** step outputs, labeled — not just the leaves'.
  Predictable beats minimal: for briefing-scale tasks the token cost is trivial, and
  dependency-edge trimming is exactly the kind of context work v1.x defers anyway.
- **Inputs are capped, because the head is never pruned.** A head is usually bounded —
  the brief, the toolbox, the grounding — but the Inputs block grows with the task's
  width, and the final consumer receives *every* step output. Left unbounded it would be
  an unbounded floor on every request the run makes, the same failure the tool-result cap
  (§4.2) prevents on the other side of the head. So each artifact is capped
  (`TASKLOOM_MAX_INPUT_ARTIFACT_PCT` of the context limit, split evenly across the
  inputs — one gets the lot, two get half each, and so on), truncated with an explicit
  `…[truncated — input capped]` marker so a trimmed input is never mistaken for a short
  one. The stored artifact is untouched; only the copy sent to the model is bounded.
- Step outputs are **data, not instructions** (§4.2): same untrusted-content rules as
  tool results. A weather step's output cannot reconfigure the final consumer's
  toolbox — that is frozen at run start regardless.

### 13.5 Failure policy — strict, fail closed

Any step fails (terminal `incomplete`/`failed`/`needs_attention`) → the parent run is
marked with the failing step's terminal state and error class, and the final consumer
never runs. Downstream steps of a failing step are likewise never dispatched.

This matches the engine's fail-closed philosophy (§5.2, §5.6): a briefing assembled from
a dead weather server lands in the attention queue with a precise diagnosis (which step,
which error class) instead of a silently degraded briefing.

A `degrade` policy (mark the step output "unavailable", proceed with the final consumer)
is a clean one-field extension later — the data model doesn't preclude it — but v1.1 does
not build it. Strict/degrade, when it comes, is a decision made once at the task level,
not per step.

### 13.6 Admin UI

The run surface groups by parent run: child runs of one task run render as a grouped
timeline (levels for display, statuses from the child run statuses), and the run detail
view is where steps become visible. The scheduler strip may show several runs of one
task on the `llm` lane at once — the run list groups them under the parent. The attention
queue needs no new surface: a parent run carrying a failed step carries the step's error
class already.

### 13.7 What the step model is not

- **Not a second task shape.** One engine, one loop, one budget semantics. Steps are
  declarative structure on top of runs, not a parallel execution model.
- **Not per-step tool scoping as a new mechanism.** Toolbox scoping is per-run (§4.1) —
  which, under run-per-step, *is* per-step for stepped tasks, without a separate
  mechanism.
- **Not conditional logic.** The DAG is declared by the author, not computed by the
  model. The model cannot add, remove, or reorder steps mid-run. Runtime branching is a
  `session`-kind concern (§9), deliberately out of scope here.
- **Not nested steps.** One level of decomposition: task → steps. No steps of steps.
  If a step needs finer structure, write a better step brief — justified completion
  already forces a real artifact out of each step.

---

## 14. Scheduling (v1.1) — cron → dispatch, cursor-based

*Closes the last v1.1 roadmap item. The mechanism is task-weaver's proven tick
(`DESIGN_CONSIDERATIONS.md` §3), rebuilt on this engine's dispatch path here.*

### 14.1 The model

`task.schedule` holds a cron expression, as authored (nullable — a task with no
schedule is manual Run-now only, exactly v1). `task.next_run_at` holds the **cursor**:
the next owed occurrence, as epoch seconds. The cursor is the record of truth — not
wall-clock equality, not a cron-matcher's opinion about "now".

Epoch seconds, deliberately not a datetime column: DBAL reinterprets SQLite datetimes
in the process's *current* default timezone on hydration, so a datetime cursor compares
unstably across environments. The same reasoning produced `run.claimed_at`.

### 14.2 The tick

One tick (`app:schedule:tick`, or the daemon `app:schedule:run`, which the container's
`serve` fleet supervises):

1. **Arm.** An enabled scheduled task whose cursor is null gets it set to the next
   occurrence *after now*. A freshly enabled task starts on its schedule; it does not
   fire retroactively.
2. **Fire.** A task whose cursor has arrived — or already passed — launches its owed
   occurrence **through `RunLauncher`**, the same queue path as Run now. A scheduled
   run is born exactly like a manual one: parent graph or standalone run, first turn on
   the `llm` lane, carried by workers, never by the tick process.

Both effects of a fire — the advanced cursor and the created run with its first lane
message — commit in **one transaction**, so an occurrence is never both consumed and
lost.

**At-most-once, by compare-and-swap.** The cursor advance is a conditional UPDATE
(`... SET next_run_at = :next WHERE id = :id AND next_run_at <= :now`); a tick that
loses the race affects zero rows and steps aside. Two ticks, or two containers, cannot
double-fire an occurrence.

**Downtime never skips a slot.** A due task stays due until fired, so a delayed tick
(or a daemon that was down) catches the slot up instead of skipping to the next one.
Missed occurrences during a long gap collapse into the single catch-up fire: the cursor
always advances to the next occurrence *after now*.

**Overlap is held, not stacked.** A due occurrence is skipped while a previous
top-level run of the same task is still active (reported in the tick's output, still
owed). Two overlapping runs of one task is duplicate work by default, not the intent;
the occurrence fires as soon as the previous run settles. Events and dispatch stay
per-run under run-per-step (§13): a stepped task's "previous run" is its parent.

### 14.3 The trigger is ledger data

`run.triggered_by` records what launched the run: `manual` (Run now, console) or
`scheduled` (the tick). Set on the top-level run of a launch; child runs of a graph
keep the default. "Why did this run at 3am?" is answered by the ledger, not inferred
from timestamps.

### 14.4 Timezone

Schedules are wall-clock in the deployment timezone, `TASKLOOM_TIMEZONE` (IANA name) —
**required, part of the env contract** (§11): an 08:00 schedule silently running at
08:00 UTC for a Chicago operator is exactly the class of quiet wrongness this project
refuses. The container's boot lint names the variable when missing. DST is the
timezone database's problem, not a special case here: the next occurrence is computed
in the zone, instants are what is stored.

### 14.5 Failure policy — same as everywhere

- **Validation is a gate, twice.** An invalid cron expression is refused at task
  create/update (the authoring boundary, §13.2's discipline) and again at enable/approve
  — an invalid schedule never becomes an enabled task. Blank normalizes to "no
  schedule".
- **A launch that fails at dispatch is a ledger row, not a log line.** A toolbox that
  no longer resolves (or any classified dispatch failure) becomes a *failed run* with
  its error class, carrying the `scheduled` trigger; the occurrence is consumed. Loud,
  classified, no retry storm — the same treatment a child run gets (§13.5).
- **An infrastructure failure leaves the occurrence owed.** If the launch cannot commit
  at all, the transaction rolls back — including the cursor advance — so the next tick
  retries it. Never silently skipped.

### 14.6 What scheduling is not

- **Not a second dispatch mechanism.** The tick calls `RunLauncher`; there is no
  scheduler-side run creation, no scheduler-side lanes.
- **Not per-task timezones.** One deployment timezone; TaskLoom v1 is single-tenant.
- **Not catch-up replay.** A missed window fires once, at the next tick — not once per
  missed occurrence.

## 15. Chat (v1.x) — a conversation, not a task

**Status:** the conversational loop, the attribution invariant, the chat lane
as priority head, and the phone-first web surface are built. Alerts (§5 of
`CHAT_AND_CAPACITY.md`), streaming, and the assistant initiating are not.

### 15.1 Three nouns, and no fourth

| task world   | chat world          | what it is                         |
|--------------|---------------------|------------------------------------|
| `Task`       | `Chat`              | the durable thing being worked on  |
| `Run`        | `ChatExchange`      | one execution, triggered, claimable |
| `RunEvent`   | `ChatExchangeEvent` | one typed row in the ledger        |

**An inbound message is the trigger. Everything from that message until the
reply concludes is one exchange. Each thing that happens inside it is one
typed event.** An exchange is a run-shaped execution *over a conversation
instead of a task* — not a `Run`, and not a `Task`.

**There is no turn table.** The transcript is a filtered read: the
conversational rows of a conversation's exchanges, in order. A turn *is* an
event. (A `ChatTurn` table later would be a projection over these rows, not a
migration of them.)

### 15.2 Attribution is a safety invariant

**Every turn carries its speaker, and the model sees it.** Not "the system
knows" — the model is handed the attribution. A flattened two-party
transcript breaks in both directions: the assistant's own past output reads as
the human's instructions (a prompt-injection surface grown inside her own
history, in a system where she holds tools), or the human's instructions read
as her own prior words and may not be followed.

- **Stored, richly, in typed columns:** `speaker` (`andrew` | `nia`), `role`,
  `origin`, `content`, `reply_to_id`. Typed rather than payload entries
  because a safety invariant should not live in an untyped blob — nothing
  enforces a blob's shape, and a reader has to know the convention to find it.
- **Rendered as roles:** `andrew → user`, `nia → assistant`. That mapping is
  what tells the model whose opinions are whose, and it is the form a small
  model is trained on.
- **The human never types a name.** Attribution is applied by the pipeline at
  the render layer; "morning" arrives as a turn *from Andrew*. For two
  participants the prompt shows roles only, with no visible prefix.
- **The roster does not assume two.** `Participant` is an enum with a display
  name, so a third participant moves names *inside* a role (`Nia: …` vs
  `Reviewer: …`) — the same mechanism one rung up.

### 15.3 Capacity: the chat lane is the priority head

`TASKLOOM_LLM_MAX_CONCURRENCY` is the **total** number of requests in flight
to the model, not a per-lane budget (§6). The schedulable unit is therefore
one LLM call, and preemption is not "stop the task" — it is **which lane the
next available worker drains first**.

- The `chat` lane is a transport on the same `messenger_messages` table.
- The LLM workers consume **`chat llm`**. `messenger:consume` is
  strict-priority across its receivers — `Worker::run()` iterates them in
  order and breaks on the first that handled an envelope — so receiver order
  *is* consume order, and every existing LLM worker becomes chat-aware with no
  new process, no lock, and no async runtime.
- **It does not create capacity.** At the default `N=1` a chat goes *next*,
  never *now*: the worst case is one in-flight generation, and for a reasoning
  model that is tens of seconds. Sub-second preemption needs a streaming
  client that can abort mid-generation; the seam for it is not built, and the
  client is deliberately non-streaming today.
- **Halt is scoped to each LLM call**, not to the chat's whole duration. A
  conversation that goes quiet while a human thinks must not leave tasks
  halted. Waiting for a human occupies nothing.

### 15.4 Failure is loud

A run that throws is visible — it lands in `failed` and the run page shows it.
A chat turn that throws has **a person staring at it**, so the same treatment
is not enough. A failed exchange is marked `failed` with a classified reason
in its ledger, and the surface says so out loud. It is deliberately **not**
re-dispatched: a model that is down will still be down a second later, and a
hot retry loop against a single-slot server is how one conversation stops
every task. The human's next message starts a fresh exchange; that is the
retry.

### 15.5 Respond-only, with the seam left

v1 answers; it does not open conversations. The seam is that
`ChatExchange.triggered_by` exists from day one with `inbound` as its only
value — so the trigger is a *value* on the exchange rather than the presence
of an inbound turn. The one review rule that must hold: **do not let "an
exchange always has a triggering inbound turn" harden into a non-null FK with
no escape.** That is the single decision that would make initiation hard.

The delivery path is already separate: when the assistant initiates, the
*notification* that tells you to look is an alert, and alerts have their own
channels. Chat is a place you go; an alert is a thing that reaches you.

### 15.6 What is shared with the run engine, and what is not

**Shared (machinery, not tables):** the ledger row's layout (`LedgerEvent`,
a Doctrine `MappedSuperclass`, so the two event tables are identical by
compiler rather than by convention); the claim / checkpoint / requeue protocol
(`App\Claims\ClaimStore`, written once against a closed set of claimable
aggregates); the message bus and its lanes; `LlmClientInterface`.

**Not shared:** the `Task → Run → Step` graph, and `RunStatus` as the state
machine. A chat's states are its own — `queued | running | answered | failed`
— because a conversation never declares justified completion, and sharing the
run's enum would drag `paused`/`needs_attention`/`incomplete` into a domain
with no use for them.

**Why not reuse the tables.** `Run.task_id` is NOT NULL and
`Run::__construct()` takes a `Task`, so reuse means a synthetic "Internal:
Chat" task row — a lie at the centre of the schema, appearing in the task
admin UI. And `findRecent()` / `findAttention()` filter on `parent IS NULL`
only, so a chat row would **silently** appear in the run history and the
attention queue, the queue whose entire job is "a run needs a human".

### 15.7 Boot recovery covers chat exchanges too

An exchange abandoned by a fleet that was killed is as stuck as a run is, and
worse in one respect: a human is waiting for an answer that will not come. So
`ClaimReaper` (§6.2) sweeps **every** claimable aggregate, and the container's
boot sequence runs `app:chat:requeue` alongside `app:run:requeue --startup`.
The reap is written against the shared claim store rather than per table, so a
third claimable aggregate cannot be added and then quietly left out of boot
recovery. The boot line reports the split, because "work that stopped" and "a
person waiting" want different attention.

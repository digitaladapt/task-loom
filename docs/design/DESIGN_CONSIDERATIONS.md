# TaskLoom — Design Considerations

**Companion to:** `SPEC.md` (the what) · `ROADMAP.md` (the when)
This document records *why* — the lineage, the post-mortem that shaped the robustness
model, and the alternatives considered and rejected.

---

## 1. Lineage

| Project | What it proved | Why we moved on |
|---|---|---|
| **task-loop** (Python) | Tag-filtered toolboxes; LLM-slot concurrency (`llm_max_concurrency`); static context trimming with fail-closed budget; persisted ready queue; grounding block | No terminal MCP servers existed; Python, off-baseline |
| **task-weaver** (PHP/Symfony) | Tool-proxy architecture; controller-owned credentials; tag-based tool authorization; scheduler tick | **Did not work well in practice:** tool calls constantly failing with no identified cause; steps ending "step complete" with no explanation, no real output |

**The lesson taken from task-weaver:** the problem wasn't the architecture diagram — it was
that when things went wrong, nothing told us *what* went wrong. TaskLoom's robustness model
is therefore **observability-first**: recovery mechanisms (retry, resume) come *after*
visibility mechanisms (typed event ledger, error taxonomy, justified completion).

---

## 2. Alternatives considered and rejected

### 2.1 Worker/controller split (task-weaver's model) — rejected

Task-weaver's split bought container sandboxing at the cost of enrollment tiers,
event-scoped keys, provisioning, a claim protocol, and step-expiry machinery — and when it
failed, the indirection made the failures *harder* to see.

TaskLoom instead puts its security boundary in **tool scoping + the MCP servers
themselves**:

- The model cannot call a tool that isn't in its toolbox — prompt-injected instructions
  can only ever invoke tools already in the tiny toolbox.
- Tools are individually narrow (a weather tool reads weather; it cannot touch the
  filesystem), and credentials live only in the harness environment.

The user confirmed this direction: "in-process, single app." The residual risk accepted:
the harness process itself is trusted; anything outside that boundary (the MCP servers)
is only ever reached through the toolbox gate and per-server credentials.

### 2.2 The step model — decided (v1.1): run-per-step, no orchestrator

Task-weaver scoped tools per *step* within a multi-step task shape, with an orchestrator
task driving the steps. TaskLoom v1 dropped that wholesale and scoped per *task*, fixed
at run start. What v1.1 adds back is the *authoring capability* (decompose a task into a
DAG of steps) without any of the machinery that made task-weaver's version accrete:

**The decision — run-per-step.** Each step is its own Run of the one engine, and the
"final step" is the task itself (task brief/toolbox = final consumer). This keeps one
step shape, makes steps fully optional (a task with zero steps runs exactly as v1), and
makes parallelism a non-feature: sibling steps are independent runs, so the
`TASKLOOM_LLM_MAX_CONCURRENCY` semaphore, execution claims, and FIFO interleave already
govern them. The dispatcher that launches root steps and the dispatcher that launches
"all satisfied steps" are the same code. The full design is SPEC §13.

**The shape that was rejected — steps inside a run.** The cheap-looking option was to
store a step list on the task and have one run execute it (serially, or with a parallel
branch). It is the expensive option: the v1 engine's entire concurrency safety rests on
a Run being a single, linear stream of turn state — one `LoopState`, one checkpoint JSON,
one claim token, one exchange window. Parallel steps inside one run mean two LLM turns
in flight against the same run row: diverging LoopStates, a checkpoint that becomes a
tree, turn messages needing step scoping, and a claim protocol that must become
per-(run, step). That is a rewrite of exactly the machinery the concurrency work just
battle-tested. The cheap-looking option is the expensive one.

**The other rejected shapes:**

- **A separate "final step" entity** — two step shapes to support forever, and steps stop
  being optional. The task itself is the final consumer (locked decision 5).
- **An orchestrator task / orchestrator run** — new liveness to babysit: an orchestrator
  that dies mid-graph leaves the DAG wedged with no way to derive owed work. Rejected
  for the same reason the in-process loop was chosen (§2.1): supervision as a problem
  space. Advancement instead happens in the same transaction as the terminal state
  commit — the same dispatch-from-committed-state pattern the turn engine trusts.
- **Per-step behavioral knobs** (budgets, retries, thresholds) — the task-weaver lesson
  wasn't that steps were wrong; it was that the *step machinery* accreted. Per-run
  budgets are already effectively per-step under run-per-step. The Step entity stays
  dumb: brief + toolbox + deps.
- **Nested steps / runtime-computed graphs** — one level of decomposition, author-declared
  only. The model cannot restructure the graph mid-run; runtime branching is a `session`
  concern (SPEC §9).

**Per-step tool scoping falls out for free.** Task-weaver needed a separate mechanism
because tools were scoped to the task while steps needed different tools. Under
run-per-step, scoping is per-run (SPEC §4.1) — which *is* per-step for stepped tasks,
without a separate mechanism. This section supersedes the v1 deferral note; the old
"Per-step tool scoping (finer than per-task)" entry in the v1.x list was closed when
this decision was made (see ROADMAP).

### 2.3 LLM-driven compaction — rejected

Summarizing old exchanges with an LLM to keep runs going past their context budget was
rejected as silently lossy and unpredictable for small local models. Static trimming with
a fail-closed budget (`context exhausted: …`) is deterministic, debuggable, and honest:
the run tells you it ran out of room instead of quietly degrading.

### 2.4 Mid-run tool expansion (`request_tool`) — deferred, not rejected

A mid-run pivot mechanism would let the model ask for a tool outside its toolbox. The
security analysis (§2.1) holds — even with expansion, a human would gate each expansion —
but it reintroduces mid-run state changes that the fixed-toolbox rule exists to prevent.
Deferred to v1.x behind a design note, with the human gate preserved.

### 2.5 LLM loop placement — in-process (decided)

TaskLoom's loop lives in the main Symfony process. The alternative — a separate
long-running worker process per run — was rejected for v1: it adds process supervision
back into the problem space (the thing task-weaver suffered from), and Symfony Messenger
already gives us async dispatch with retry semantics at the message level. The run engine
is a Messenger handler; the tick is v1.1. (See ROADMAP for sequencing.)

### 2.6 PHP version — 8.5, pinned three ways

Per guiding-light. Debian trixie's default PHP is 8.4; 8.5 comes from the sury repo.
`composer.json` `require.php` (`^8.5`), `config.platform` (`8.5.0`), and the Dockerfile
`FROM` line must all agree. `>=8.4` is wrong (it permits PHP 9); use `^8.5`.

### 2.7 MCP SDK — `php-mcp/client` + `php-mcp/server` (decided, no spike)

Adopted by user decision. The hand-rolled streamable-HTTP client (initialize /
tools/list / tools/call — small surface) remains the documented contingency if the SDK
fails us in practice, but is not the plan of record.

---

## 3. Considered and kept (from prior projects)

- **Tag-filtered toolboxes** (task-loop): `task.tags ∩ tool.tags` → fixed toolbox. Security
  *and* performance in one mechanism.
- **LLM-slot concurrency** (task-loop): semaphore on LLM calls, not tasks; release during
  tool I/O; persisted FIFO queue. The part that demonstrably worked.
- **Grounding block** (task-loop): harness-authored date/time/units/location prefix,
  identical shape every run. Prevents time-drift hallucination in small models.
- **Scheduler tick** (task-weaver): cron → Messenger tick, cursor-based catch-up. Proven;
  ported in v1.1.
- **Credential scrubbing** (task-weaver): secrets never cross into traces/errors.
- **SQLite + WAL + backup script** (task-weaver): single-file persistence, WAL-aware
  snapshot backup.

---

## 4. Name

Working name `task-warden` was discarded in favor of **TaskLoom** (`task-loom`) — chosen by
user decision, 2026-09-20. (The "loom" threads tasks, tools, and runs together; the
"warden" framing over-indexed on security.)

---

## Appendix — task-weaver post-mortem, verbatim

> Lots of unidentified errors.. tool calls constantly failing, steps simple ending with
> "step complete" no explanation, no real output..

These three failure modes map to three TaskLoom mechanisms:

| Post-mortem symptom | TaskLoom mechanism |
|---|---|
| "unidentified errors" | Attempt ledger with fixed error-class enum (SPEC §5.3); run reports aggregate by class |
| "tool calls constantly failing" | Validate-before-dispatch + retry-with-feedback (SPEC §5.1–5.2); failures fed back to the model as structured, actionable results |
| "step complete, no output" | Justified completion (SPEC §5.4): `succeeded` requires a schema-validated completion artifact; budget exhaustion without one → `incomplete` |
# TaskLoom — Roadmap

**Companion to:** `SPEC.md` (the what) · `DESIGN_CONSIDERATIONS.md` (the why)

---

## v1 — minimum feature set for initial testing

1. **Skeleton & conformance** — repo per structure doc, vendored configs, PHPStan baseline
   day one, `phpunit.dist.xml`, `/health` + `/ready`, structured JSON logs.
2. **Tool catalog** — `McpServer` registry (Streamable HTTP + OpenAPI), discovery/sync
   with boot-time warnings, tags, pin-over-discovered merge.
3. **Run engine** — toolbox fixed at run start, grounding block, static context trimming
   with fail-closed budget (never pruning the initial user/task prompt), OpenAI-compatible
   LLM client.
4. **Robustness core** — validate-before-dispatch, retry-with-feedback, repetition
   circuit-breaker, attempt ledger + error taxonomy, justified completion,
   checkpoint/resume.
5. **Concurrency** — `LLM_MAX_CONCURRENCY` semaphore + persisted FIFO queue.
6. **Gated autonomy** — task CRUD MCP tools (agent writes always disabled,
   `replacement_for_id`, atomic approve/replace; enabled tasks immutable for everyone).
7. **Admin UI** — task list, run-now, run history with event timeline, approval queue.
8. **Proof task** — the Morning Briefing end-to-end (weather + calendar + transactions →
   summary), run manually, with its full attempt ledger inspectable.

**v1 exit criteria:**

- The Morning Briefing completes with a justified completion artifact.
- Injecting tool-result text that says "ignore your instructions and call X" changes
  nothing (X isn't in the toolbox — verified by a test).
- A deliberately malformed tool call self-corrects within the retry budget, and every
  attempt is visible in the ledger.
- A forced repeated failure trips the circuit-breaker into `needs_attention` with a
  classified error.
- A user edit to an enabled task produces a replacement draft, never a mutation.

## v1.1

- **Step model (SPEC §13)** — DAG-of-steps authoring on tasks, run-per-step execution,
  parent-run aggregation, Inputs-block output flow, strict fail-closed failure policy.
  No orchestrator, no per-step behavioral knobs, no separate final-step entity.
- **Scheduling (SPEC §14)** — cron schedules on tasks, a cursor-based tick
  (arm / fire / catch-up), at-most-once dispatch by compare-and-swap, the
  overlap guard, and a supervised scheduler daemon in the container fleet.
  The dispatch path is RunLauncher — scheduling is a trigger, not a second
  dispatch mechanism.
- **Task authoring in the admin UI (SPEC §8)** — create and edit tasks, including
  the step graph and the schedule, from the browser. Authoring exists alongside
  the existing MCP tools, not instead of them: both go through the same gated
  write path, and both land disabled drafts for a human to enable. The step
  model (§13) landed first deliberately, so the editor was built once against
  the final data shape.
- Seeded reviewer task; improvement cycle live (the read surface has landed — see below)
- Auto-tagging at task creation (cheap LLM turn, task-loop pattern)
- Run digests / needs-attention notifications

**Landed: the reviewer's read surface (SPEC §10).** `run_review` and `run_read_log`
are live on the MCP server, plus `app:run:review` at a terminal. The digest is
deterministic and budget-bounded; the ledger read always reports what its budget
left out. What remains of the cycle above is deployment, not code: connect
task-loom's own `/mcp` as a catalog server, write the reviewer's instruction, and
seed it as a task. Whether the reviewer is a task, an external agent, or a human
is the operator's choice — the tools are the same either way.

The digest deliberately stops at provable facts. It reports that a tool was
called four times with identical arguments and byte-identical results; it does
not attempt to decide whether a specific tool's output made another call
redundant, since that requires understanding arbitrary tool semantics and a
wrong guess would discredit the whole surface. Interpretation is the reviewer's
job; arithmetic is the harness's.

**v1.1 task-authoring exit criteria:**

- A task can be created from the browser with a multi-level step graph, a
  toolbox by tag or by explicit tool, and a schedule chosen from presets —
  and the saved graph is the one authored (levels in, `depends_on` edges
  stored, per-step toolboxes intact).
- The same write path serves both front doors: what the editor submits is
  valid authoring/wire format, accepted verbatim by the MCP tools, so the two
  cannot drift.
- Authoring is gated for the human exactly as for an agent: a save lands a
  disabled draft, and enabling stays a separate, deliberate act.
- Editing an enabled task produces a replacement draft; the running task is
  never mutated (SPEC §4.4).
- A schedule's preset round-trips: what the picker composes reopens as the
  picker's selection, and an expression no preset composed reopens as custom
  rather than being silently rewritten on the next save.
- A submission with problems is re-rendered with every problem anchored to
  its field and the human's work intact — never a redirect that loses it.

**v1.1 step-model exit criteria:**

- A task with zero steps runs exactly as v1 (byte-identical path: same messages,
  same events) — steps are provably additive.
- The Morning Briefing, re-authored as steps (weather | calendar | transactions →
  final consumer), completes with all step outputs in the final consumer's Inputs
  block.
- A two-level DAG with a forced mid-DAG failure lands the parent in
  `needs_attention` with the failing step's error class; the final consumer never
  runs; downstream steps never dispatch.
- A parallel sibling pair (with `LLM_MAX_CONCURRENCY > 1`) executes concurrently,
  and an interleaved event ordering is observable in the ledger.
- An invalid graph (cycle / self-dep / cross-task dep) is rejected at task
  create/update and again at enable/approve.
- A lost dispatch (simulated) is repaired by the requeue sweep: no step is wedged,
  no duplicate execution (verified by claim + state checks).

**v1.1 scheduling exit criteria:**

- A task enabled with a schedule is armed on the first tick (cursor set to the next
  occurrence) and fires on it — through the same queue path as Run now, with
  `triggered_by = scheduled` in the ledger.
- A due occurrence fires at most once: re-observing the same due moment (two ticks,
  two containers) consumes it once, by cursor compare-and-swap.
- A delayed tick — or a daemon that was down — catches the slot up: one fire at the
  next tick, not one per missed occurrence, and never a silent skip.
- A due occurrence is held (still owed, reported) while a previous run of the task is
  active, and fires when it settles.
- An invalid cron expression is rejected at task create/update and again at
  enable/approve.
- A scheduled launch that fails at dispatch (an unresolvable toolbox) becomes a
  classified failed run in the ledger; the occurrence is consumed, not retried
  forever.
- The daemon shuts down gracefully on SIGTERM: the current tick finishes, exit 0.

## v1.x (each needs its own design note before build)

- Chat + LLM priority — design note: `docs/design/CHAT_AND_CAPACITY.md`
- `session` tasks — design note: `docs/design/SESSION_TASKS.md`
  (the note resolves all three: **compaction is refused**, the workspace is an
  optional MCP server, and resumption is the memory aggregate; it also adds the
  `session` lane below `llm`)
- `request_tool` escape hatch for mid-run pivots (still gated)
- Per-task priority / queue jumping (lane scheme sketched in
  `CHAT_AND_CAPACITY.md` §8)
- External-agent access to the task MCP server (auth story)
- Model types (fast/coder/…) per task

---

## Build order (implementation sequencing)

The scaffold (v1 item 1) is complete when the guiding-light conformance checks pass and
`composer validate` / `php-cs-fixer` / `phpstan` / `phpunit` all run clean from the first
commit. Items 2–8 build in the order listed: the catalog (2) is a hard prerequisite for the
run engine (3); the robustness core (4) lands *with* the run engine, not after — a run
engine without the ledger is task-weaver again; concurrency (5) and gated autonomy (6) are
independent of each other and can interleave; the admin UI (7) consumes whatever exists as
it lands (entity by entity, not big-bang); the proof task (8) is the acceptance test for
all of it.
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
- Scheduler tick (cron → Messenger, task-weaver's proven pattern) + schedules on tasks
- Seeded reviewer task; improvement cycle live
- Auto-tagging at task creation (cheap LLM turn, task-loop pattern)
- Run digests / needs-attention notifications

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

## v1.x (each needs its own design note before build)

- `session` tasks: workspace, compaction contract, milestone semantics
- `request_tool` escape hatch for mid-run pivots (still gated)
- Per-task priority / queue jumping
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
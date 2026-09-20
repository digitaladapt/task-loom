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

### 2.2 Per-step tool scoping (task-weaver's finer-grained scoping) — deferred

Task-weaver scoped tools per *step* within a multi-step task shape. TaskLoom scopes per
*task*, fixed at run start. Per-step scoping requires a step model and a "two task shapes"
contract (parallel steps + final consumer), which is the machinery we are dropping. The
toolbox snapshot per run preserves the audit trail. Per-step scoping can layer on later
without redesign.

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
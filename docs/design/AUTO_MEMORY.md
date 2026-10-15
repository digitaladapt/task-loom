# Automatic memory — design note

**Status:** proposed · draft for discussion · **Scope:** automatic store and
auto-inject for the assistant stack — the session emission seam, the chat
injection gap, the boundary between session memory and the persistent store,
and the capacity reasoning that shaped the design. Reopens one settled
question deliberately (SESSION_TASKS §14 decision #7; argued in §5).

**Provenance:** from a conversation with Andrew, 2026-10-13; supersedes the
earlier sketch parked in the operator's ledger
(`/home/user/ledger/2026-10-13-3.md`). That sketch assumed a clerk that
"runs on idle"; this revision removes that assumption, because idleness is
not the resource this deployment has (§8).

---

## 1. The problem

Two halves, one symptom.

- **Write side (auto-store).** The small local model rarely writes memories
  uninvited, despite being encouraged to be prolific. This is not a prompting
  failure to fix with more prompting: the decision is cheap to skip, and it
  gets skipped. The industry's answer to the same symptom is the same:
  don't ask the model — extract after the turn (Letta's sleep-time compute;
  Mem0's retrieve → inject → extract loop; LiteLLM middleware that stores and
  injects around any model).
- **Read side (auto-inject).** What should ride into the next request should
  simply *be there*, not be fetched by a tool call. Sessions already have this
  half (`## Memories`, §7); chat does not.

**The constraint that shapes everything.** One LLM request at a time,
locally: llama.cpp with `parallel=1` *rejects outright* any request that
arrives mid-generation, and the `llm` lane is the design feature that keeps
us from ever making such a call by accident (CHAT_AND_CAPACITY §4.1). Any
design must therefore never multiply generations: no "read the content, then
read it again to extract". The goal state is a second instance on an external
provider that handles concurrent requests — but the design must be correct at
N=1 first, and merely *profit* from N>1. Capacity is a dial, not an
assumption.

## 2. What exists, and the one distinction to hold

**Session memory is working state — and is internal to task-loom by decision**
(SESSION_TASKS §14 #1: not a context-shuttle key, no MCP dependency). Built:
the store (`SessionMemoryStore`), the renderer (`SessionMemoryRenderer`), the
per-request injection seam (`ContextWindow::buildMessages()`'s 4th argument),
the write tools (`session_note`, `session_objective`, `session_declare`), and
the slice engine's settlement through the terminal-commit choke point.

**The persistent store is durable knowledge — the operator's, cross-session,
serving chat too.** In this deployment: context-shuttle, an MCP server like
any other; a session may attach it to its toolbox (SESSION_TASKS §9), and a
session with zero MCP servers still works.

These are two stores with two lifecycles, two namespaces (the session vs. a
key), two injection paths, and two dependency postures. That is not
duplication waiting to be unified; it is the design. The rule that keeps them
apart and makes the boundary cheap:

> **The survive-the-session test.** Does this fact need to outlive the
> session? No → session store. Yes → persistent store. When both, it can be
> both, written twice by route, not by duplication of machinery.

**"Two different ways into the same system" is true twice, and both need
rules:**

1. *Every write into the session store converges on one policy* — the
   model's mid-work tools (`session_note`), step 0's echo (§3.1), the
   emission's instruction (§4). The store dedupes, caps, tiers and revisions
   every writer alike; no writer gets a privileged size or a special tier.
2. *Two destinations across the stack* — session store vs. persistent store,
   decided by the test above. The emission may carry both routes (§6).

Neither is accidental; both are policy.

## 3. The mechanisms, one policy

Ordered by cost; all four write through the stores' ordinary paths.

| | Mechanism | LLM cost | Purpose |
|---|---|---|---|
| 0 | **The settlement's own words** | zero | Sessions only. The settle path files the declaration's summary (or an undeclared continuation's message) as one note — cooperation-free (§3.1). |
| A | **Pre-filter** | zero | Structural extraction from finished exchanges: explicit "remember this", corrections, concrete identifiers (PR/run numbers, dates, versions, paths). Regex-shaped, deterministic, testable. |
| B | **Emission** | epsilon | Sessions only. The settlement generation carries the slice's memory payload in the same call it was already making. |
| C | **Clerk** | one request, bounded | The residue that needs judgment: new keys, consolidations, relationships, chat's whole write side. Reserved and off the critical path (§5). |

Routing posture: **Step 0 files by echo, A stages, B files by instruction, C
curates.** The pre-filter never persists to the persistent store silently —
it stages candidates (a pending queue the operator or the clerk can
promote). Drizzle must not ride every prompt, and the thing you cannot undo
is junk in the durable store; drafts you can.

## 3.1 Step 0 — the settlement's own words (ship first)

Andrew's reading, confirmed: a declaration's content becomes a session note,
no tool changes. Two refinements land it: *which* content, and *which*
declarations.

**What.** Every declaration whose content becomes the run's completion
artifact — its `summary` — is additionally filed as one **session note**, by
the settle path itself. No schema change, nothing new for the model to say:
exactly what it already said, in the store that already exists.

**Which declarations: all of them, not just milestones.** The rule is "what
this slice said becomes one note", not "what the next slice needs" — the
latter means the settle path inspecting the next slice's purpose, which is
the judgment creep step 0 exists to avoid. And `blocked` earns it as much as
anything: its summary is already its completion artifact precisely so a
human — and, after a later resume, the session itself — can recover where it
stopped.

**Also the undeclared continuation's message** (SESSION_ENDINGS §4's
`continue` policy): when a slice settles undeclared-and-continuing, the
terminal message IS its completion artifact, and it becomes the note the
same way. Otherwise note-filing would cover exactly the slices whose
declarations arrived — and skip the next slice's window precisely when the
model already failed to declare once.

**Why it lands only with the milestone.** `milestone` launches the next
slice in the settlement commit; that slice begins from a fresh checkpoint —
empty window, shared head — so the next call runs cold, and the note is
already riding the next request's `## Memories`. For `done`/`blocked` the
surface that matters is an operator page, not a model context: filing there
is a consistency side effect, not the motivation.

**What it is not.** Not the emission (§4): nothing extra is asked,
nothing is extracted, no schema moves. Not a summarizer: the note is the
summary verbatim. If a summary is thin as a note, that is the store's normal
quality problem and the clerk's normal job, not step 0's.

**Mechanics.** The harness writes through `addNote()`: the writer is the
settle path, the source stays `session` (no new enum case; §9 #2), and tiers
are the store's business — the note lands hot and ages like any other.
Everything step 0 adds: one call site in each settle closure (`settleSlice`
for a declaration, `settleUndeclaredSlice` for a continuation), plus the
store's injection into `RunEngine`.

**Why it is step zero.** It exercises the whole pipeline with none of the
new machinery: the block already injects (`buildMessages()`'s 4th argument),
the renderer already exists, milestone runs already begin fresh — so the
loop's first slice is cheap, observable (the ledger already records the
declaration), and it is the smallest possible answer to "the model rarely
writes memories": for the one moment it already wrote one, the harness files
it.

**Costs and honest counter-cases.** One store row per settled slice; the
normal caps bound it (5 hot / 25 cold, writes `guardText`-bounded); a
next-slice-cold problem only a milestone has, solved for the exact slices at
risk. Counter-cases: a session whose next slice is genuinely unrelated eats
a hot slot for nothing (cap 5 — acceptable); summaries phrased toward the
operator ("done; all green") are thin notes (clerk's consolidation job);
and the note is *instant*, not drafted — no staging cushion, unlike the
emission's payload. Accepted: the stages are rated differently because
their triggers are rated differently — step 0 fires on a declaration that
already passed; the emission fires on proposals the model was asked for.

## 4. Mechanism B — the emission seam (the centerpiece)

Sessions end every slice with a generation that was going to happen anyway:
the settlement that emits `session_declare`. That request is already paid
for; the slice's entire context is already inside it. So:

```
the declaration generation ──► settle (terminal-commit choke point)
                                 ├─ outcome  → slice machinery
                                 ├─ summary  → ledger / operator
                                 └─ memories → store(s)          [new]
```

**Shape.** `session_declare` gains an optional `memories` array — a filing
instruction, not state:

```
memories: [
  { route: "session", kind: "note"|"objective", text: "..." },   // default
  { route: "persistent", key: "...", text: "..." }               // §6
]
```

**Why this shape and not a tool call.** The ALONE rule ("no other calls
beside it; nothing dispatches from a round that carries it with company",
`RunEngine`'s refusal) means a *second* call cannot ride that round. A field
on the one call that is required can. So the payload rides the mandatory
generation:

- **No extra request** — the second reading of the same content, the cost
  that motivated this whole note, never happens.
- **No reliance on politeness** — the trigger is structural (the settle path
  always fires), which is exactly the failure mode of "encourage the model to
  write memories": cheap decisions get skipped. A required call with an
  optional field gets made; what it carries is then a smaller decision.
- **One commit** — at the terminal-commit choke point the settlement, the
  memory writes and the next slice's launch are one transaction; the next
  slice reads the state the last one filed, with no window between.

**Failure semantics.** Schema-validate the payload; a malformed payload is a
validation error the declaration is re-issued without (the existing pushback
pattern). Session-store writes ride the settlement commit — fail-closed, per
house style: the settle does not silently degrade. Persistent-route items are
*not* transactional (different system): they enqueue in an outbox committed
with the settlement and are replayed by a tools worker, best-effort, with
failure as an event — never a block. Prior art for the outbox is this very
deployment: `ledger/pending-memory-*.md` files exist because a memory write
once failed mid-conversation and a human parked it for replay. Automate that
shape.

**What does not change.** `session_note` / `session_objective` remain
first-class: an emergency is written the moment it is known. The emission is
the end-of-slice sweep that catches what the mid-work calls missed — a new
path, not a replacement, and not a summarizer. (DESIGN_CONSIDERATIONS §2.3
stands: this store files facts; it does not compact context.)

### The reopen, declared

SESSION_TASKS §14 #7: "the declaration carries the go/no-go and the summary
only — state crosses via the store." The emission does not carry *state* —
nothing the next slice must read in order to run; it is a **consequence
filing** of the settlement generation, the same category as the summary's log
line, one step further. Cost if the review disagrees: a fatter declaration
schema. Mitigation: `memories` is optional, count-capped (proposal: 5, the
hot tier's size), and read by nothing but the store.

## 5. Mechanism C — the clerk

The one thing that genuinely needs a model: deciding that something is a
*new* key, that two things belong together, that a draft is ready to become
durable. Constraints, in order:

1. **It must never delay a human or a slice that is owed.** Lane order is
   chat > task > session, and a session holds the lane per turn — a paused or
   blocked session has no lane claim at all (SPEC §9; CHAT_AND_CAPACITY §4).
   So the clerk is a plain task in the **task band**, launched by the tick
   (v1.1) or manually: it can only run where a *task* could have run, behind
   chat, never ahead of an owed slice.
2. **It is bounded and countable.** Every Nth slice, or every N minutes,
   whichever the operator sets; one request per run; the run list shows it.
3. **It never runs "on idle".** Idleness is not a schedulable resource: an
   always-on deployment deletes it, and a design that waits for it stops
   first exactly when the system is busiest. The clerk waits for *boundaries*
   (a settlement, a slice end, a lane gap), which exist at every utilization
   level. At N>1 (the second instance, external provider) it stops hiding
   entirely and becomes an ordinary concurrent request.

Chat has no emission seam — there is no mandatory terminal call to ride — so
chat's write side *is* the pre-filter plus the clerk. Interim (N=1): the
pre-filter needs no lane and covers chat now, staging to the outbox; the
clerk's cadence is an operator knob.

## 6. The bridge — session store ⇄ persistent store

Route, per item, by the survive-the-session test. Mechanics:

- A session that wants durable memory attaches the persistent store's MCP
  server to its toolbox (optional, per §9 — no dependency). The emission's
  `route: "persistent"` items are written through that server by the harness,
  batched into the settlement commit as an outbox row.
- No server attached → persistent-route items are dropped with a ledger
  event, never an error: task-loom works with any servers, and with none.
- The clerk curates that boundary long-term: promotes staged drafts, spends
  the "new key" judgment, consolidates. The boundary is where judgment lives,
  which is why mechanism C exists at all.

## 7. The injection seam

- **Sessions: exists.** `## Memories` per request (objective always, hot
  notes capped at `TASKLOOM_SESSION_HOT`, cold retained, pins exempt,
  `TASKLOOM_SESSION_MAX_MEMORY_PCT` backstop, pins always ride so the worst
  case degrades to today's block, never to silence).
- **Chat: the gap.** `ChatContextWindow::fit()` takes the system message and
  tool descriptors only. Add a memory block input at assembly, same bounds,
  same role convention as sessions (assistant, SESSION_TASKS §14 #4).
- **Relevance, when stores grow: procedural, not a model.** Mention-match of
  the existing key vocabulary (key names and recorded aliases already carry
  the semantics — `pref:email`, `project:work-warp`) plus recency plus pins.
  Multi-string match, milliseconds, testable. Embeddings, if ever earned, are
  a separate CPU process — they do not consume the LLM slot.
- **Record what was shown.** Render deterministically and record the
  rendered block (or its hash) per exchange; "what could she see?" is then
  answered from the record.
- **Echo invariant.** The emission reads the *transcript* (what happened),
  never the rendered block (what was shown). Never re-ingest a block you
  caused to appear.

## 8. Capacity and the second instance

Restated, because the design is built around it:

- `TASKLOOM_LLM_MAX_CONCURRENCY` is the total in-flight count and is the same
  quantity as the server's own parallelism; at `parallel=1`, a second arrival
  is rejected by the server (CHAT_AND_CAPACITY §4.1). The lane exists so we
  never have to worry about accidentally making concurrent calls — it is a
  feature, not a limitation to route around.
- Consequences for this design: **A** consumes no LLM time; **B** consumes
  epsilon output tokens on a call that was already being made; **C** is the
  only new request, banded and bounded. Nothing here is "extra time spent
  going through content a second time" — that cost was designed out, not
  budgeted for.
- The second instance changes no architecture: lanes become per-instance
  knobs, and C is promoted from "reserved" to "ordinary". The design's job is
  to be correct at N=1 and to have nothing to unlearn at N>1.

## 9. Open decisions (for review)

1. `memories` shape: one array with per-item `route`, or two arrays? (Lean:
   one array, `session` default.)
2. New `SessionMemorySource` case for the emission, or reuse `session`?
   (Lean: reuse — it is the same writer; the ledger event carries the audit.)
3. Emitted-items cap and overflow policy (Lean: 5; overflow dropped with an
   event, or forced cold).
4. Chat block role/placement — assistant, per house precedent?
5. Does the pre-filter ship ahead of the seams? It is the only mechanism
   that needs nothing new from the model or the lanes — pure capacity win.
6. Clerk cadence defaults and the operator knob's name.
7. Step 0 note text is the summary verbatim (trimmed); a blank summary is
   skipped with an event — the schema's `minLength: 1` forbids it, and the
   settle path should enforce structurally anyway.
8. Step 0 rides the store's ordinary caps (lean). Give slice notes their own
   sub-cap only if 5 hot slots prove precious in practice.

## 10. What this is not

- Not a build plan. Status: proposed; nothing here is scheduled.
- Not LLM compaction (DESIGN_CONSIDERATIONS §2.3 unchanged).
- Not a second memory policy: caps, tiers, revisions, provenance and §10's
  security frame (memory is framed as data; the emission payload is model
  output of the same trust class as `session_note`, and is framed the same
  way) all apply to the new path unchanged.

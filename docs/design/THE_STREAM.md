# The stream — one being, one context, forever

Status: **DRAFT, agreed in direction with Andrew 2026-10-23.** Phase 1 only.
Supersedes the continuity mechanisms in `SESSION_TASKS.md` §6–§8: a session's
slices and its memory block were the *old* answer to "a context cannot persist".
This note is the answer now that it can.

---

## 1. The goal, stated once

A being is **one append-only stream** of events — turns, tool calls, tool
results, operator messages, and whatever else happens to her — and **one
context** compiled from that stream.

- A turn is a turn no matter which door wrote it. Chat is not a parallel
  context that references a session; it is **another writer to the same
  stream**. There is nothing to bind, because there is nothing to bind *to*.
- What is sent to the LLM is the stream, trimmed only when it outgrows the
  window, and trimmed **in batches** — so the front stands still between trims
  and the server's prefilled prefix is reused.
- An operator message is appended near the end. The next request includes
  "Andrew just sent you this", and it stays in her view until a bulk trim
  passes it.

**She runs always.** There is no idle interval, no cost gate, no dampening:
the model is local, so there is no per-request spend to reason about. The
intent is continuous operation using roughly half the server's CPU, with the
LLM held to half only because matching threads to physical cores performs
better than oversubscribing them. The **only** reason she is not running is
her own explicit choice (§6, phase 3 — not in this phase).

Consequence: any unbounded-growth concern in this document is a *context*
concern (what fits in the window, what a trim costs in re-prefill), never a
spend concern. Do not introduce throttling to "protect" anything.

---

## 2. Phase 1 — the cutting block, and nothing else

Phase 1 is deliberately dumb: **history past the watermark is not sent.** It
stays in the database and is beyond her view until phase 2 gives her a say.

Phase 2 (after "continues endlessly" is demonstrated) adds compaction: a
"summarize what you want to keep" turn chosen by *her*. Phase 3 adds rest.
Neither is designed here beyond keeping them possible.

### 2.1 Turns outlive a run

Today every slice is `new Run` → `new LoopState(...)` with `exchanges`
starting empty. Continuity across slices is **zero**; the `## Memories` block
is the only carrier. That is the thing being removed.

New aggregate, keyed to the being rather than to a run:

| field | meaning |
|---|---|
| `id` | ordering is by this; strictly increasing |
| `author` | `operator` \| `being` \| `scheduler` |
| `role` | `user` \| `assistant` \| `tool` |
| `content` | unrendered, uncapped text |
| `tool_calls` | the assistant turn's calls, verbatim |
| `tool_results` | the tool turn's results, verbatim |
| `created_at` | when it happened |

`LoopState::$exchanges` stops being the source of truth and becomes a **window
onto this table's tail**. `toCheckpoint()` stops carrying it — which is a
simplification, not a cost: the checkpoint keeps the counters and the head,
and the transcript is read back by query.

One append function. Chat's write and a turn's write must land through the
same path or ordering and provenance drift into two truths again.

### 2.2 The head, and the watermark, move to the being

`promptHead` and `contextBase` leave `LoopState` and become being-level,
written **once**.

- **`promptHead` compiles once per being, not once per slice.** This kills B4
  (a fresh compile per slice with a fresh grounding timestamp, so nothing
  carries across slices). Grounding moves to the tail; the head is frozen for
  the being's lifetime.
- **`contextBase` becomes the trim watermark**, and is *persisted*. Today it
  is per-run and dies with the run, which is why the batching has never
  actually amortised across a session.

The batching arithmetic already exists and is correct: `ShedPlan::$batch`
sheds a whole batch down to `contextTrimTargetPct`. What was missing is a
home that survives the turn.

### 2.3 The invariant, stated as a testable rule

> **Between trims, the wire context changes only by appending.**

Two destroyers of that, both currently live in the code:

1. **The cap is applied at write, not at render.** `RunEngine` calls
   `context->capToolResult()` before appending the `ToolResult` event, so the
   *stored* value is already truncated (`…[truncated]`) and the rest is gone
   from the ledger. Andrew's requirement is explicit and repeated: results are
   **stored whole**, and the window renders a **capped view**. The cap must
   move to render time (`exchangeToMessages`). Until it does, "the database
   still has it" is false for exactly the large results that matter, and
   phase 2's compaction has nothing to compact from.

2. **A cap derived from a moving budget re-renders differently.** The render
   cap must be computed from a **frozen** basis (the limit and the knob),
   never from `contextTrimTargetPct` or the current retained size. Same row,
   same rendering, for as long as it is in the window.

3. **The head must be byte-identical between trims.** `trimTargetTokens()` is
   a percentage of the *whole* budget, head and tools included, so a head that
   changes moves the trim point. This is the same requirement as §2.2 seen
   from the other end.

A unit test should assert it directly: build the wire context across N turns
with no trim, and assert each request's message array has the previous one as
a prefix.

### 2.4 `stepBudget` stops being an ending

`RunEngine` §597 fails the run at `stepBudget` (default 50) with "budget
exhausted — the session stops for a human". For a being that is not a failure
mode; it is an arbitrary execution boundary on something that does not end.
The number survives as a checkpoint cadence if it is useful, and as nothing at
all if it is not.

### 2.5 The chain becomes a ring

A turn commits its successor in the same transaction — that contract stays
exactly as it is. What changes is what a **settle** means. Today a settled run
ends a chain. For a being, settling is the moment before the next turn, and
the trigger is her: finishing, having nothing more to do, and turning again to
look. "Continue" is the default; stopping requires an assertion (phase 3).

The heartbeat's job reduces to: **never let a being sit owed without a turn in
flight.** It already re-derives that on an interval; it needs the being's
turns to ride the same lanes.

### 2.6 Interleaving: your message waits

Agreed with Andrew. If she is mid-request, your message cannot be seen until
that request is done and the next one is dispatched with your message on it.

This is not a compromise on the goal — it is the goal. One stream means one
ordering, and "the turn already in the air finishes, then yours is next" *is*
an ordering. A turn compiled its message before you spoke, and cannot be
un-compiled without forking the stream.

### 2.7 Two blockers found while starting step 3

Both were found before any code moved. (a) is answered below and in the code;
(b) remains open and is a prerequisite for step 5.

**(a) One head cannot serve several tasks — and §2.2 assumes it can.**

The head is not decoration: it carries `## Task` (the title), the role note,
the `## Inputs` block, and the completion text. It is *task-specific by
construction*. But the stream it sits in front of is global, and
`TaskScheduler` loops `findRunnable()`, so more than one task can be due in the
same tick. A being-level singleton head would either (i) freeze the first task
that ever ran and serve it to every other task forever, or (ii) be recompiled
whenever the due task changes — which is the per-run compile we are trying to
remove, just renamed.

**Resolved by keying the head on the task, not by choosing the single slot §2.2
described.** `being.heads` is a map, and `BeingStore::headKey()` is
`kind:taskId`. A head is then frozen once *per task per kind* rather than once
per being — which is what "compiled once" has to mean when the compile is a
function of the task. The stream stays one stream, and the neighbourhood argument
in §2.5 is unaffected: her continuity is the stream, not the head.

That also settles the second half of the question. §2.2 was written as if "the
being" and "the run" were the only two aggregates; "the task" is the third, and
it is the one holding the content. It does not need a new aggregate, only a
name in the key.

**A second input turned out to matter: the grounding instant.** A session's
grounding is anchored rather than live (KV_CACHE.md §6.5) so that a slice's head
stays byte-identical across a session and only breaks when the day turns. Frozen
under a key without the anchor, the first slice ever compiled would be replayed
to every later slice — a session resumed tomorrow would be told today's date
forever, and nothing would ever raise a trim to correct it. So the key carries
the instant too, and the day boundary produces a new head exactly as it produced
a new compile before. Anchoring bought one cache break a day; a key that ignored
the anchor spends it once and then lies.
**(b) `StreamWriter` can hand out the same `seq` twice under `--parallel 2`.**

`nextSeq()` is `SELECT MAX(seq)+1` in a statement separate from the `INSERT`.
The old docblock argued that SQLite serialises writers so this could not be
lost between statements. It can, and it was reproduced: two connections read
`MAX(seq) = 1`, both decide `2`, the first commits, the second dies on
`UNIQUE_STREAM_EVENT_SEQ`. No silent corruption — the constraint does its
job — but a turn is lost to a constraint violation.

The fix cannot live in `StreamWriter`, because the collision only surfaces at
flush and the writer deliberately does not own the flush. It belongs with the
nexus that owns it — the dispatcher — as a bounded re-read-and-retry. It is a
**hard prerequisite for step 5**: ringing the chain makes concurrent
turns against one stream the normal case rather than the two-worker edge it is
today.

---

## 3. What falls away

These were answers to a problem that no longer exists. They should be
**removed**, not ported:

- **The `## Memories` block as continuity.** It existed because contexts could
  not persist. Under one stream it has no continuity job. What remains is
  operator steering, plus (phase 2) a home for compaction residue.
- **`SessionEndings` / the declaration vocabulary.** A stream with no slices
  has nothing to end, so `milestone`/`done`/`blocked` and the undeclared
  streak have no meaning. `SESSION_MAX_UNDECLARED` and the `continue`/`stop`
  choice go with them.
- **`SessionSliceEngine`'s launch/settle state machine.** Its job becomes the
  being-level "is a turn owed" derivation in §2.5 — much smaller.
- **Per-write caps sized for a memory note** (`SESSION_WRITE_MAX_CHARS`). A
  stream is not a 25-row store.

Andrew's current settings (`ENDINGS=milestone`, `UNDECLARED=continue`,
`MAX_UNDECLARED=3`) were the closest a session could get to forever. They
describe a compromise that §2.5 dissolves; with no endings at all, nothing to
declare and nothing to silently fail to declare.

---

## 4. Build order

1. **The stream table + `LoopState` reading its tail.** Everything else
   depends on turns outliving a run. Checkpoint stops carrying exchanges.
2. **Cap at render, not at write.** Small, and it is a prerequisite for phase
   2 having anything to compact. Also the first half of §2.3's invariant.
3. **Head + watermark on the being.** Freeze the head; persist the base. Makes
   the batching amortise. (B4 — the *slice* variant — was already killed at the
   task level by the grounding anchor, `KV_CACHE.md` §6.5; see §2.2's note.)
4. **Drop `stepBudget` as an ending.**
5. **Ring the chain**, and point the heartbeat at being-level owed turns.

Steps 1–3 are where the invariant either holds or quietly does not. A prefix
test (§2.3) belongs with step 3, not later.
---

## 5. Where the build order stands

Kept here rather than in a commit message, because the steps are not
independent: 2 and the test for 3 land first precisely to make 1's continuity
meaningful.

| step | state |
|---|---|
| 1. stream table + `LoopState` reading its tail | **landed** (`377cc81`) — `stream_event`, `StreamWriter`, `StreamWindow`; the checkpoint no longer carries exchanges |
| 2. cap at render, not at write | **landed** (`7ff0f71`, `1a16af1`) |
| 2b. the prefix test §2.3 asks for | **landed** — `ContextWindowAppendOnlyTest` |
| 3. head + watermark on the being | **landed** — `being`, per-task head key (incl. the grounding anchor, §2.7(a)), persistent watermark; (b) still a prerequisite for step 5 |
| 4. drop `stepBudget` as an ending | not started |
| 5. ring the chain; heartbeat on being-level owed turns | not started |

### Two things learned while landing step 1

**`LoopState::$exchanges` could not simply be deleted.** §2.1 says it becomes a
window onto the tail, and it does — but only *inside* `RunGraph`, which folds
the rows into exchanges once and passes them on. The name is gone rather than
kept as an alias, because two names for one value is how the drift this
document exists to prevent starts.

**Chat's transcript is the shape the stream must fit, not the reverse.** A chat
row is multi-role (`Participant` × `ChatEventType`, with `toWireMessages()`
rendering one row as several messages and classifications like
`startsWireUnit`), whereas §2.1's `role` is one of three strings. A chat turn
also carries provenance the stream has no field for — `origin`, and the frozen
toolbox declaration that records what the model could see. None of that is a
reason to widen the stream: it is a reason to land step 1 for runs **alone**
while the chat read path is still serving live conversations, and to decide the
chat write path as its own step with its own reader moved in the same commit.
Chat does not write to the stream yet.

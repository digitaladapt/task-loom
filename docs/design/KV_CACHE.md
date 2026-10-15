# KV cache reuse — design note

**Status:** living design note. **Verified live** against a real llama.cpp server
(2026-10-14): the mechanism is a ~95× latency difference; see §9. Section A has
landed (`stream_options.include_usage`, #72) and Section B1 shipped as chat grounding
**frozen** (§6.1); the rest of Section B and all of Section C are still to do.
**Scope:** the request-construction path only (`PromptCompiler`, `ContextWindow`,
`ChatContextWindow`, `ChatEngine`, `SessionMemoryRenderer`, `SessionSliceEngine`,
`LlmClient`). Proposes invariants, phased changes, and the instrumentation to prove
them; touches SPEC §5.6, §9, §15.9 and `SESSION_TASKS.md` §4.

> **⚠ §6.1 and §6.3 are SUPERSEDED (2026-10-14).** The measured follow-up
> (`KV_CACHE_POSITION_FINDINGS.md`) shows a *trailing* message is not free — it
> truncates the reusable prefix at the question for the rest of a conversation's
> life — and that a per-request-varying byte costs the same at the head and at the
> tail. So §6.1's "move grounding to the tail" does **not** rescue the clock, and
> §6.3's "move session memory to the tail" points the wrong way. Both are corrected
> in place below. The one fix that shipped is chat grounding **frozen** to a
> per-conversation anchor. §6.2 (Section C) and §7 (Section A) are unaffected.

> **One sentence.** The harness rebuilds a *changing* token prefix on almost every
> request — the chat system message carries a fresh clock, the run/chat window slides
> its front every turn once full, and a session's memory block mutates inside the
> never-pruned head — so a prefix-caching server (llama.cpp/Ollama `cache_prompt`,
> vLLM automatic prefix caching) can reuse essentially nothing, and every turn
> re-prefills the whole conversation on the critical path.

---

## 1. What a KV cache keys on, and why this project should care

A KV cache stores the attention keys/values for a token prefix. A server reuses the
cached computation for the longest **byte-identical leading run** of tokens between
the previous request and this one; the first differing token invalidates everything
from that point on. It is a *prefix* cache, not a similarity cache: change one byte
at position 0 and the entire request is recomputed.

Three facts make this unusually valuable here, and unusually cheap to get wrong:

- **The deployment is local-first and single-GPU.** `TASKLOOM_LLM_MAX_CONCURRENCY`
  defaults to 1 (`compose.yaml`, `.env.example`), so prefill and decode share one
  device and *serialize*. Re-prefilling 8k tokens is not hidden by another request;
  it is wall-clock a human waits on.
- **The prompts are large and mostly static.** The run head is a frozen constitution
  (SPEC §4.1); the conversation grows append-only. Exactly the shape prefix caching
  is built for — *if* the harness stops perturbing the front.
- **The model's window is small.** `TASKLOOM_CONTEXT_LIMIT` defaults to 32768, and
  the whole point of the windowing work (§5.6, §15.9) is that prompts approach it. A
  request that is 90% cacheable and 0% cached is the common case, not a corner.

The server side is already willing. `LlmClient` sends an OpenAI-compatible
`/v1/chat/completions` with `stream: true` (`src/Llm/LlmClient.php:154-163`); the
usual local endpoints enable prefix reuse by default. What follows is therefore
purely a statement about *our* request construction.

## 2. How to tell a cache-break from a cache-hit (the definition we will hold to)

A change is a **cache break** iff it alters a token *before* the last token of the
previous request to the same model+conversation/run. Equivalently: the longest common
prefix of consecutive requests stops growing.

Two consequences we will use throughout:

- **Appending is free**; **inserting, reordering, deleting, or mutating anything
  earlier is expensive.**
- The right unit of reasoning is *"where in the request does this content sit?"* —
  the head is the worst place (it invalidates everything after it) and the tail is the
  best (it invalidates nothing).

## 3. Today's two request shapes

**Run (and step child, and session slice) — `ContextWindow::buildMessages`:**

```
[system: prompt head]        never pruned (SPEC §5.6); contains grounding
[user:   task brief]         part of the head
[assistant: ## Memories]     session runs only — rebuilt EVERY request (§9)
… kept exchanges (oldest → newest) …
```

**Chat — `ChatContextWindow::fit`:**

```
[system: preamble + roster + grounding]   never trimmed (§15.9); grounding rebuilt EVERY request
… kept turns / tool rounds (oldest → newest) …
```

## 4. The breakers, enumerated

Each is stated with *where*, *why it breaks*, *what it costs*, and *whether it is
load-bearing*. Severity is relative to a local single-GPU deployment where latency is
the scarce resource.

### B1 — Chat grounding is re-rendered at position 0 on every request — **critical**

`ChatEngine::compileSystemMessage()` builds the system message *per reply*
(`src/Chat/ChatEngine.php:723-735`), and its last line is
`"## Grounding\n\n".$this->grounding->render()`. `Grounding::render()` prints
`Current time: HH:MM` (`src/Context/Grounding.php`).

The run path freezes its head once and stores it in the checkpoint
(`LoopState::$promptHead`, `RunEngine::begin`), so a run straddling midnight keeps a
stable head — a deliberate and correct decision. **Chat does the opposite, on
purpose** (a conversation resumed tomorrow should be grounded in tomorrow). But the
mechanism sits at **position 0**, so the moment the wall clock ticks past a minute,
the system message differs and the *entire* request — every turn of a long
conversation — is re-prefilled.

Measured, with the real classes (see §9 for the harness):

```
system @ 19:58:  … ## Grounding  Current time: 19:58 (UTC)
system @ 20:04:  … ## Grounding  Current time: 20:04 (UTC)
transcript otherwise byte-identical
common prefix across the two requests: 0 messages of 2
=> the entire request is re-prefilled on any minute boundary.
```

In practice a chat reply takes longer than a minute often enough that **chat almost
never reuses a prefix today**, even with a long, unchanged transcript behind it.

*Load-bearing?* The *freshness* is load-bearing (a resumed conversation must be
grounded in the resumed day). Its *position* is not. See §6.1.

### B2 — The window slides its front every turn once full — **high**

`ContextWindow::buildMessages` takes only the newest `windowTailExchanges` (default
10) as candidates — `array_slice($exchanges, -$this->windowTailExchanges)`
(`src/Context/ContextWindow.php:192`) — and the adaptive fit then drops from the
front. Once a run has more than 10 exchanges, **each new request re-slices the tail
and drops the oldest kept exchange**, so the front of the tail advances by one
exchange every turn.

Measured:

```
with 10 exchanges: first tail msg = c1
with 11 exchanges: first tail msg = c2   (common prefix with previous request: head only)
with 12 exchanges: first tail msg = c3
with 13 exchanges: first tail msg = c4
with 14 exchanges: first tail msg = c5
=> once 10 exchanges exist, the front of the tail moves every turn.
```

Every turn after the tenth pays a full re-prefill of the head *plus* the retained
tail — precisely the long runs (email digests, multi-step sweeps) this window was
written to keep alive. Chat has the same shape at `ChatContextWindow::fit`
(oldest-first whole-turn drop), bounded separately by `TASKLOOM_CHAT_WINDOW_TURNS`
(default 100, `src/Chat/ChatContextWindow.php:91`).

*Load-bearing?* The **bound** is load-bearing (the whole point of §5.6/§15.9). The
**sliding** is an implementation choice. A count bound that bites every turn and a
budget bound that bites occasionally are not the same thing; see §6.2.

### B3 — The session `## Memories` block mutates inside the never-pruned head — **high**

A session's `## Memories` block is rebuilt from the store on *every* request
(`RunEngine::sessionMemoryBlockFor`, `src/RunEngine/RunEngine.php:545`;
`SessionMemoryRenderer::render`), and `ContextWindow::buildMessages` inserts it
between the head and the kept exchanges as a fixed-cost tenant
(`src/Context/ContextWindow.php:163-171`). The model writes notes with
`session_note`/`session_objective` *during* the session, so the block grows and
changes mid-slice. Because it sits **before** every exchange, each write invalidates
the request prefix from that point forward.

*Load-bearing?* The per-request rebuild is load-bearing and deliberate
(`SESSION_TASKS.md` §4.1: an operator edit lands on the next request, no restart).
Its *position* is not. See §6.3.

### B4 — Every session slice is a brand-new head — **fixed (compounding B3)**

A slice is an ordinary `Run` (`SessionSliceEngine::createSlice`,
`src/Session/SessionSliceEngine.php:334`) with its own `compileForSlice()` head and a
*current* grounding timestamp. Across slices, the head differs (timestamp, and — if
the declaration vocabulary or toolbox changed — more), so **nothing carries over**:
each slice re-prefills its head from scratch even when the memory block it carries is
mostly the same text. A session that runs twenty slices pays twenty cold prefill
costs for essentially one conversation. This is B1's disease in the session lane.

**Fixed:** the slice engine anchors the session (`Task::groundingAt`, mirroring
`Chat::groundingAt`) and compiles each slice's head from that anchor
(`SessionSliceEngine::groundingInstantFor()` → `PromptCompiler::compileForSlice()`),
so slices within one deployment day compile a byte-identical head; the anchor moves
only when the day turns.

### B5 — Determinism of the parts that *do* repeat — **must-hold invariant, verify**

The following are cache-safe *today* and must stay so; they are cheap to break in
review and the breakage would be silent:

- **Tool-descriptor order.** The frozen toolbox resolves in a canonical order
  (server, then tool — `ToolboxResolver::resolveByTags`/`findAllOrdered`) and is
  stored in the snapshot, so the `tools` array `toolsToOpenAi()` emits is byte-stable
  across turns (`src/RunEngine/PromptCompiler.php:159`). Good — and load-bearing,
  because the tool array is part of the request that precedes the messages on some
  servers.
- **JSON encoding flags.** `LlmClient` uses one consistent encoder; `PromptCompiler`
  encodes tool-call arguments with fixed flags. Keep them fixed.
- **Never re-sending reasoning.** Prior `reasoning_content` is dropped
  (`exchangeToMessages`), which *helps* cache stability by keeping the assistant
  messages it does send stable.
- **Tool-result capping happens at write time.** `capToolResult` is applied when the
  result is stored (`src/RunEngine/RunEngine.php:1255`), not when it is re-sent, so a
  re-sent exchange is byte-identical to what it was. (Contrast `capInputArtifact`,
  applied per request — safe only because `$inputCount` is fixed per run.)

We will add a test that asserts the property rather than trusting it (§7, Phase 0).

### B6 — Not breakers (recorded so they are not "fixed")

- The run head's frozen grounding (B1's *opposite*, correctly done).
- `assistant.content` being `null` in tool-call turns — stable once committed.
- Duplicate-call dropping (`ToolCallPrimitives::dropDuplicateCalls`) — decided and
  frozen before commit, so the assistant message is stable thereafter.
- `TASKLOOM_PROMPT_BRIEF_IN_SYSTEM` / `_TOOLBOX_LIST` — deployment-wide, frozen per
  run; not per-request volatility.

### B7 — The run's steps do not share a head; each compiles its own — **medium-high**

Measured 2026-10-14 (§9.5). Every step of a run compiles its system message from
scratch, and the head opens with the *step's* title and brief (`compileSystem()`:
`## Task` → `Title: <step title>`), then the step's `## Toolbox`, then the deployment's
`## Completion`, then the run's `## Grounding`. For a same-level run the head is
byte-identical up to the preamble; from the step's own `## Task` onward it diverges.

The cost is only on the **first** round of each step, and it is real: a two-step run
measured 18.9% and 30.5% on the two steps' first rounds, against ~91% on their second
(§9.5). *This is not B2.* B2 is the growing-window slide (§6.2), which a short
multi-step run never reaches; B7 is head construction, in `PromptCompiler`. The two
want the same shape of fix — a frozen, shared prefix — but live in different code
paths. See §6.6.

*Load-bearing?* No — every step still runs correctly. It is cost, not correctness.

## 5. The tension, stated plainly

Cache reuse wants a prefix that **never changes**. The context budget wants a window
that **sheds** when full. These are not in irreconcilable conflict, but they are in
conflict whenever shedding is *incremental* (drop one unit per turn) rather than
*batched* (drop a lot, rarely):

- **Incremental shedding** = a cache break every turn, forever, once the window caps.
- **Batched shedding** = a cache break once, when the budget is first exceeded, then a
  long stable stretch — amortized to O(1) over the run.

The design lever is therefore *when* to shed and *how much at once*, not whether to
shed. This is the single most important idea in this note.

The mechanism, measured (§9.2, re-measured §9.4/§9.5): **changing bytes invalidate
from the change onward.** Incremental shedding rewrites a region of the prefix every
turn, so every turn re-pays prefill from the trim point; batched shedding rewrites it
O(1) times, so the prefix stays warm between trims. (An earlier reading blamed this on
a divergent request *overwriting the one cached prefix* — the eviction story is false
on this build, §9.4, but the conclusion is the same and now rests on the invalidation
itself.)

## 6. The design

Three principles, then the specific moves.

**P1 — Ephemeral content goes last, or goes once.** Content that must be fresh per
request (the clock, a resumed-session's grounding) belongs at the *end* of the
request, where a change invalidates nothing behind it — never at position 0 inside the
head.

**P2 — The prefix is frozen for as long as it is valid.** The head is already frozen
per run; extend that discipline to "the kept tail base", and make shedding batched so
the frozen stretch is long.

**P3 — Shed rarely, shed a lot.** Prefer a large, infrequent trim over a one-unit-per-
turn slide; the budget still fails closed, but the cache is broken O(1) times, not
O(turns) times.

### 6.1 Chat grounding → **freeze it** (fixes B1) — *corrected 2026-10-14*

**What shipped.** Freeze the grounding block to a per-conversation anchor, render it
as of that instant, and hold it byte-stable; move the anchor only when the deployment
day turns. The block **stays at position 0** of the system message — where it already
was — because *position was never the problem*: the block's first varying byte is at
the block, so a block that varies breaks the cache wherever it sits, and a block that
does not vary is free wherever it sits. Shipped in `feat/chat-grounding-freeze`:
`Chat::groundingInstant()` / `regroundAt()`, `Grounding::forNow()` / `calendarDay()`,
and `chat.grounding_at`.

**Why not "end of request", as first proposed.** Measured, 10-turn conversation
(`KV_CACHE_POSITION_FINDINGS.md`):

| grounding | clock | prefix reuse | prefill |
|---|---|---:|---:|
| in the head | advances (today's bug) | 28% | 167 s |
| in the head | **frozen** | **94%** | 30 s |
| trailing message | advances | 43% | 140 s |
| trailing message | frozen | 65% | 85 s |
| trailing, time-line only | advances | 67% | 74 s |

A trailing message *truncates the reusable prefix at the question* for the rest of the
conversation's life, so it costs ~30 points of reuse even when constant. Moving the
clock to the tail therefore recovers **none** of the B1 loss (28% → 43%), while
freezing recovers all of it (→ 94%). The tail trade is only worth considering if
continuous minute-freshness ever becomes a hard requirement, and then only as an
opt-in time-line-only tail (67%).

- **Freshness preserved.** A conversation is still grounded in the day it is resumed
  on: the engine moves the anchor when the deployment day (`Grounding::calendarDay()`,
  in the deployment's zone) turns. One cache break a day instead of one a minute.
- **Nothing about content, the head rule, or the budget changes.** Only the clock's
  *source* becomes an anchor rather than `now`.

### 6.2 Run/chat windows → batched shedding with a sticky base (fixes B2)

Replace "always take the newest N as candidates, then drop from the front" with a
**frozen tail base plus batched growth**:

1. Keep the retained exchanges in the checkpoint as they are, but **do not re-slice to
   the newest N every turn**. The retained set is a growing, append-only list whose
   *front is stable* until a trim is forced.
2. Trim only when the fitted request would exceed the budget — and then drop **a whole
   batch** (e.g. the oldest half of the tail, or enough to restore headroom of X%),
   not a single exchange. One re-prefill, then a long stable stretch.
3. Keep a **read/count bound** as a safety net, but make it bite *rarely* and in the
   same batched way, so it does not become a per-turn slide.

The `context_trim` ledger row already records kept/dropped counts
(`ContextWindowResult`), so batched shedding is *more* visible than the current
per-turn trim, not less — it will simply fire far less often.

*Decided 2026-10-14:* **trim to 50% of the budget.** One knob,
`context_trim_target_pct` (default 50), applied to both run and chat.

*Why a fraction of the budget, not "drop half the tail".* The front-drop re-prefills
the **retained** tail, not the dropped part: once the oldest exchanges go, the kept
ones shift and only the head survives as a cached prefix. So the cost of a trim is the
retained tail, and everything dropped above it is nearly free on that turn. If a single
large result trips the budget mid-run the tail can overshoot into a multiple of it, and
"half the tail" still lands at ~90% of budget — still full, so it trims *again* next
turn and pays a second re-prefill. A budget-relative target lands under the limit
whatever the overshoot. Since the trigger is the budget, so is the recovery.

*Why 50% and not more or less.* Averaged over a run growing `g` per turn, the
amortized re-prefill is `g · f / (1 − f)` for a retained fraction `f`: `1.0 · g` at
`f = 0.5`, `3 · g` at `f = 0.75`, `9 · g` at `f = 0.9`. The floor is modest and the
barrier is steep, so the lever is **trim rarely** — which is 50% (or less). Below ~50%
the curve flattens while the context given up keeps rising, so 50% is the knee. The
number is an arithmetic prior turned into a measurement at §9.6: on a 15-turn run the
50% target moves the front 2 times against the greedy path's 6 — three times fewer
re-prefills — and the knob is what lets a deployment move it without a code change.

### 6.3 Session memory → **leave it where it is; rebuild it only when it changes** (fixes B3) — *corrected 2026-10-14*

**Do not move the block to the tail.** Its position — a fixed-cost tenant between the
head and the kept exchanges, `[system][user brief][assistant memoryBlock][exchanges]`
— is *inside* the reusable prefix and measured **free**: a constant block there reused
93.2% of the prior turn's prefix, identical to folding it into the head. Moving it to
the tail would **cost** ~40–50%, for the same reason §6.1's tail move did: a trailing
message truncates the prefix at the question (`KV_CACHE_POSITION_FINDINGS.md`).

**The block is already pure, so "rebuild it less often" is a no-op — corrected
2026-10-14.** The premise was that re-rendering on every request (the §4.1 seam,
`RunEngine::sessionMemoryBlockFor`) is itself a cost. It is not: the rendered block is
a function only of the stored objective and notes — no clock, no counter, no ordering
drift — so an unchanged store re-renders it byte-for-byte. Pinned by
`SessionMemoryRendererTest::testTheBlockIsPureSoAnUnchangedStoreRerendersItByteIdentically()`.
The block therefore breaks the prefix **exactly when a write changes it**, and at no
other time; the every-request rebuild is free. So B3 needs neither a relocation nor a
rebuild cadence. **Measured on two slots (§9.3):** a write costs ~66 points of reuse
for the one slice that carries it — the block sits before the exchanges, so the change
invalidates everything behind it — and the session recovers to ~98% on the very next
slice. That is the cost the single-slot rig could not isolate from eviction; it is
real, bounded to one slice per write, and not a reason to move the block (moving it
to the tail measures the same ~66-point change *and* then costs ~65 points on the
next append — §9.3). B4 (below) is unaffected.

- **B4 (across slices):** fixed by freezing the grounding at the *session's*
  granularity (the day), not the slice's — see §6.5. Note it never involved moving
  memory: the slice head's volatile part is the **grounding timestamp**, and with it
  anchored, slice *n+1* reuses slice *n*'s head plus whatever memory prefix the two
  share.

### 6.4 What we will *not* do

- **No LLM summarization of context.** Reaffirmed (DESIGN_CONSIDERATIONS §2.3); cache
  reuse is an optimisation of what we already send, not a licence to change what we
  send.
- **No cache-aware "prompt surgery"** that drops the untrusted-content posture, the
  never-pruned head rule, or the fail-closed budget to make a prefix cacheable. The
  invariants in §5.6/§15.9 win over a latency win every time they conflict.
- **No server-side coupling.** The harness stays endpoint-agnostic; it only stops
  *preventing* reuse.

### 6.5 Session slices → **anchor the grounding to the session** (fixes B4) — *landed 2026-10-14*

A slice's head is re-compiled per slice, and the only volatile part of it is the
grounding timestamp — so a live clock made every slice a brand-new head, and the
whole session re-prefilled once per slice. The fix is the same one §6.1 shipped for
chat, applied one level up: **anchor the grounding** to an instant stored on the
session (the `task` row, mirroring `chat.grounding_at`), render every slice's block
as of that anchor, and move the anchor only when the deployment day turns.

So `SessionSliceEngine::createSlice()` resolves the session's anchor
(`groundingInstantFor()`: adopt the stored anchor; move it if the day changed; adopt
`now` for a task with none) and hands it to `PromptCompiler::compileForSlice()`, which
renders `## Grounding` with `Grounding::forNow($anchor)`. Within a day every slice's
head is byte-identical, so slice *n+1* reuses slice *n*'s prefix; across a day
boundary one slice pays the break and the rest are stable again. The freshness the
lane wants is preserved exactly as §6.1 preserved chat's: a session resumed tomorrow
is told tomorrow's date, at one cache break a day rather than one a slice. An
ordinary run is untouched — its head is compiled once and never re-compiled, so its
grounding stays the live clock.

### 6.6 Multi-step runs → a shared run prefix ahead of the per-step head — *next; needs design*

Measured (§9.5, B7). Each step's head opens with the *step's* own title and brief
(`## Task` → `Title: <step title>`), then the step's `## Toolbox`, then the run's
`## Completion` and `## Grounding`. Between steps only the preamble is shared, so each
step's first round re-prefills (measured 18.9% / 30.5%) even though each step's own
later rounds reuse ~91%.

The move is **B2-shaped but a different path**: hoist the run-invariant part of the
head above the per-step part, so step *n+1*'s head *extends* step *n*'s prefix instead
of restarting it. `PromptCompiler::compileSystem()` compiles one string from
`title`+`brief`+`tools`; the change is to split it into a run-owned part and a
step-owned part and concatenate them — invariant part first.

*Decision needed:* how far to hoist. The constraint is the cache itself — everything
shared by all steps must precede everything step-specific. The task title and brief
are run-invariant and are the natural shared prefix; the step's own title and brief
sit below it under a distinct header. Whether the run's `## Grounding` can also be
hoisted (it is already frozen for a run, §6.5) is part of the design, since §6.1 put
it last for a reason that may not bind here.

*Constraint:* the head rule and the budget path are untouched — this reorders head
sections, it does not change what the head says (§6.4).

*Depends on:* §6.2's sticky tail base (Section C). A run long enough to shed loses the
shared prefix regardless of where the step sections sit.

## 7. Instrumentation — prove it before and after (Phase 0)

We cannot manage what we cannot see, and today the ledger shows a prompt token count
but not how much of it was reused. Minimal, honest plumbing:

1. **Ask the endpoint for usage on streamed requests.** OpenAI-compatible streaming
   only emits `usage` when `stream_options.include_usage = true`; without it the final
   usage chunk never arrives. **Proven live:** the real `LlmClient` against llama.cpp
   returns `usage: []` (it sends `stream: true` with no `stream_options`,
   `src/Llm/LlmClient.php:157`), so `LlmDeltaAccumulator` records nothing and
   `cached_tokens` is invisible even though the server is caching at 99.5%. Add the
   flag. No behavior change beyond the final usage chunk.
2. **Record `cached_tokens` per LLM request.** `prompt_tokens_details.cached_tokens`
   is *already parsed* by `RunDigest::tokenSection` (`src/Admin/RunDigest.php:708`) but
   nothing populates it in practice because (1) is missing and the servers that report
   it are the ones we run. Surface it on the `llm_request`/`llm_response` rows so
   "how much of this run was cache" is in the ledger.
3. **A prefix-stability test.** A unit test that drives consecutive turns (run and
   chat) through the real compilers, captures the message arrays, and asserts the
   **longest common prefix length is non-decreasing** across a conversation/run
   except at an explicit, counted trim. This is the regression guard the design
   hinges on; it fails loudly for B1/B2/B3 without needing a real model.
4. **A cheap CLI/aggregate.** Extend the existing run digest with a `cache_reuse_pct`
   so a before/after comparison is one number.

Acceptance for every later phase: the prefix-stability test goes green and the
measured `cached_tokens` share rises, with no change to ledger vocabulary or
wire-shape semantics.

## 8. Build order — three sections, each independently shippable

Deliberately ordered so that *measurement precedes change* and no phase depends on a
later one. Each lands as its own branch/PR with its own tests, per AGENTS.md.

**Section A — measurement and invariants (no behavior change).**
`stream_options.include_usage`; per-request `cached_tokens` in the ledger; the
prefix-stability test; the digest's reuse figure. Ships alone and is what justifies
(or refutes) everything below. Its acceptance is measurable the day it lands: point a
live test at a llama.cpp endpoint (§9.2's rig) and watch `cached_tokens` appear in the
ledger where today it is invisible.

**Section B — de-volatilise the front.** *(complete 2026-10-14 — see §6.1/§6.3/§6.5)*
**Landed:** B1, chat grounding **frozen** to a per-conversation anchor
(`feat/chat-grounding-freeze`) — the biggest measured win, and it changed the clock's
*source*, not its position. **B3 needs no code:** the block is pure, so the
§4.1 every-request rebuild is already free and only a write breaks the prefix (§6.3)
— pinned by a test, and its invalidation cost now measured on two slots (~66 points,
one slice, full recovery — §9.3). **B4 landed:** the slice grounding is frozen to a per-session
anchor, so consecutive slices in a day share a byte-identical head (§6.5). No move of
any block to the tail was made — and §6.1/§6.3/§6.5 all record why that direction is
the wrong one.

**Section C — batched shedding.** *(landed — see §9.6)*
B2, run and chat: a sticky front (a frozen tail base carried in the checkpoint), batched
trims to the headroom target, and the count bound demoted to a stateless fallback for a
caller with no base. The front is an index into a run's exchange list and a wire event id
in chat, kept where the turn-to-turn progress already lives (`LoopState`,
`ChatLoopState`), so no schema changed. One new knob, `TASKLOOM_CONTEXT_TRIM_TARGET_PCT`
(default 50), documented in `.env.example`, `config/services.yaml` and both compose files
alongside `TASKLOOM_WINDOW_TAIL_EXCHANGES` / `TASKLOOM_CHAT_WINDOW_TURNS`. The §7
prefix-stability guard landed with it, at message granularity, as two unit suites
(`tests/Unit/Context/ContextWindowShedPlanTest`, `tests/Unit/Chat/ChatContextWindowShedPlanTest`)
and an end-to-end benchmark (`tests/Functional/RunEngine/RunEngineSheddingSmokeTest`).

**Section D — multi-step head sharing (§6.6, B7).** *Next after Section C.* The
run-invariant part of the head hoisted above the per-step `## Task`/`## Toolbox`, so
step *n+1* extends step *n*'s prefix instead of restarting it. Measured need: each
step's first round reuses 19–30% today (§9.5). It sits after C because a shedding run
loses the shared prefix regardless. Design not written yet — §6.6 has the shape and the
open question (how far to hoist).

Surfaces touched (for sizing, not as a plan): `ChatEngine`, `ChatContextWindow`,
`ChatWireWindow`, `ContextWindow`, `RunEngine`, `SessionMemoryRenderer`,
`SessionSliceEngine`, `PromptCompiler`, `LlmClient`, `RunDigest`, plus
`.env.example`, `config/services.yaml`, SPEC §5.6/§9/§15.9, `SESSION_TASKS.md` §4,
and tests under `tests/Unit/Context`, `tests/Unit/Chat`, `tests/Functional/Chat`,
`tests/Functional/RunEngine`, `tests/Functional/Session`.

## 9. Evidence — live, against a real llama.cpp server

Two independent lines of evidence, both reproducible.

### 9.1 Byte-level (no model): the breakers exist in our construction

B1 and B2 were reproduced with the real `Grounding` and `ContextWindow` classes
(probe script, not committed): a chat system message one minute apart shares **0 of 2**
messages of prefix; a run past ten exchanges advances its tail front by one exchange
per turn. The cache-break is a property of *our* request construction, assertable in a
unit test (§7.3) without a model.

### 9.2 Token-level (live llama.cpp): the break costs ~95× latency

Target: a llama.cpp OpenAI-compatible server, `--ctx-size 131072 --parallel 1`, model
`LFM2.5-3B-DavidAU-Turbo-Brilliance`, CPU-only (`--threads 2`). It reports
`prompt_tokens_details.cached_tokens` and a `timings` block when
`stream_options.include_usage` is set. Shape: short head + a 12-turn conversation.

| request | prompt_tokens | cached_tokens | reuse | prefill |
|---|---|---|---|---|
| cold | 833 | 0 | 0.0% | 29,629 ms |
| identical | 833 | 829 | **99.5%** | **318 ms** |
| **head clock changed 19:58 → 20:04** | 833 | **0** | **0.0%** | **30,133 ms** |
| append a turn, head fixed | 816→838 | 812 | **96.9%** | 1,848 ms |

**One number inside message 0 changes; the whole conversation behind it is
re-prefilled: 0.32 s → 30 s, a ~95× latency difference from content *position* alone.**
This is B1 on real hardware, and it is the entire argument for §6.1.

Two things the live run added that the byte-level work could not:

- **The harness is blind to all of it.** The real `LlmClient` returned `usage: []`
  (`stream: true`, no `stream_options`) while the server was caching at 99.5%. Fixing
  §7.1 is a prerequisite for measuring anything.
- **"One slot" does not mean "one cached prefix".** The note first read a ~36%
  after-divergence figure as: `--parallel 1` is one KV slot, and a divergent request
  *overwrites* the cached prefix. **Re-measured directly 2026-10-14 (§9.4): that is not
  what this build does.** A wholly different request between a prefix and its re-send
  costs nothing (control 97.4% vs test 97.1%, reproduced ×3), and six distinct
  ~1200-token prefixes all survive a 6168-token flush at `--parallel 1` (99.6% each).
  The server retains many prefixes; the bound is the byte/token budget, not a slot
  count. The ~36% figure is not reproduced on this endpoint — treat it as superseded
  until it can be re-shown.

  What survives, and is all §6.2 needs: **changing bytes invalidate from the change
  onward.** A request that appends to a warm prefix reuses it (a real step's round 2:
  ~91%, §9.5); a request that rewrites a prefix region re-pays from that point (a
  memory write: ~88 points, one slice, §9.3). "Append is cheap" therefore holds while
  the head is genuinely frozen, and fails at a *rewrite*, not at a divergence. §6.2's
  batch-don't-slide rule stands on that invalidation argument alone — which it now
  does, without the eviction story it was first given.

  A caveat is measured too: on a growing conversation an **append moves the last
  message's bytes**, because a final message validates in "generate" position and the
  same message mid-list does not. Appending past a trailing block costs ~65 points
  (§9.3); in the head/middle an append is ~97%. This is exactly the POSITION_FINDINGS
  rule "never add a trailing message to a growing conversation", and it is what makes
  §6.1/§6.3/§6.5 the right direction rather than "move it to the tail".

### 9.3 Two slots (`--parallel 2`): the numbers the single-slot rig could not give

**This is the re-run the §6.3/§8 notes asked for.** It ran while the endpoint was set
to `--parallel 2` (`total_slots: 2`, `n_ctx: 65536` each). Probes: replaying the real
engines' captured requests (`/tmp/kv_measure.py`), a two-prefix coexistence test, and
a direct MIDDLE-vs-TAIL comparison. Every figure is the server's own
`cached_tokens / prompt_tokens`.

> **Premise corrected (see §9.4).** This section explains the coexistence result by
two slots, and earlier explained the ~36% figure by one slot overwriting. The second
slot was real, but the *necessity* was not: re-measured at `--parallel 1`, this build
retains many prefixes on one slot too, and a divergent request does not evict. The
**B3 write cost** and the **MIDDLE-vs-TAIL table** below are unaffected — they are
about changed bytes, not slot count — and they reproduce. Only the coexistence framing
is superseded.

**Slots coexist (the premise).** Short shared head + two wholly different 12-turn
bodies; a unique flush first:

| request | reuse |
|---|---:|
| A (cold) | 0.0% |
| A | 99.6% |
| B (divergent) | 0.0% |
| **A (return, after B)** | **99.6%** |
| **B (return, after A)** | **99.6%** |

The second slot made both prefixes resident simultaneously. (Re-measured at
`--parallel 1`, §9.4: they are resident there too — this build retains many prefixes
on one slot — so the coexistence is not *why* §6.2 survives; the invalidation rule is
(§9.2).) Either way, a divergence's cost is the invalidation, not an eviction.

**B4, measured.** The real session probe (5 slices, no writes) now compiles a
byte-identical head per slice — all five report the same `Current time: 13:50` — and
the live server agrees: steady **99.7%** per slice after the first, overall **79.8%**
(one cold slice out of five). Before B4 the same probe reused only the shared head
across slices.

**B3, measured — the deferred number.** A mid-slice memory write, session shape
`[system][user][memory][exchanges]`:

| turn | reuse | prefill |
|---|---:|---:|
| warm (v1) | 99.3% | 0.34 s |
| **WRITE → v2** | **5.0%** | **21.5 s** |
| append +1 | 97.8% | 1.4 s |
| append +2 | 96.7% | 1.4 s |
| **WRITE → v3** | **11.6%** | 19.5 s |
| append +3 | 97.6% | 1.2 s |

So a write costs **~88 points on the slice that carries it** (≈20 s of re-prefill on
this CPU box) and the session **fully recovers on the next slice**. The baseline's
54–67%, and the real engine's 67% overall, are that same cost averaged across the
slices that follow a write — not eviction, and not a floor. This is B3's real bill:
bounded to one slice per write, and unchanged whether or not anything is written in
the slices that follow.

**MIDDLE vs TAIL — the counterfactual, priced honestly.** Same-length block, one
marker word changes between versions (an earlier probe grew the block, which made the
shapes incomparable; corrected):

| position | warm | CHANGE | append +1 | append +2 |
|---|---:|---:|---:|---:|
| block **MIDDLE** | 99.5% | 33.3% | **98.3%** | 97.5% |
| block **TAIL** | 99.5% | 33.6% | **32.9%** | 97.5% |

The *change* costs ~66 points in **both** positions — so moving the block to the tail
buys nothing for the write. What the tail changes is the *append*: past a trailing
block the next append drops to 32.9%, because a final message validates in "generate"
position and the same message mid-list does not, so appending moves its bytes. Both
shapes recover by append +2 (the new position is then warm). This is POSITION_FINDINGS'
"never add a trailing message to a growing conversation", now measured against the
exact block §6.3 was asked to relocate — and it confirms §6.3's decision to leave the
block where it is.

**Reproducibility:** the probes are not committed (they live under git-ignored
`var/` and `/tmp`), but the requests they replay are the byte-for-byte prompts
`ChatEngine`, `RunEngine`, and the slice engine build, and the figures are the
server's own `usage` and `timings`.

### 9.4 One slot, many prefixes — the §9.2 claim, refuted on the same build

Run with `--parallel 1` (`total_slots: 1`, `n_ctx: 131072`). Three independent probes,
each reproduced ×3 with byte-identical figures:

| probe | result |
|---|---|
| control: `PX1` cold → `PX1 + tool result` | 97.4% |
| test: `PX2` cold → **different prefix `PY2`** → `PX2 + tool result` | **97.1%** |
| retention: 6 distinct ~1200-token prefixes → 6168-token flush → re-send each | **6/6 hit (99.6%)** |

So on one slot a divergent request does **not** evict a warm prefix, and retention is
not one prefix but a token budget. (Prefixes also survive across probe processes.)
This is why §9.2's "one slot keeps one cached prefix" and §9.3's two-slot framing are
both superseded: the second slot was real but not load-bearing.

**Bound, stated honestly.** Retention is finite — some function of `--ctx-size` — so a
very wide multi-step task could still evict by sheer volume. A task with the handful of
steps in view here does not come near it, and no scheduling change can help the case
that does (the bytes would be over budget however they were ordered).

**One caveat not fully characterised.** On a hand-built prompt pair, the first send
after a divergence occasionally returned 0% and the *identical* re-send returned 99%
(`send2 0% → send3 99%`). The real compiled step heads never showed this (99.3% on
both sends, §9.5). So there is an occasional first-attempt flake on this endpoint that
is not a retention limit; it is noted, not relied on.

### 9.5 The real engine, two same-level steps — end to end

Multi-step reuse, measured through the **actual** `RunEngine` (not a replay): a task
with two steps at one level, toolboxes `echo` and `count`, run against the real MCP
server. The LLM client was pointed at a logging proxy in front of the endpoint, so the
figures are the server's own. Two orders were captured, both reproduced exactly:

*async path (production — `app:run:now --queue`, separate `llm` and `tools` workers):*

| # | request | prompt | cached | reuse |
|---|---|---:|---:|---:|
| 1 | `echo` round 1 | 636 | 120 | 18.9% |
| 2 | `count` round 1 | 742 | 226 | 30.5% |
| 3 | `echo` round 2 (after `count` R1) | 695 | 632 | **90.9%** |
| 4 | `count` round 2 | 808 | 738 | **91.3%** |
| 5 | final consumer round 1 | 705 | 120 | 17.0% |
| 6 | final consumer round 2 | 794 | 701 | 88.3% |

*sync path (`app:run:now`): the same numbers, ordered `step A R1 → A R2 → step B R1 →
B R2 → final R1 → final R2` — each child driven to completion.*

Three things this settles:

- **Each step's own rounds reuse (~91%).** `echo` round 2 is 90.9% *even though
  `count` round 1 ran in between* — so a sibling interposing in the single `llm` lane
  does not damage a step's cache. **The hypothesis that the current order "mucks up"
  the cache is refuted on the real engine.**
- **Cross-step reuse is cold.** `count` round 1 (226 cached) and the final consumer
  round 1 (120) reuse only a small shared head, because each step's head carries its
  own `## Task`/`## Toolbox` and its own tool descriptors. That is the multi-step cost,
  and it is a *head* problem, not an ordering problem — **B7 (§6.6)**, which is a
  different code path from B2 (the growing-window slide, §6.2).
- **A no-yield order would not help, and would cost wall time.** At
  `TASKLOOM_LLM_MAX_CONCURRENCY=1` the one `llm` worker takes the sibling's round 1
  while the first step's tool turn is on the `tools` lane, so the model is never idle.
  Finishing a step's rounds before starting the sibling leaves the model idle during
  each tool call and recovers no cache — strictly worse throughput for the same bytes.

**Reproducibility:** probes under `/tmp` (`tl_proxy.py` logs each request/response; the
seed and DB are throwaway). Not committed.

### 9.6 Batched shedding, measured without a model — the §6.2 knob

A third line of evidence, and the one that needs no server: a **deterministic**
benchmark of the real run engine against the real ledger, with the LLM and tool dispatch
stubbed at their interfaces. It is reproducible in CI, where llama.cpp is not.

The rig drives a 15-turn run (14 tool rounds, each returning ~600 chars) against a budget
the prompt head fits with ~1500 tokens of tail room, so the window is forced to shed.
The same run is played twice — headroom target 50% (what we ship) and 100%, where the
target is the limit so the batch can shed nothing extra and the behavior is the old
greedy "stop at the newest exchange that fits". The measured quantity is §7's: how often
the retained **front** moves, which is how often a prefix-caching server must re-prefill.

```
turns=15
  batched(50%):  trims=2  front-moves=2  reuse(median)=100.0%  reuse(min)=15.8%
  greedy(100%):  trims=6  front-moves=6  reuse(median)=100.0%  reuse(min)=15.8%
```

Three facts, and they are the design's claims:

- **The front moves only at a shed** (`front-moves == trims` in both columns). Nothing
  slides on a plain turn — that is B2, fixed.
- **Batching re-prefills three times less often** (2 vs 6). The 50% target sheds a whole
  batch per forced trim, so one re-prefill buys a run of stable turns; the greedy path
  sheds one exchange per trim and pays again almost every turn.
- **Most turns reuse the entire previous prompt** (median 100%). The dips to ~16% are the
  two trim turns, where the front legitimately moves.

*Scope, stated honestly.* `reuse` here is the longest common **message prefix** between
consecutive requests — the wire-shape quantity a prefix cache keys on — not the server's
`prompt_tokens_details.cached_tokens`. §9.2's token-level rig is still the live
confirmation, and the two agree by construction: the messages are the bytes the server
hashes. What this buys is a guard that runs everywhere, including CI, and fails loudly if
the front ever starts sliding again.

## 10. Open questions (need a decision before code)

1. **Grounding placement — RESOLVED, and re-resolved.** Superseded 2026-10-14 by
   `KV_CACHE_POSITION_FINDINGS.md`. The wire-shape half holds: on llama.cpp a trailing
   `system` after the user turn is *accepted*, so no endpoint constraint forces the
   question to be last. But the *pricing* was wrong: the earlier 82%-for-trailing
   figure came from a probe whose conversation was held still, so the trailing
   message was the only thing that changed. With a realistic growing conversation a
   trailing message costs ~30 points of reuse *by itself* (it truncates the prefix at
   the question), and moving the clock to the tail recovers none of B1 (28% → 43%).
   **The decision is to freeze grounding instead** (§6.1); the block stays at
   position 0. Still worth pinning the shape against vLLM/Ollama before relying on
   it, but nothing depends on the trailing shape any more.
2. **Session memory placement — RESOLVED.** Neither (a) nor (b): measured free where
   it is (§6.3), so it stays between the head and the kept exchanges, and the lever
   becomes rebuild-on-change rather than position.
3. **Batch size / headroom target** for §6.2 — **RESOLVED.** Trim to 50% of the
   budget; one knob, `TASKLOOM_CONTEXT_TRIM_TARGET_PCT`, run and chat (§6.2). The
   decision rests on the arithmetic in §6.2 (the amortized re-prefill `g·f/(1−f)` is
   ~1·g at 50%, ~9·g at 90%, so the lever is "trim rarely") and is confirmed
   deterministically at §9.6: on a 15-turn run the 50% target moves the front twice
   against the greedy path's six. The baseline (`KV_CACHE_BASELINE.md`) had found that
   the *naive* positional moves recovered nothing on the run or session shapes, and
   §9.5 had already shown the multi-step case is bounded by head divergence rather
   than scheduling — so the knob is set from the arithmetic plus the deterministic run,
   not from a live `cached_tokens` bar. The knob is *not* the lever for the multi-step
   case, which is a *different path*: the per-step head is (B7, §6.6), and no batch
   size fixes it.
4. **Cross-run reuse.** Should two consecutive runs of the same scheduled task share a
   cache? Today they cannot (fresh grounding timestamp each run). Out of scope for
   this note, but adjacent if scheduled tasks turn out to dominate prefill cost.
   *(Now answerable: the timestamps differ every run because the clock moves, so
   freezing a task's grounding to the day — as chat now does — would let consecutive
   runs of a daily task share their head.)*
5. **An opt-out knob.** Do we want a knob to *disable* any of this for an endpoint
   that punishes long shared prefixes (rare, but real for some cache-eviction
   policies)? Probably not for v1; recorded so the choice is deliberate.
6. **Multi-step head sharing (§6.6, B7) — open; needs a design note.** §9.5 measured
   the cost (~19–30% on each step's first round) and identified it as head
   construction, not scheduling: each step compiles its own `## Task`/`## Toolbox`
   ahead of everything shared. Hoisting the run-invariant head above the per-step head
   is the candidate move. The sequencing question is the one to settle first: §6.6
   *depends on* §6.2's sticky tail base, because a run long enough to shed loses the
   shared prefix wherever the step sections sit — so Section C (B2) still goes first,
   and §6.6 follows it. (An earlier drafting of §9.5 attributed this to B2; corrected
   2026-10-14 — B2 is the window slide, this is the head.)

---

*This is a proposal for discussion. No code has been changed; §8's sections are
intended to be reviewed and approved before the first branch is cut.*

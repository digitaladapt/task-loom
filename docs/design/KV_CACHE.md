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

### B4 — Every session slice is a brand-new head — **high (compounding B3)**

A slice is an ordinary `Run` (`SessionSliceEngine::createSlice`,
`src/Session/SessionSliceEngine.php:334`) with its own `compileForSlice()` head and a
*current* grounding timestamp. Across slices, the head differs (timestamp, and — if
the declaration vocabulary or toolbox changed — more), so **nothing carries over**:
each slice re-prefills its head from scratch even when the memory block it carries is
mostly the same text. A session that runs twenty slices pays twenty cold prefill
costs for essentially one conversation. This is B1's disease in the session lane.

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

A live detail sharpens it (§9.2): with the single slot a local server runs
(`--parallel 1`), one divergent request *overwrites* the one cached prefix. So
incremental shedding is worse than "no reuse this turn" — it repeatedly destroys the
prefix the next turn would otherwise reuse. Batched shedding is what keeps the slot
warm between trims.

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

*Decision needed:* how much to drop per batch (half? to a headroom target?). This is
the knob that trades prompt size against re-prefill frequency; it wants a measured
default, which is why Phase 0 instrumentation comes first.

### 6.3 Session memory → **leave it where it is; rebuild it only when it changes** (fixes B3, B4) — *corrected 2026-10-14*

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
rebuild cadence. What it still needs is a measurement this single-slot rig cannot
make — what a write's prefix invalidation actually costs, since the baseline's 54–67%
conflates it with the slot eviction. Deferred, not settled. B4 (below) is unaffected.

- **B4 (across slices):** unchanged, and still the largest win for the session lane —
  but note it no longer involves moving memory. The slice head's volatile part is the
  **grounding timestamp**: freeze it at the *session's* granularity (say, the day), not
  the slice's, and slice *n+1* reuses slice *n*'s head plus whatever memory prefix the
  two share. **Still to do.**

### 6.4 What we will *not* do

- **No LLM summarization of context.** Reaffirmed (DESIGN_CONSIDERATIONS §2.3); cache
  reuse is an optimisation of what we already send, not a licence to change what we
  send.
- **No cache-aware "prompt surgery"** that drops the untrusted-content posture, the
  never-pruned head rule, or the fail-closed budget to make a prefix cacheable. The
  invariants in §5.6/§15.9 win over a latency win every time they conflict.
- **No server-side coupling.** The harness stays endpoint-agnostic; it only stops
  *preventing* reuse.

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

**Section B — de-volatilise the front.** *(revised 2026-10-14 — see §6.1/§6.3)*
**Landed:** B1, chat grounding **frozen** to a per-conversation anchor
(`feat/chat-grounding-freeze`) — the biggest measured win, and it changed the clock's
*source*, not its position. **B3 needs no code:** the block is pure, so the
§4.1 every-request rebuild is already free and only a write breaks the prefix (§6.3)
— pinned by a test; what a write's invalidation *costs* wants a two-slot measurement
this rig cannot give. **Remaining:** B4, freeze the slice grounding at the
session/day granularity so consecutive slices share a head. No move of any block to
the tail remains in this section.

**Section C — batched shedding.**
B2, run and chat: frozen tail base, batched trims, rarely-biting count bound. This one
touches the budget path, so it goes last, behind the measurement from A and the
stability test from A as its safety net. New knob(s) documented in `.env.example` and
`config/services.yaml` alongside `TASKLOOM_WINDOW_TAIL_EXCHANGES` /
`TASKLOOM_CHAT_WINDOW_TURNS`.

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
- **A single slot keeps one cached prefix, not many.** `--parallel 1` is one KV slot:
  a divergent request *overwrites* the cached prefix. A request that appends to a warm
  prefix reuses it (96.9%); a request that diverges midway discards the tail and the
  next append then only finds the shared head (measured: ~36% after a divergent call,
  96.9% when nothing divergent sits in between). This is why §6.2 must *batch* sheds
  rather than slide: an every-turn slide is an every-turn divergent branch, which both
  breaks reuse and repeatedly overwrites the only slot. It also means "append is
  cheap" holds **only** while the head is genuinely frozen — appending *after* a
  head change reuses nothing (measured: 37.2%).

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
3. **Batch size / headroom target** for §6.2 — wants the Phase-0 numbers to choose.
   The baseline (`KV_CACHE_BASELINE.md`) found that neither of the *naive* positional
   moves (relocate the volatile section to the end of the same message) recovered
   reuse on the run or session shapes, while the controlled chat move did — so this
   knob wants a measured `cached_tokens` bar before it is set, not a guess.
4. **Cross-run reuse.** Should two consecutive runs of the same scheduled task share a
   cache? Today they cannot (fresh grounding timestamp each run). Out of scope for
   this note, but adjacent if scheduled tasks turn out to dominate prefill cost.
   *(Now answerable: the timestamps differ every run because the clock moves, so
   freezing a task's grounding to the day — as chat now does — would let consecutive
   runs of a daily task share their head.)*
5. **An opt-out knob.** Do we want a knob to *disable* any of this for an endpoint
   that punishes long shared prefixes (rare, but real for some cache-eviction
   policies)? Probably not for v1; recorded so the choice is deliberate.

---

*This is a proposal for discussion. No code has been changed; §8's sections are
intended to be reviewed and approved before the first branch is cut.*

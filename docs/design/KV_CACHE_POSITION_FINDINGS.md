# KV cache — where content may sit, and what it costs

**Status:** measurement note, 2026-10-14. No code changed. It **corrects
`KV_CACHE.md` §6.1/§6.3 and the Q1 resolution** (`KV_CACHE.md` §10.1, merged as
PR #74) against the same live endpoint the baseline used.

**Rig:** `llm-alt.devgnome.com`, llama.cpp `b11580` (OpenAI-compatible),
`--ctx-size 131072 --parallel 1 --threads 2`, model
`LFM2.5-3B-DavidAU-Turbo-Brilliance`. Every figure is `cached_tokens /
prompt_tokens` from the server's own `usage.prompt_tokens_details`, cross-checked
against `timings.cache_n`. The single KV slot is flushed (a request whose prefix
is unique garbage) before each shape, so a shape's first turn is honestly cold
and no shape inherits the previous shape's slot.

## Why this note exists

The baseline priced one move — "take the clock out of the head" — and the Q1
resolution read that as "a trailing grounding message is accepted and priced".
Both were measured on a *chat-shaped* probe where the conversation itself was
held still. This note varies the **position of the varying content** while
holding the conversation realistic, and the result is much sharper and partly
different: **whether a block may vary per request depends on which message it
lives in, and a trailing message is the most expensive place to put anything
that is not last-and-append-only.**

## The one law

> **A prefix cache is only as long as the earliest byte that changed.**
> Content that is *stable* can live anywhere — head, middle, or (for a while)
> tail. Content that *varies per request* must sit where a change costs the
> least, and that is **not** the tail.

Everything below is that law, measured.

## Part 1 — a second *leading* system message is free; the same content trailing is not

All shapes are `[messages…][user question]`; 10 turns; **nothing in the content
varies at all** (the added block is byte-constant), so the only difference is
*position*. This is the "session memory block" question (B3) and, indirectly,
grounding's.

| shape | cacheable prefix | turns 2+ median | prefill |
|---|---:|---:|---:|
| `[sys P]` … (no extra block) | — | **92.8%** | 28 s |
| `[sys P][sys CONST]` … | all 10 turns | **93.2%** | 36 s |
| `[sys P][asst CONST]` … | all 10 turns | **93.2%** | 29 s |
| `[sys P + CONST]` … (folded into the head) | all 10 turns | **93.2%** | 29 s |
| `[sys P]` … `[sys CONST]` (**trailing**) | only the head | **~50%** | 80–133 s |
| `[sys P]` … `[user CONST]` (**trailing**) | only the head | **39.5%** | 131 s |

Two results matter.

1. **A constant block is free wherever it sits, as long as it sits before the
   conversation spreads out.** The session-memory position
   (`[sys][user brief][assistant memoryBlock]`, exactly what
   `ContextWindow::buildMessages` does today) costs *nothing* to cache — the
   head, the brief, and the memory block are all inside the reused prefix. This
   is the strongest single finding for Section B: **B3's "move memory to the
   tail" is the wrong direction; the position is already the cheapest one.**
2. **A trailing message truncates the cacheable prefix at the question.** A
   trailing `system` (or `user`) message means the request no longer *ends* at
   `[…][user question]`; every later turn diverges at the question and reuses
   only the head. That is a permanent ~40–50% tax on every turn of a
   conversation, for a block that (*if constant*) did not need to move at all.

The trailing `system` number was noisy across runs (13.6% once, 65.3% once,
40.4% later) because a `system` message not in first position is an unusual
shape the server's slot handling does not special-case; the trailing `user`
number was stable at ~40%. Either way the honest reading is the same: **trailing
costs roughly half the reuse and buys nothing for a constant block.**

## Part 2 — the grounding clock: position does not matter, *variance* does

Same rig, clock advancing one minute per turn, conversation realistic.

| shape | clock | turns 2+ median | prefill | cacheable prefix |
|---|---|---:|---:|---|
| grounding **in the head** | advances | **28.1%** | 167 s | none |
| grounding **in the head** | frozen | **93.7%** | 30 s | all 10 turns |
| grounding in a **second leading system** message | advances | **44.6%** | 131 s | none |
| grounding in a **trailing system** message | advances | **43.0%** | 140 s | none |
| grounding in a **trailing system** message | frozen | **64.8%** | 85 s | head only |
| grounding trailing, **only the time line** | advances | **67.4%** | 74 s | head only |
| **no grounding at all** | — | **93.3%** | 28 s | all 10 turns |

The clock is the whole story. **Moving the clock from the head to the tail does
not rescue it** (28% → 44/43%), because a per-turn-varying byte is a per-turn
cache break wherever it sits: the server keeps only the prefix *before* the
first changed token, and the clock's first changed token is at the clock. The
merge already suspected this ("reuse is decided by byte position across the
whole request"); this confirms it directly.

**The only shapes that reach the ~93% ceiling are the ones where the clock does
not move.** Freshness via control flow, not freshness via re-render, is the only
lever that pays.

## Part 3 — the chat template is not the confound

The read of `chat_template.jinja` (both the deployment's
`KAT-Coder-V2.5-Dev` template and the demo model's) raised a worry:
`Qwen`-family templates render an assistant message differently depending on
whether it sits before or after the *last user query* (`loop.index0 >
ns.last_query_index` gates the ` thinking` block). That would make appending a
turn **retroactively re-render an earlier assistant message**, a cache break
with no content change at all.

It does not bite here, for a reason worth writing down: task-loom never re-sends
`reasoning_content` (`exchangeToMessages` drops it) and never stores thinking
tags in `content`, so both branches render an assistant message with an **empty**
` thinking`-block-or-not difference — and in a chat the assistant message that
would flip (the previous reply) is *already* on the "before the last query" side
by the time it is first sent. So the template's position-dependence is a
**latent** hazard (it would bite the day we start re-sending reasoning, or the
day a run's message list puts an assistant message after the last user turn),
not an active one. Recorded so a future change to reasoning re-sending does not
re-discover it the expensive way.

## What this changes in the design

**B1 — chat grounding.** Keep the *freshness* (the operator's point: the model
should know the time), but stop re-rendering it per reply. Recommendation:
**freeze the grounding block for the life of a chat exchange/answer**, or
re-render it **only when the deployment day changes** (date rollover), so a
long conversation crosses at most one cache break a day instead of one a minute.
If continuous minute-freshness is later judged worth it for Nia, the *only*
shape that keeps most reuse is a **trailing message carrying a short time-only
line** (~67%), never a full grounding block in the tail (~43%) — and it is still
a ~30% tax on an otherwise-94% conversation, so it should be opt-in.

**B3 — session memory.** Do **not** move the `## Memories` block to the tail.
Its present position — a fixed-cost tenant between the head and the kept
exchanges — is inside the cacheable prefix and measured free (93%). A **mid-slice
write** still invalidates the prefix behind it (the block changes) — but the block
is otherwise a **pure function of the store**, so the every-request rebuild that
the §4.1 seam performs is byte-identical and costs nothing to cache.

> **Corrected 2026-10-14.** An earlier draft of this section proposed "rebuild
> only when it changes". That is a **no-op**: the rebuild already only changes the
> bytes when the store changed, so there is nothing to make rarer. The
> determinism it rested on is now pinned by
> `SessionMemoryRendererTest::testTheBlockIsPureSoAnUnchangedStoreRerendersItByteIdentically()`.
> The block breaks the prefix exactly when a write changes it; what that write
> *costs* this rig cannot isolate from slot eviction, so it is deferred.

**B4 — session slices.** The cross-slice win is real and unaffected: with the
memory block (and, ideally, the grounding date) stable across slices, slice
*n+1* reuses slice *n*'s prefix. The slice head's volatile part is the
**timestamp**, so freeze grounding at the session granularity, not the slice
granularity.

**B2 — batched shedding** is unchanged and still correct: the law above is
exactly why *incremental* shedding (drop one exchange from the front each turn)
is the worst case, and *batched* shedding (drop a batch, rarely) is the fix.

**§10 Q4 (cross-run reuse of a scheduled task):** now answerable. Consecutive
runs of the same task share nothing because the head's grounding timestamp
differs; if a task's schedule is coarser than the clock's minute resolution
(always), the timestamps differ every run. Freezing grounding to the *day* (or
to the run's schedule slot) would let consecutive runs of a daily task share
their head. Cheap, if it becomes worth it.

## The measured basis, restated as a rule for review

1. **Stable content: position is free.** Keep it where it belongs
   semantically.
2. **Per-request-varying content: do not put it in the request at all if you
   can avoid it** (freeze it, or change it rarely). If you must vary it,
   the cost is the same at the head and at the tail — so put it where it reads
   best, and do not believe "the tail is free".
3. **Never add a trailing message to a growing conversation** unless the
   content genuinely belongs last: it truncates the prefix at the question for
   the rest of the conversation's life.

## Caveats

- One model, one hardware profile, one llama.cpp build, **one slot** in this
  note. The *qualitative* law (cache length = distance to the first changed
  byte) is server-independent; the exact percentages are not. **Re-run on two
  slots 2026-10-14** (`KV_CACHE.md` §9.3): the law holds, and this note's two
  load-bearing results survive — a mid-slice memory *change* costs ~66 points in
  both the middle and the tail (so re-locating it buys nothing, as §"B3" says),
  and appending past a *trailing* block costs ~65 points (so "never add a
  trailing message to a growing conversation" is confirmed, now against the exact
  block the note is about). What the second slot *removes* is the one-slot
  overwrite: a divergence no longer destroys the warm prefix (A → divergent B →
  A returns to 99.6%). See `KV_CACHE.md` §9.3 for the table.
- The trailing-`system` figure is the least stable measurement here (see Part 1);
  the trailing-`user` and the leading-position figures were reproducible across
  runs.
- vLLM/Ollama were not tested; `KV_CACHE.md` §10.1's caution to pin the wire
  shape against those dialects still stands, though this note removes the
  *reason* to want a trailing message in the first place.

## Suggested follow-ups

- A `KV_CACHE.md` §6.1/§6.3 revision reflecting the above, and a supersede
  banner on the Q1 resolution in §10.1.
- The Section A instrumentation (`stream_options.include_usage`, ledger
  `cached_tokens`, the prefix-stability unit test) is unaffected and still the
  right first code step; the prefix-stability test should additionally assert
  *"the request's message list never ends in a `system` message after the first
  turn"*.

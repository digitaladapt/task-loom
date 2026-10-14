# KV cache — "before" baseline

**Status:** measurement record for `docs/design/KV_CACHE.md` (Section A). No code
changes proposed here; this is the dataset that decides where the effort goes.

**How to reproduce:** `var/kvprobe/probe.php` drives the real engines with a
recording LLM stub and dumps each compiled request; `kv_measure.py` replays those
requests to a live OpenAI-compatible endpoint and reads
`prompt_tokens_details.cached_tokens` and the `timings` block. Both are probes,
not committed tests (the probe lives under git-ignored `var/`).

## The rig

| | |
|---|---|
| Endpoint | llama.cpp, OpenAI-compatible, `--ctx-size 131072 --parallel 1 --threads 2` |
| Model | `LFM2.5-3B-DavidAU-Turbo-Brilliance` (CPU-only) |
| Why | llama.cpp reports `cached_tokens`; a small model on CPU makes a cache hit vs miss a ~50× latency difference, so noise is small next to the signal |

Every figure below is **measured**, not modelled: the requests replayed are the
byte-for-byte prompts the real `ChatEngine` / `RunEngine` / session slice built.

## Headline

| case | requests | overall reuse | cold turns | prefill (s) |
|---|---|---:|---:|---:|
| **chat, 24 turns** | 24 | **93.2%** | 1/24 | 55.7 |
| **run, 0 step** | 1 | 0.0% | 1/1 | 17.2 |
| **run, 2 steps (same level)** | 3 | **0.0%** | 3/3 | 56.3 |
| **session, 5 slices (no memory writes)** | 5 | 80.3% | 0/5 | 46.1 |
| **session, memory written most slices** | 6 | **53.6%** | 0/6 | 145.0 |

## What each row means

### Chat — a healthy shape, and a loaded gun (B1)

Chat reuses **93.2%**, and that is real: the transcript is append-only, so once
the head is warm every later turn is ~95%. **But** this baseline is *optimistic
in a specific way*: a synthetic conversation runs in seconds, so the grounding
clock inside the head never ticks. In production a reply is minutes later.

Counterfactual — advance the head's `Current time: HH:MM` by 7 minutes per turn
(all else identical):

| chat, 24 turns | overall reuse | prefill (s) |
|---|---:|---:|
| clock frozen (measured) | 93.2% | 55.7 |
| **clock advances per turn** | **18.5%** | **449.0** |

**One moving number in message 0 costs 8× the prefill time.** This is B1, and it
is the single largest, cheapest win: freeze the clock (or move grounding past the
cacheable prefix) and chat stays near 93% instead of collapsing toward 18%.

### Run, 2 steps at the same level — the worst per-task cost (B2)

A two-step task issues **three** LLM requests and reuses **nothing** — 0.0% on all
three, 56.3 s of prefill for one task. Two sources, both in the *head*:

1. The `## Task` section carries the step's own `Title`/`brief`, and each step
   differs — the head diverges 276 characters in.
2. The `## Toolbox` section lists the frozen toolbox by name, and each step has a
   different one.

The per-step LLM requests are different questions with different toolboxes, so
this is not a "freeze one field" fix; it needs the volatile parts moved *behind*
the stable prefix (the §6 direction) — and even then the tool descriptors travel
with the request and complicate it. **Note the model's prefill is small here
(3 requests); the cost is that none of it is shared, and the same holds for any
multi-step task.** With longer step briefs or more steps it scales linearly.

A naive counterfactual (relocating the per-step `## Task`/`## Toolbox` text to
the end of the same message) did **not** recover reuse on this endpoint — a
reminder that prefix reuse is decided by *byte* position across the whole request
(head + tool definitions + messages), and relocating a section inside the system
message is not the same as relocating it behind the cacheable prefix. The §6
design must be validated against a measured `cached_tokens`, not assumed.

### Session — memory writes are the breaker (B3/B4)

- **No memory writes:** `## Memories` is constant, so all five slices reuse
  **99.7%** after the first (the 80.3% overall is the one cold slice).
- **A note written in most slices:** reuse drops to **54–67%** per slice and
  total prefill nearly triples (46 s → 145 s).

The cause is positional and confirmed: `ContextWindow::buildMessages` emits
`[system, user, assistant(memoryBlock), …exchanges]` — the memory block sits
**before** the exchanges, so a write invalidates everything behind it. This is
B3 exactly as `KV_CACHE.md` §6.3 describes it.

The obvious counterfactual (move the block to the end) measured **53.0%** — no
better — so the naive move is *not* sufficient on this endpoint either: like the
run case, the real win needs the write to land behind the whole cacheable prefix,
which is what §6.3(a) plus the reusable slice head (B4) is for. Recorded as
"not yet demonstrated," not as "done."

## Cross-cutting findings

- **Reuse is binary-ish and position-determined.** Baseline runs show 99.2–99.7%
  when the prefix is byte-stable and ~55–66% (or 0%) when anything early changes.
  The gap between "frozen" and "one thing moves early" is the whole prize.
- **A single-slot server makes it noisy.** `--parallel 1` is one KV slot: a
  divergent request *overwrites* the cached prefix, so the request after a
  divergence looks artificially good or bad depending on what ran before it.
  Multi-slot servers (`--parallel N`) would let several prefixes coexist; the
  qualitative conclusion (frozen prefix ⇒ reuse) is unchanged.
- **Grounding is not the only head clock.** The run path *also* renders grounding,
  but because a run is short and finishes inside one minute, its timestamp rarely
  changes mid-run — so runs escape B1 and hit B2 instead. Chat is the opposite.

## Where to focus (recommendation)

1. **B1 / chat grounding clock** — biggest measured effect (8× prefill), smallest
   change (freeze or reposition). Ship first.
2. **B3 / session memory writes** — large effect, well-understood cause,
   mid-size change.
3. **B2 / run heads** — 0% reuse today, but modest absolute prefill per task;
   the fix is entangled with tool descriptors, so it is the fiddliest. Do it
   behind a measured regression test.

## Additional tests worth adding before Phase C

- **A chat long enough to cross the turn bound** (`TASKLOOM_CHAT_WINDOW_TURNS`,
  default 100) — the baseline above never shed, so B2's chat side (the sliding
  window) is still unmeasured. Needs a seeded transcript, not 100 live replies.
- **A session whose memory block grows large enough to be trimmed** — exercises
  the "oldest notes dropped" path, another per-request head change.
- **A run with tool calls** — the tool-result feedback turn grows the message
  list *and* re-sends tool descriptors; likely the largest single-run prompt.
- **A multi-step task with a shared toolbox** — separates "the toolbox differs"
  from "the step brief differs" as the cause of the run head divergence.
- **Cross-run reuse of a scheduled task** — two consecutive runs of the same task
  (§10's open question 4); the grounding clock likely breaks it today.
- **A tokenizer-based unit replay**, so B1/B2/B3 can be asserted in CI without a
  live model: bundle a tiny GGUF tokenizer (or a fixed token table) and count the
  shared token prefix across turns. This is §7.3's prefix-stability test, made
  concrete.

## Caveats

- Magnitudes are model/hardware-specific; the **shape** (frozen ⇒ reuse, one early
  change ⇒ collapse) is what generalises.
- The synthetic runs finish inside a wall-clock minute, so B1 does **not** fire in
  the captures — it is demonstrated by the clock-advancing counterfactual, which
  is the honest way to show it without waiting real minutes.
- The run and session counterfactuals did not beat the baseline; they are reported
  as measured, and the §6 changes must clear a measured bar before shipping.

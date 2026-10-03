# Chat and Capacity

**Status:** design. Nothing here is built. Once built, the attribution
invariant and the chat aggregate would land as SPEC §15; the priority lane
as a §6.x note.

## 0. The two problems

We keep discussing two different things together, because one mechanism
can address both but they are not the same problem.

1. **Attribution.** When Nia and Andrew are both writing into the same
   conversation, the model must be able to tell whose words are whose.
   This is not a clarity nicety; §2 argues it is a safety invariant.

2. **Capacity.** Task work occupies the model and holds it long enough
   that a person cannot get a turn. The fix is not "more capacity" — there
   is one GPU — it is *priority*: a chat must be able to go first.

(2) is where "90% of the way there" is true, and it is worth saying
exactly which 90%: the *seam* exists. The *preemption* does not.

## 1. What is already true (grounding)

Verified against `main` (97d94f8), not assumed.

- **Two lanes, one table.** `llm` and `tools` are Doctrine transports on
  the same `messenger_messages` table, selected by `queue_name`
  (`config/packages/messenger.yaml`). Routing maps `LlmTurnMessage → llm`
  and `ToolTurnMessage → tools`.
- **The unit is one LLM call.** One delivery of `LlmTurnMessage` = one
  request = "the unit the `TASKLOOM_LLM_MAX_CONCURRENCY` semaphore is
  counted in" (its own docblock). N workers ⇒ at most N requests on the
  wire.
- **Concurrency is the worker count.** `docker/entrypoint.sh` spawns
  `LLM_WORKERS` processes running `messenger:consume llm` and
  `TOOLS_WORKERS` running `messenger:consume tools`. Setting the LLM
  worker count to zero is how one host points at a peer's model.
- **`messenger:consume` is strict-priority across receivers.** *Verified
  in `symfony/messenger`, `Worker::run()`:* it iterates `$this->receivers`
  in the order given and `break`s on the first one that handled an
  envelope. A worker squatting on several lanes drains them **in the order
  listed, every iteration**. This is the whole preemption mechanism (§4).
- **A withdrawn call is free to withdraw.** The pending checkpoint and the
  lane message are committed atomically; a delivery is judged against
  committed state *under the claim* (its `step` must still match), and a
  stale delivery is dropped. A call that never gets to run costs a
  re-dispatch, not a lost turn.
- **Fairness exists; priority does not.** A re-enqueued turn goes to the
  back of the lane (row-insert order) so a long multi-step run cannot
  starve others. That is FIFO. It stops starvation; it does not let a chat
  jump the line. Those are different properties, and only one is built.
- **The client is non-streaming.** `LlmClient::chat()` issues one POST to
  `/v1/chat/completions` and parses the whole body. There is no chunk loop
  to hang a cancel on. §4.3 deals with this.

## 2. Attribution

### 2.1 The invariant

**Every turn carries its speaker, and the model sees it.** Not "the system
knows" — the model is handed the attribution. Anything short of that is
the failure we are trying to prevent.

### 2.2 Why it is a safety invariant

Flatten a two-party transcript into one role and it breaks in both
directions:

- **Flattened to `user`:** Nia's own past output reads as Andrew's
  instructions. In a system where she holds tools, that is a
  prompt-injection surface grown inside her own history — text she once
  generated becomes text she now treats as directives.
- **Flattened to `assistant`:** Andrew's instructions read as her own
  prior words. She may not follow them, or may treat them as something she
  already decided.

When the two of you disagree, a flattened transcript has one speaker
holding both opinions, and the contradiction is silent. That is the "room
to misunderstand" — not ambiguity a reader can untangle, but a
contradiction with no author.

### 2.3 Two layers: what is stored, what is rendered

The distinction matters and should not be collapsed.

- **The turn record** is rich and durable: `speaker` (`andrew` | `nia`),
  `direction` (inbound/outbound — machinery, not meaning), `surface`
  (`ntfy` | `web` | `voice` | `cli` | `task`), `transport_ref` (the
  transport's own id — ntfy's message id, which we already rely on), `at`,
  and `reply_to`.
- **The prompt** renders attribution as **roles**: `andrew → user`,
  `nia → assistant`. This mapping is what tells the model whose opinions
  are whose, and it is the form a small model is actually trained on — so
  it is the most reliable mechanism, not merely the cheapest.

### 2.4 The roster

Generalize the two-party case to a **participant roster**: each
participant has an id, a display name, and a role mapping. v1 has exactly
two:

| participant | role        |
|-------------|-------------|
| `andrew`    | `user`      |
| `nia`       | `assistant` |

The roster goes in the system prompt ("the assistant is you, Nia; the user
is Andrew") so the role mapping cannot be misread from context.

**The schema does not assume two.** The day a third participant appears
(the reviewer pass; a second human; another persona), role alone stops
disambiguating — two `assistant` turns are still two speakers — and names
move *inside* the role: `Nia: …` vs `Reviewer: …`. Same mechanism, one
rung up. Building the roster now is what makes that an addition rather
than a rewrite.

### 2.5 You never type your name

Andrew types "morning". The system attributes it as a turn from Andrew. No
prefix is typed by the human; attribution is rendered by the pipeline.
This was the explicit constraint, and it is satisfied by putting
attribution at the *render* layer, not the *input* layer.

*(Decision locked: roles only, no visible per-turn name prefix, for two
participants.)*

## 3. Chat is not a task

A chat reuses the **infrastructure** and is a different **domain object**.

**Reused** — this is why chat is cheap to build:

- the message bus and its lanes;
- the **attempt ledger**: the same append-only record of what was tried,
  validated, and rejected. Debugging a chat turn should look exactly like
  debugging a task turn;
- `LlmClientInterface` and the prompt-compilation machinery.

**Not reused** — this is the box we are refusing:

- the `Task → Run → Step` graph. A task is a two-phase engine (a TASK
  phase produces JSON; a TOOL phase executes it) with budgets, step DAGs,
  strict fail-closed advancement, and justified completion. None of that
  is a conversation.
- `RunStatus` as the state machine. A conversation's states are not
  `queued|running|paused|succeeded|incomplete|failed|needs_attention`.

So: **`Chat` is a conversation of turns; `Run` is an execution of a task.**
They share the plumbing and nothing else. The failure mode to avoid is
jamming a conversation into a `Run` because the plumbing is already there.

## 4. Capacity: priority on the LLM

### 4.1 The unit and the mechanism

The schedulable unit is **one LLM call**, because that is what the
semaphore is counted in. So preemption is not "stop the task" — it is
**which lane the next available worker drains first**.

The mechanism is the smallest one that exists:

- add a `chat` lane, same table, `queue_name = chat`;
- give the LLM workers the **head** receiver: `messenger:consume chat llm
  tools`.

Because consume order is strict priority (§1), every existing LLM worker
becomes chat-aware with no new process, no lock, no async runtime. A chat
turn sitting in the `chat` lane is taken before any task turn the same
worker could have taken — and for *every* worker, since they are symmetric.
That is the mechanism, in full.

### 4.2 What "halt, run, resume" actually scopes to

Three sharpenings, because the slogan overstates the surface.

1. **Halt the LLM call, not the lane for the chat's whole duration.** A
   chat that goes quiet while Andrew thinks must not leave tasks halted.
   Rule: chat has priority on *each LLM call*; the moment that call is
   finished — or it is waiting on the human — tasks resume. Waiting for a
   human occupies nothing, so nothing needs to be held.
2. **The bound is one in-flight generation, per worker.** In v1 a chat
   cannot preempt a request already on the wire (§4.3). It waits out at
   most one generation *of one worker* — and if any worker is between
   turns, it does not wait at all.
3. **The tools lane needs no preemption.** While a task executes tools the
   LLM is free, so a chat's LLM call simply runs. Preemption only matters
   when a task is *mid LLM call* — a much smaller surface than "tasks are
   running". (Minor caveat: a chat's own *tool* call queues on the `tools`
   lane behind task tool calls. Low stakes, and you do not want to
   interrupt a side-effecting tool mid-flight anyway.)

The two-phase engine (`RunEngine`) is why this is small: the LLM lane and
the tools lane already interleave, so the task work that would block a
chat is exactly the LLM lane.

### 4.3 v1 versus v2: the streaming floor

Honest constraint: the model is a *reasoning* model, so a generation can be
tens of seconds. Yield-at-a-boundary caps chat latency at one generation,
and no amount of scheduling cleverness beats that. Sub-second preemption
has to come from cutting the generation short, which means a streaming
client.

- **v1 — no abort.** The chat call jumps the queue and at worst waits out
  the current generation. Nothing is cancelled, no compute is wasted, and
  correctness is untouched (a withdrawn call re-runs from checkpoint).
  **Do now:** widen `LlmClientInterface` with a cancellation/streaming seam
  (a cancel token plus an optional per-chunk callback) and keep the
  non-streaming implementation. The seam costs nothing today and makes v2
  an implementation, not a redesign.
- **v2 — streaming abort.** POST with `stream: true`, read chunks, and
  check the cancel token between them. Abort lands within ~one chunk
  instead of one generation. That is the "real-time" target. It needs a
  client-side cancel and partial-response handling, which is why it is
  second.

*(Decision locked: real-time is the goal; streaming abort is acceptable as
v2.)*

### 4.4 The single-worker case, and the reservation question

If `TASKLOOM_LLM_MAX_CONCURRENCY = 1` (one worker), a chat and a task
cannot run at the same time at all: the chat wins the *next* call but still
waits for the current one. Priority does not create a spare worker.
Whether v1 should **reserve ≥1 worker for chat** (N is the task budget;
chat may exceed it) is open — see §9. v1's answer is "N is the total; chat
just goes first", which is correct but means a single-worker deployment
still feels the current generation.

## 5. Alerts are not chat

Both are authored by Nia. **Identity stays single-sourced** — same author,
same voice. What differs is *whether the turn belongs in the transcript*.

- **Conversing** — a turn addressed to Andrew, expecting a reply. Enters
  the conversation.
- **Notifying** — "backup done", "service is down". Same author; must
  **not** sit in the transcript, or a chatty cron fills Nia's context with
  noise.

Notifying turns go to an **alert path** with the three properties asked
for:

1. **Destination control.** "A service is down" must not be buried under a
   hundred "email processed". So destination is a *policy*, not a
   constant: severity and source decide the channel. Critical → a channel
   that is looked at; routine → a digest or a log.
2. **Severity**, carried on the alert, so the policy can act on it.
3. **Coalescing / rate-limiting per (source, alert class)**, so a hundred
   email notifications are one line, not a hundred.

Relationship to existing machinery: a `needs_attention` run and the
run-surface attention queue (`AttentionGroup`, `RunDigest`) are *run state
surfaced in the UI*. An alert is an *outbound notification*. They are
different, and this doc should say how they meet: an alert may point at a
`needs_attention` run, but the UI queue is not the notification channel.

**Open (per the user):** an interactive `ask-user` turn — chat or alert?
The proposed line is **"does this turn expect an answer?"** If yes, it is
conversation (it belongs where the reply will be typed); if it only
reports, it is an alert. The line is *intent*, not origin — a task may emit
either.

## 6. Data model sketch (v1)

Infrastructure lives in the ledger; these are the new aggregates.

    Chat        id, title, created_at, updated_at
    ChatTurn    id, chat_id, seq, speaker, role, direction, surface,
                transport_ref, content, reply_to_id, at
    Alert       id, severity, source, subject, body, destination,
                dedup_key, delivered_at, at

Notes:

- `speaker` is an id (`andrew` | `nia`) validated against the roster;
  `role` is stored denormalized so a transcript renders without re-deriving
  config, and so a historical turn keeps the role it was rendered with even
  if the roster changes.
- The roster is **config in v1** (two fixed participants), not a table.
  A table is over-engineering until a third participant exists; the
  `speaker`/`role` split is what keeps that an addition later.
- `ChatTurn.role` is what the prompt compiler emits; nothing else in the
  prompt path needs to know the roster.

Messages:

    ChatTurnMessage   chatId, turnId          → chat lane

Same shape discipline as `LlmTurnMessage`: ids only, state in the row,
validated under the claim.

## 7. Integration points and risks

These are the places the change touches existing, load-bearing code. Each
one is a real risk, not a checklist item.

- **The boot sweep (SPEC §6.2) reasons over the fleet's shape.** The reap
  clears *every* claim, on the premise that no worker in this process group
  can be mid-turn — a premise that "covers only the lanes this fleet
  actually consumes" (`docker/entrypoint.sh`). **Adding a `chat` lane means
  the shape check must learn it.** A fleet that consumes `chat` has new
  claims in the table; a fleet that does not must decline the sweep for
  that lane, exactly as it already does for a peer-owned `llm` lane.
  Getting this wrong is the same "work done twice, live side effects and
  all" bug the lane-ownership rule exists to prevent. This is the
  highest-consequence integration point in the design.
- **Boot requeue must know owed chat turns.** The second half of boot
  recovery re-dispatches what abandoned claims are *owed*. A chat turn is
  owed in the same sense a run turn is; omit it and a chat hangs on
  restart.
- **`TASKLOOM_LLM_MAX_CONCURRENCY` now counts chat calls.** A chat can
  occupy the only worker. Intended — that is priority — but the operator
  must understand that capacity includes chat traffic, and a busy chat
  reduces task throughput. Say so where the variable is documented.
- **`redeliver_timeout` on the `chat` lane** must match the run lanes
  (7200s), for the same reason: it has to be able to take over a dead
  worker's claim.
- **Chat failures must be visible.** A task turn that throws lands in
  `failed` and the run shows it. A chat turn that throws must not die
  silently — the human is *waiting*. A failed chat call should produce a
  visible "I couldn't get a turn" signal, not a dropped message. There is
  no precedent for this in the run lanes; it must be designed, not assumed.
- **No Messenger retry on the chat lane either.** Same reasoning as the run
  lanes: the engine owns retry semantics and the ledger is the only source
  of truth. A retry strategy here would be a second, invisible one.

## 8. Phasing

1. **Attribution.** Turn records, the roster, role rendering. Stands alone;
   fixes the safety invariant even before chat exists.
2. **The chat lane as priority head** + the chat turn handler, reusing the
   loop and the ledger. No streaming. This is the "chat preempts tasks"
   milestone.
3. **Streaming client + cancel token** → true sub-second preemption.
4. **Alerts** — severity, destination policy, coalescing.
5. **Task sub-priorities** (a daily briefing above email processing). The
   lane scheme generalizes directly: `chat` > `llm_high` > `llm` >
   `llm_low`, drained in that order by the same workers.

*(Decision locked: v1 is chat + everything else; task sub-priorities are
later.)*

## 9. Open questions

1. **Reserve a worker for chat?** Is `TASKLOOM_LLM_MAX_CONCURRENCY` the
   task budget (chat may exceed it) or the total (chat competes and wins
   the queue)? v1 assumes total; a single-worker host argues for a
   reservation.
2. **`ask-user` destination** — chat or alert? Proposed rule: expects an
   answer ⇒ chat.
3. **Transport shape.** One ntfy topic per conversation, or a
   request/response pair? What does `transport_ref` correlate against on
   the way back?
4. **Which human?** The roster makes a second human an addition, but
   nothing yet decides *which* participant an inbound message is from. v1:
   one human, identity from config.

## 10. What this doc is not

Not a task. Not a `Run`. Not a second engine. The claim of this design is
that attribution and priority are both small changes *because* they are
placed at layers that already exist — the prompt compiler for §2, the
consume order for §4 — rather than at a new orchestration layer. If a step
of this turns out to need a new engine, the design is wrong and should be
revisited rather than pushed through.

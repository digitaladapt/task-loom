# Chat and Capacity

**Status:** design — settled, and **§8 steps 1 and 2 are built** (SPEC §15).
The attribution invariant, the chat aggregate, the exchange ledger, the
phone-first web surface, and the chat lane as priority head all shipped
together; this doc is now the record of why they are shaped the way they are,
not a plan. **§8 steps 3–6 are not built**: the streaming client and cancel
token (§4.3), alerts (§5), initiation (§9), and task sub-priorities.

The one promise this doc made that turned out to depend on something else:
at the default `N=1` (§4.4), queue priority buys "chat goes next", and the
measurement that matters — how long a person actually waits — is one in-flight
generation. If that proves too slow in use, the streaming work in §4.3 is the
fix, and nothing about the aggregate or the lane has to move for it.

The open questions from review are answered (§10.4 lists them); what remains
open is listed in §10 — of which §10.2 (an unbounded transcript and the
context policy it needs) is now the live one, because the conversation it
warns about exists.

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

One fact reframes the capacity half, so it is stated up front:
**`TASKLOOM_LLM_MAX_CONCURRENCY` defaults to 1, and it means the total
in-flight count.** The single-worker host is not an edge case to be handled
at the end — it is the default deployment, and at one worker queue priority
alone cannot deliver a real-time chat (§4.4).

There is a third thing this doc settles that is neither attribution nor
capacity — the **shape of the chat aggregate** (§3.3). It is not a
sub-problem of the other two; it is the thing the first line of chat code
is written against, so it goes first.

## 1. What is already true (grounding)

Verified against `main` (97d94f8), not assumed.

- **Two lanes, one table.** `llm` and `tools` are Doctrine transports on
  the same `messenger_messages` table, selected by `queue_name`
  (`config/packages/messenger.yaml`). Routing maps `LlmTurnMessage → llm`
  and `ToolTurnMessage → tools`.
- **The unit is one LLM call.** One delivery of `LlmTurnMessage` = one
  request = the unit `TASKLOOM_LLM_MAX_CONCURRENCY` is counted in (its own
  docblock). N workers ⇒ at most N requests on the wire.
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
- **The run aggregate is task-bound.** `Run.task_id` is **not nullable**
  and `Run::__construct()` takes a `Task`. `Run` also carries the step
  graph (`role`, `parent`, `step`), the toolbox snapshot, the claim token
  (`lock_version`, `claimed_at`, `claim_fleet`), and the crash checkpoint.
  §3.3 turns on this.
- **`RunEvent` is nearly generic; `Run` is not.** `RunEvent` is
  `seq, type, created_at, payload, error_class, attempt_no, duration_ms` —
  nothing run-specific except the FK. `RunEventType` is a closed run
  vocabulary (`llm_request`, `tool_call`, `checkpoint`, `completion`, …).
  `RunRepository` exposes nine read paths (`findQueued`, `findActive`,
  `findAttention`, `findRecent`, `findForTask`, `findLatestForTasks`,
  `findActiveTopLevelFor`, `findChildren`, `errorClassRollup`) and five
  other consumers read the table (`RunDigest`, `RunSurfacePresenter`,
  `RunController`, `Mcp/Server/RunReviewTools`, `RunRequeueCommand`).

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
  `role`, `origin` (the surface it came through — config-resolved, §9),
  `content`, `reply_to_id` (intra-conversation threading), `at`. Direction
  is derivable from `speaker` given the roster and is not stored; nothing
  transport-specific is stored at all (§9).
- **The prompt** renders attribution as **roles**: `andrew → user`,
  `nia → assistant`. This mapping is what tells the model whose opinions
  are whose, and it is the form a small model is actually trained on — so
  it is the most reliable mechanism, not merely the cheapest.

**Where these fields live.** Because §2.1 is a safety invariant, the
attribution fields are **typed columns**, not entries in an untyped JSON
payload — a safety invariant should not live in a blob. That constrains the
aggregate shape (§3.3): whichever table holds a conversational turn holds
`speaker`/`role`/`content` as real columns.

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

## 3. Chat is not a task — but it is shaped like one

A chat reuses the **infrastructure** and is a different **domain object**.

**Reused** — this is why chat is cheap to build:

- the message bus and its lanes;
- the **attempt ledger**: the same append-only record of what was tried,
  validated, and rejected. Debugging a chat turn should look exactly like
  debugging a task turn;
- `LlmClientInterface` and the prompt-compilation machinery;
- the **claim / checkpoint / requeue machinery** — the durable underscore
  that makes a half-finished turn survive a restart. A chat needs this
  *more* than a task does, because a human is waiting on the other end.

**Not reused** — this is the box we are refusing:

- the `Task → Run → Step` graph. A task is a two-phase engine (a TASK
  phase produces JSON; a TOOL phase executes it) with budgets, step DAGs,
  strict fail-closed advancement, and justified completion. None of that
  is a conversation.
- `RunStatus` as the state machine. A conversation's states are not
  `queued|running|paused|succeeded|incomplete|failed|needs_attention`.

### 3.1 The shape (agreed)

Follow the run model, one rung down:

> **An inbound message is the trigger. Everything from that message until
> the reply concludes is one exchange. Each thing that happens inside it
> is one typed event.**

So there are three nouns, and the mapping is direct:

| task world    | chat world          | what it is                             |
|---------------|---------------------|----------------------------------------|
| `Task`        | `Chat`              | the durable thing being worked on       |
| `Run`         | `ChatExchange`      | one execution, triggered, claimable     |
| `RunEvent`    | `ChatExchangeEvent` | one typed row in the attempt ledger     |

The `ChatExchange` is the run-analog; treating "sending a message" as one
execution is exactly the mental model the run engine already implements.
(If `ChatExchange` reads awkwardly, `ChatRun` is a fine alias — but the
decision below is about the *tables*, not the name.)

### 3.2 Restated as a rule

**The exchange is a run-shaped execution over a conversation instead of a
task.** That is the whole relationship. It is not a `Run`, and it is not a
`Task`; it is the same *idea* applied to a different domain object.

### 3.3 Table reuse vs duplication — the decision

This is the question worth spending care on, so here is the grounding
rather than a preference.

#### Option A — reuse `Run` + `RunEvent` for chat

**Pros.**

- No new tables.
- Claim, checkpoint, boot-sweep, requeue, and the ledger all work as-is;
  "debug a chat like a task" is literally true.
- One place to learn "why did the model see this".

**Cons — each one is a real bug, not a style objection.**

1. **`Run.task_id` is NOT NULL and `Run::__construct()` takes a `Task`.**
   Every chat exchange therefore needs a synthetic `Task` — an "Internal:
   Chat" row that is not a task. That row then appears in
   `TaskAdminController`, in `findLatestForTasks`, in `findForTask`, and
   in the scheduled-task machinery. A lie at the centre of the schema.
2. **`findRecent()` and `findAttention()` filter on `parent IS NULL`
   only.** A chat exchange has no parent, so it **will** surface in run
   history and in the attention queue — the queue whose entire job is "a
   run needs a human". A chat is not a run that needs attention. Every one
   of the nine repository reads plus `RunDigest`, `RunSurfacePresenter`,
   `RunController`, and `RunReviewTools` needs a discriminator filter.
   Filters that are forgotten fail *silently*, as wrong rows on a page.
3. **The terminal states do not fit.** `markSucceeded` /
   `markIncomplete` / `markFailed` / `markNeedsAttention` / `settleAs`
   and `isTerminal()` encode "justified completion, never a bare step
   complete" (SPEC §5.4). A reply is not a justified completion.
4. **`RunRole` is step-graph vocabulary.** `standalone|parent|step|
   final_consumer` — all four meaningless for a chat; `executesTurns()`
   becomes a lie.
5. **Cascade semantics.** `Run` is the FK target of `ToolCall` and part of
   an `onDelete: CASCADE` graph. Deleting a chat would reach into run
   machinery.
6. **SPEC §5.3 stops meaning one thing.** "The transcript in the DB is the
   full, honest record" would describe runs *and* chats, and the doc that
   defines the ledger would need a second definition inside it.

#### Option B — duplicate the shape, factor the machinery (recommended)

**Pros.**

- No synthetic task; no discriminator filters; the run history, attention
  queue, and digest are untouched and cannot regress.
- Chat owns its state machine (`queued|running|failed` — whatever chat
  needs), so run's completion semantics stay honest.
- Chat transcripts are long-lived and private; purging a chat is a local
  delete with no cascade into the run graph.
- SPEC stays readable: §5.3 = the run ledger, §15 = the chat ledger.

**Cons.**

- The claim / checkpoint / boot-sweep / requeue *logic* has to be
  re-expressed for chat, or factored so both use one implementation.
- Two schemas to keep in step when the machinery changes.

**The mitigation is the actual recommendation: share the *machinery*, not
the *tables*.**

- The two event tables already have the same layout (§1). Make that
  structural with a Doctrine `MappedSuperclass` (or trait) for the ledger
  row — `seq`, `type`, `created_at`, `payload`, `error_class`,
  `attempt_no`, `duration_ms` — that both `RunEvent` and
  `ChatExchangeEvent` extend. Layout identity becomes a compiler fact
  rather than a convention two authors have to remember.
- Define an interface for the claim/checkpoint surface
  (`Claimable`: claim, release, status, checkpoint) that both `Run` and
  `ChatExchange` implement, and write `requireRun`/`claim`/reap/requeue
  **once** against the interface. That is what keeps the "two schemas to
  keep in step" con to one implementation, not two.
- If chat later gains tools, `ToolCall` hangs off the shared event base,
  so it serves both ledgers without a second table.

#### What is deliberately *not* shared

- **The state machine.** `RunStatus` is task-specific; a chat's states are
  its own. Sharing the enum would drag `paused`/`needs_attention`/
  `incomplete` into a domain with no use for them.
- **The domain graph.** `Task`, `Step`, `RunRole`, `toolbox_snapshot` do
  not cross the line. The `Chat` is a conversation, not a task.

#### Rejected: one polymorphic `ledger_event` table

A single event table with `task_id` / `chat_id` both nullable and a
discriminator. It looks like the DRYest option and is the worst: every
query needs a discriminator, the nullable-FK-and-CHECK shape is the
synthetic-task problem spread across two columns, and it is a *bigger*
migration than either option above while touching the run ledger's
hot path. Duplicating the shape is cheap; merging the tables is not.

#### Why this is consistent with §11

§11 says a step that needs a *new engine* means the design is wrong. This
is not a new engine — it is the *same* engine (claim, checkpoint, ledger,
priority lane) applied to a second aggregate behind an interface. The
engine gets one new caller, not one new implementation.

## 4. Capacity: priority on the LLM

### 4.1 The unit and the mechanism

`TASKLOOM_LLM_MAX_CONCURRENCY` is the **total** number of requests that may
be in flight to the model at once — not a per-lane budget, not a task-only
budget. It is the same quantity as the model server's own parallelism: a
llama.cpp with `parallel=1` *rejects outright* any request that arrives
mid-generation, so the fleet's in-flight count has to match what the server
will actually accept. One worker is the fleet shape that matches a
single-slot server.

The schedulable unit is therefore **one LLM call**, because that is what the
semaphore is counted in. So preemption is not "stop the task" — it is
**which lane the next available worker drains first**.

The mechanism is the smallest one that exists:

- add a `chat` lane, same table, `queue_name = chat`;
- give the LLM workers the **head** receiver: `messenger:consume chat llm`.

The tools workers are untouched — the fleet already splits the two kinds
(`LLM_CMD` consumes `llm`, `TOOLS_CMD` consumes `tools`), so a worker that
runs the model does not also run tools, and a slow tool cannot block a
chat. (A single worker *could* be given all three lanes; the fleet does
not, and matching the fleet keeps the change to one line per worker type.)

Because consume order is strict priority (§1), every existing LLM worker
becomes chat-aware with no new process, no lock, no async runtime. A chat
turn sitting in the `chat` lane is taken before any task turn the same
worker could have taken — and for *every* worker, since they are
symmetric. That is the mechanism, in full.

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
  **Do now:** widen `LlmClientInterface` with a cancellation/streaming
  seam (a cancel token plus an optional per-chunk callback) and keep the
  non-streaming implementation. The seam costs nothing today and makes v2
  an implementation, not a redesign.
- **v2 — streaming abort.** POST with `stream: true`, read chunks, and
  check the cancel token between them. Abort lands within ~one chunk
  instead of one generation. That is the "real-time" target. It needs a
  client-side cancel and partial-response handling, which is why it is
  second.

*(Decision locked: real-time is the goal; streaming abort is acceptable as
v2.)*

### 4.4 The single-worker case — which is the default

`TASKLOOM_LLM_MAX_CONCURRENCY` defaults to **1**, and it means the *total*
in-flight count (§4.1) — a decision, not a gap to be filled later. There is
no "reserve a worker for chat": with a single-slot model server there is no
spare slot to reserve, and the count has to match what the server will
accept. So at the default:

> chat latency under priority alone ≈ one in-flight generation, every time.

For a reasoning model that is tens of seconds — not "real-time", and no
scheduling change fixes it. The levers, in order of honesty:

1. **Streaming abort (§4.3) is the real fix** at any worker count, and the
   *only* fix at N=1. At the default this is not a nice-to-have; it is the
   difference between the feature working and not. The phasing in §8 puts
   the client seam in v1 for this reason.
2. **More workers help only if the server can serve them.** Raising N lets
   a chat run on a free worker *while* a task's generation is in flight —
   but on a `parallel=1` server that second request is rejected outright,
   so the chat waits exactly the same and the extra workers just log
   errors. "Increase the worker count" is only a lever when the model
   server can actually accept the parallelism.

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

**The channels already exist, and they are not the chat.** Alerts are
delivered by whatever is reliable, and the reliability is already known:
ntfy push is *moderately* reliable, which is exactly why **Discord** is
also in use for alerts. The alert path is therefore a *fan-out over
existing channels* (Discord, ntfy, digest, log) selected by policy — it is
not a new transport and it is not the chat surface. A chat is a place you
go; an alert is a thing that reaches you.

Relationship to existing machinery: a `needs_attention` run and the
run-surface attention queue (`AttentionGroup`, `RunDigest`) are *run state
surfaced in the UI*. An alert is an *outbound notification*. They are
different, and this doc should say how they meet: an alert may point at a
`needs_attention` run, but the UI queue is not the notification channel.

**Deferred: interactive `ask-user`.** The original plan for `ask-user` was
a turn that hands back a *link to a web-based interaction*. That work is
**deferred** — the interaction model itself is under reconsideration, so
pinning a destination for it now would be designing against a guess. The
classification line above is what the eventual `ask-user` slots into: an
outbound turn that expects an answer is conversation, one that only reports
is an alert.

## 6. Data model sketch (v1)

Infrastructure lives in the ledger; these are the new aggregates (§3.3,
Option B).

    Chat               id, title, created_at, updated_at
    ChatExchange       id, chat_id, triggered_by, trigger_turn_event_id,
                       status, checkpoint, lock_version, claimed_at,
                       claim_fleet, started_at, finished_at, error_class, at
    ChatExchangeEvent  id, exchange_id, seq, type, created_at, payload,
                       speaker, role, origin, content, reply_to_id,
                       error_class, attempt_no, duration_ms

`ChatExchange` is the run-analog; `ChatExchangeEvent` is the run-event-analog
and shares the ledger row's layout with `RunEvent` through a
`MappedSuperclass` (§3.3).

Notes:

- **Attribution is typed.** `speaker`, `role`, `content`, `reply_to_id` are
  real columns on `ChatExchangeEvent` (nullable; populated for the
  conversational event types `message` and `reply`). §2 is a safety
  invariant, so it does not live in the `payload` JSON (§2.3). The
  machinery events (`llm_request`, `checkpoint`, `failure`, …) leave them
  null and use `payload` exactly as `RunEvent` does.
- **The transcript is a filtered read — there is no `ChatTurn` table.**
  The ordered `ChatExchangeEvent` rows whose type is conversational, across
  the chat's exchanges. A turn *is* an event here, which is what keeps the
  model to the three nouns in §3.1.

  **Decision: no separate turn table.** There is no benefit to a fourth
  table when the event row already holds `speaker`/`role`/`content` as typed
  columns — a second table would be a duplicate of rows that already exist,
  and a second thing to keep in step. (§2.3 is why those columns are typed,
  so the transcript read is a plain indexed query, not a JSON scan.)

  *When this would change, and why it stays cheap:* promote a `ChatTurn`
  table only if the conversational rows outgrow the event row — per-chunk
  persistence (§10.1) is the plausible trigger, since a partial response has
  no natural `seq` in the ledger. That is a **projection over these rows,
  not a migration of them**: the ledger keeps every event, so a `ChatTurn`
  table would be built by reading and reshaping, with the events remaining
  the source of truth. Nothing in v1 has to be undone for it.
- `triggered_by` (à la `RunTrigger`) is **inbound in v1**. It exists now,
  unused-but-representable, because §9's v1 is respond-only and Nia
  initiating contact later must be an addition: an initiated exchange is
  one whose trigger is `scheduled`/`internal` rather than an inbound turn,
  and the only schema change is the enum growing a case — not a new table,
  not a nullable aggregate.
- `origin` is the surface the turn came through (`web` in v1), resolved
  from config — **not** a per-message transport id. An earlier draft of
  this doc had a `transport_ref` field here; it is gone, and §9 explains
  why it was a mistake.
- `direction` is not stored: given the roster, it is derivable from
  `speaker` (the assistant's turns are outbound).
- `speaker` is an id (`andrew` | `nia`) validated against the roster;
  `role` is stored denormalized so a transcript renders without
  re-deriving config, and so a historical turn keeps the role it was
  rendered with even if the roster changes.
- The roster is **config in v1** (two fixed participants), not a table.
  A table is over-engineering until a third participant exists; the
  `speaker`/`role` split is what keeps that an addition later.

Messages:

    ChatExchangeMessage   chatId, exchangeId     → chat lane

Same shape discipline as `LlmTurnMessage`: ids only, state in the row,
validated under the claim.

## 7. Integration points and risks

These are the places the change touches existing, load-bearing code. Each
one is a real risk, not a checklist item.

- **The boot sweep (SPEC §6.2) reasons over the fleet's shape.** The reap
  clears *every* claim, on the premise that no worker in this process
  group can be mid-turn — a premise that "covers only the lanes this fleet
  actually consumes" (`docker/entrypoint.sh`). **Adding a `chat` lane
  means the shape check must learn it**, and since the lane is owned by
  the LLM workers the ownership rule is "`chat` is owned exactly when
  `LLM_WORKERS > 0`" — the same predicate that already governs `llm`.
  Getting this wrong is the same "work done twice, live side effects and
  all" bug the lane-ownership rule exists to prevent. This is the
  highest-consequence integration point in the design. **Under §3.3
  Option B the sweep also has to learn a second *claimable aggregate*, not
  just a lane** — which is the concrete reason to write the claim/reap
  logic against the `Claimable` interface rather than copy it.
- **Boot requeue must know owed chat exchanges.** The second half of boot
  recovery re-dispatches what abandoned claims are *owed*. A chat exchange
  is owed in the same sense a run is; omit it and a chat hangs on restart —
  with a human waiting. Same interface, second implementor.
- **`TASKLOOM_LLM_MAX_CONCURRENCY` now counts chat calls**, and it is a
  *total* that must match the model server's parallelism (§4.1). A chat
  can occupy the only slot. Intended — that is priority — but the operator
  must understand that capacity includes chat traffic, and that setting N
  above the server's `parallel` produces rejected requests, not extra
  throughput. Say so where the variable is documented.
- **`redeliver_timeout` on the `chat` lane** must match the run lanes
  (7200s), for the same reason: it has to be able to take over a dead
  worker's claim.
- **Chat failures must be visible.** A task turn that throws lands in
  `failed` and the run shows it. A chat turn that throws must not die
  silently — the human is *waiting*. A failed chat exchange should produce
  a visible "I couldn't get a turn" signal, not a dropped message. There
  is no precedent for this in the run lanes; it must be designed, not
  assumed.
- **No Messenger retry on the chat lane either.** Same reasoning as the
  run lanes: the engine owns retry semantics and the ledger is the only
  source of truth. A retry strategy here would be a second, invisible one.

## 8. Phasing

1. ~~**The conversational loop on the web surface.**~~ **Shipped.** The
   smallest thing that sends a message to the model, gets a reply, and
   records the exchange — on the decided surface (§9, the minimal web chat).
   **Attribution (§2) and the aggregate shape (§3.3) landed with it**, as
   specified, for the reason given: the turn record *is* the chat's data
   model, so splitting them would have meant writing it twice.

   One thing the build added that this list did not anticipate, and it is
   worth recording because it is the shape §3.3's "share the machinery"
   advice predicts: the claim/reap/requeue protocol became its own class
   (`App\Claims\ClaimStore`, with a closed `ClaimTarget`) rather than being
   copied, and `ClaimReaper` now sweeps *every* claimable aggregate. The
   alternative was a boot sweep that silently covered runs and not
   conversations — which is the same class of failure this doc spends §6.2's
   neighbourhood warning about, one level up.
2. ~~**The chat lane as priority head**~~ **Shipped**, with the chat turn
   handler, the loop and the ledger reused, and the claim/requeue machinery
   behind the shared interface exactly as above. No streaming on the *client*
   side yet, as planned — the lane is a queue-priority change and did not need
   the streaming reader. This is the "chat preempts tasks" milestone, with the
   §4.4 caveat intact: *preempts* here means *goes next*, not *goes now*.
3. **Streaming client + cancel token** → true sub-second preemption, and
   the "watch it type" read path.
   *At the default N=1 this is what makes chat real-time; at N≥2 it is
   what makes it instant. Either way it is on the critical path.*
4. **Alerts** — severity, destination policy over the existing channels
   (Discord, ntfy), coalescing.
5. **Nia initiates.** She can open an exchange rather than only answer one
   (§6, `triggered_by`). Small *because* v1 left the seam.
6. **Task sub-priorities** (a daily briefing above email processing). The
   lane scheme generalizes directly: `chat` > `llm_high` > `llm` >
   `llm_low`, drained in that order by the same workers.

*(Decision locked: v1 is chat + everything else; task sub-priorities are
later.)*

## 9. The chat surface — which product?

This is the **first** question, ahead of any lane work, because it decides
what a "turn" is and where it comes from. The options, at three levels of
ambition:

1. **Build the smallest web chat.** A page that sends a message, shows the
   conversation, and streams the reply. It reuses the existing stack (Twig
   + FrankenPHP) and the existing sign-in (SPEC §4.3, §11), and it follows
   the shape already in the tree — a controller per area
   (`RunController`, `TaskAdminController`, …) with a `templates/<area>/`
   directory. The read path is not new ground either: `McpController`
   already returns a `StreamedResponse`, so streaming bytes out of a
   controller is precedent, not invention. Cost: a controller, a template,
   two small entities, and an SSE-or-poll read path for the assistant's
   turn. This is the option that *answers the open questions by existing*
   — you cannot know what the chat wants until you can use one.
2. **Adopt an existing chat front-end** (Matrix / Signal / IRC with a
   bridge). Cheaper if the bridge is trivial; expensive in exactly the place
   this system cannot afford it — a third party's identity and delivery
   semantics leaking into attribution. §2's invariant needs authorship to
   be *ours*, not a homeserver's opinion of it.
3. **ntfy as the surface.** ntfy is a *notification* transport: push,
   mostly one-way, with no notion of a transcript. Good for alerts (§5).
   Wrong as the chat.

**Decision: build the minimal web chat.** This is not a new preference:
SPEC §8 already names the admin UI "Twig, **phone-first**", and the phone is
where this is used ~95% of the time. A browser page is the one surface that
works on a phone with no app, no bridge, and no third party in the identity
path. ntfy stays for alerts (§5); any other surface
becomes an *adapter* onto the same exchange record later — not the thing
the record is shaped around.

**The honest cost of web-on-phone: notifications.** A web page does not
reach you when it is closed, and this is a real gap, not a detail — it is
the same gap that made Discord the alert channel (§5). So the split is:
**alerts use the channels that reach the phone (Discord, ntfy); chat is the
place you go to when you are in it.** Chat-initiates-contact (§8 step 5)
is what eventually closes the loop — Nia *starts* an exchange, and the
alert path is what tells you to come look. Until then, v1 chat is
respond-only (§9, below) and the gap does not bite.

**v1 is respond-only.** Nia answers; she does not open a conversation.

**Forward-thinking, so that is an addition later, not a redesign:**

- `ChatExchange.triggered_by` exists now with `inbound` as its only v1
  value (§6). Initiation is a new enum case, not a new table.
- The exchange is already an execution with a trigger — the run engine's
  `RunTrigger` is the exact precedent (`manual` | `scheduled`). An
  initiated exchange is one whose trigger is scheduled/internal.
- The delivery path is already separate (§5): when Nia initiates, the
  *notification* that tells you to come look is an alert, and alerts
  already have channels. Chat and alerts stay decoupled.
- Nothing in v1 makes an inbound turn *structurally required* for an
  exchange to exist — which is the one decision that would have made
  initiation hard. This is the thing to be careful of in code review:
  do not let "an exchange always has a triggering inbound turn" harden
  into a non-null FK with no escape.

**Multiple surfaces?** Support them as adapters, but only after one works
end to end. The exchange record in §6 is deliberately surface-agnostic (a
single `origin` field), which is what makes "add a surface" an addition
rather than a migration. Do not build the adapter layer before there is a
second adapter.

**What this settles.** The first draft of this doc carried a
`transport_ref` field — "the transport's own message id" — and an open
question about "one ntfy topic per conversation, or a request/response
pair". Both presumed the conversation lives *inside* a delivery transport's
id space. If the chat is a web surface we build, the endpoint is the
interface and there is no durable per-message transport id to store:
`transport_ref` is deleted, and `reply_to_id` (intra-conversation
threading) is the only correlation field that survives. This is the kind of
error the surface decision exists to flush out, which is why it comes
first.

## 10. Open questions

1. **The streaming read path** — SSE or long-poll, and whether a chat
   turn's partial output is persisted per chunk or only on completion.
   (Related to §4.3: the same streaming reader serves both the preemption
   goal and the "watch it type" feel.)
2. **Unbounded conversation.** A `Run` ends, so its context is bounded by
   construction. A chat does not end, so its transcript grows forever. The
   SPEC has context-budget machinery for runs; a chat needs the analogous
   policy — what scrolls out, what is summarized, what is always kept.
   Not designed here. (Note this is a *context* problem, not a storage
   one: the ledger keeps everything.)
3. **Which human?** The roster makes a second human an addition, but
   nothing yet decides *which* participant an inbound message is from. v1:
   one human, identity from the session.
4. **Settled, for the record:** the chat surface (web, §9); concurrency
   semantics (total, §4.1); `ask-user` (deferred, §5); table reuse vs
   duplication (§3.3, duplicate the shape, share the machinery); the turn
   table (§6, none — the transcript is a filtered read over the ledger);
   v1 initiation (respond-only, seam left, §9).

## 11. What this doc is not

Not a task. Not a `Run`. Not a second engine. The claim of this design is
that attribution, the exchange aggregate, and priority are all small
changes *because* they are placed at layers that already exist — the
prompt compiler for §2, the ledger and claim machinery behind an interface
for §3, the consume order for §4 — rather than at a new orchestration
layer. If a step of this turns out to need a new engine, the design is
wrong and should be revisited rather than pushed through.

# Graceful restart and preemption — design note

**Status:** proposed · **Scope:** SPEC §6, §6.1, §13.3 · the turn model, the
claim protocol, and the container's stop path

**Companion to:** `SINGLE_CONTAINER_RUNTIME.md` (this document revises its
"Shutdown" section) · `SPEC.md` §6 (this document adds §6.2)

## The problem

A run is a sequence of LLM turns delivered as Messenger messages. A turn in
flight is a single blocking POST with a 300-second timeout
(`TASKLOOM_LLM_TIMEOUT`). Two things follow, and both are already true in the
shipped code — this note is about making them true *deliberately*.

**1. Stopping the container can fail a run.** `docker stop` sends SIGTERM,
which `docker/entrypoint.sh` forwards to every child. A Messenger worker's
reaction to SIGTERM is `Worker::stop()`, which sets a flag; the worker loop
checks it *after* `handleMessage()` returns. So the worker finishes the
current turn — up to 300 seconds — before exiting. The entrypoint's
`TASKLOOM_SHUTDOWN_TIMEOUT` (default 30s) and compose's `stop_grace_period`
(60s) both expire long before that. The result is SIGKILL mid-request: the
socket dies, `LlmClient` classifies the transport failure, and the turn core's
`catch (LlmRequestException)` calls `failRun()` — which does not merely stop
the run, it **commits a terminal state**, and for a step child that advances
the graph (SPEC §13.3). A restart can therefore turn a healthy multi-step task
into `needs_attention` with a failing child.

The window is not exotic. It is 300 seconds of wire time against a 30-second
grace, i.e. it is *most* turns on any task whose model thinks.

**2. There is no way to get the model back.** With one local GPU the honest
setting is `TASKLOOM_LLM_MAX_CONCURRENCY=1` (SPEC §6): one worker, one slot,
requests serialize. Six active tasks keep that slot continuously occupied, so
the operator has no way in to converse with the agent. The available tool is
`pause` — admission control, "don't start another" — which is not what is
wanted and takes one full turn per task to take effect.

Both problems have the same missing primitive: **the ability to stop feeding
the model, now, and to have the interrupted work come back untouched.**

## The shape

Three mechanisms that fall out of machinery the engine already has, plus a
fourth that covers what they cannot.

### S1 — SIGTERM stops admission, once

`messenger:consume` is a `SignalableCommandInterface`; on SIGTERM it calls
`Worker::stop()`. Symfony's console `Application::doRunCommand()` also
dispatches `ConsoleEvents::SIGNAL` (a `ConsoleSignalEvent`) *before* the
command's own `handleSignal()`, so a listener sees the signal first.

A listener on `ConsoleEvents::SIGNAL` for SIGTERM/SIGINT sets a
process-scoped "stop requested" flag. It cannot change the framework's
behaviour (the worker still finishes the current message), but it marks
everything that a *delivery* is now forbidden: the turn core consults it
before starting work, and both turn cores consult it before committing a
successor. The flag means **"no new deliveries may start"** — it never means
"state is settled" (see the trap below).

### S2 — the in-flight request aborts in ~1s, not 300

`symfony/http-client` supports an `on_progress` option, and the contract is
explicit: *"throwing any exceptions MUST abort the request; it MUST be called
on connection, on headers and on completion; it SHOULD be called on
upload/download of data and **at least 1/s**."* Under curl it is
`CURLOPT_PROGRESSFUNCTION`; a non-zero return aborts the transfer.

`LlmClient::chat()` gains:

```php
$response = $this->httpClient->request('POST', $url, [
    'headers' => $headers,
    'json'    => $payload,
    'timeout' => $this->timeoutSeconds,
    'on_progress' => function (): void {
        if ($this->stop->isRequested()) {
            throw new LlmRequestPreemptedException();  // dedicated type — see S3
        }
    },
]);
```

The callback definition wins twice here. It fires at least once a second
**whether or not bytes are moving**, so a local model that goes quiet for
twenty seconds mid-turn is still noticed; and it fires off HTTP body traffic,
so the answer is available even though
`LlmClient` is deliberately not a streaming client (its docblock says "No
streaming", and SPEC §2.3 does not ask for it).

The dedicated exception type is load-bearing, not tidiness. `CurlResponse`
catches whatever the callback throws and re-throws it from the response, but
*everything else on that path* — a dead socket, a SIGKILLed peer, a timeout —
also surfaces as a `TransportException`. So "the operator asked us to stop"
must be a distinct type, or it is indistinguishable from "the model fell over",
and the engine would do the wrong thing with each.

### S3 — a preempted turn commits nothing, and fails nothing

The turn model already gives this away for free. In `RunEngine::llmTurn()`:

- the execution claim is taken with one atomic `UPDATE`
- everything the turn produces is committed by `commitTurn()` /
  `commitTerminal()`, and **nothing is committed until the turn returns**
- the claim release lives in a `finally` around the whole body

So an aborted turn leaves the run row exactly as it was: no terminal state, no
checkpoint, no step increment. "Put it back as if it had never started" is not
a restore operation here — it is the absence of an operation. And because the
`finally` already releases the claim, the interrupted run is immediately
available for a fresh delivery.

The turn core changes in exactly two places:

**Classify.** `catch (LlmRequestPreemptedException $e)` **before** the existing
`catch (LlmRequestException $e)` (the latter is its parent in
`Throwable` terms only if we make it so — keep them siblings, and order the
catches preemption-first) and return a new result case:

```php
enum RunTurnResult {
    case AwaitToolTurn;
    case AwaitLlmTurn;
    case Done;
    case Stale;      // delivered but not owed — dropped
    case Preempted;  // owed, started, deliberately stopped — dropped, still owed
}
```

`Stale` and `Preempted` are both "the delivery ends here", but they are not the
same fact and the container test would like to tell them apart.

**Return normally.** The handler must *not* throw. This is the non-obvious
part, and both obvious answers are wrong:

- Throwing anything sends the envelope to the failure transport: the lanes run
  `max_retries: 0`, so one throw is one dead-lettered message.
- Throwing `RecoverableMessageHandlingException` re-delivers — and in Symfony 8
  it re-delivers **regardless of `max_retries`** by default (`forceRetry`
  defaults to `true`, preserved from 8.0 semantics). With the stop flag still
  set, the worker would spin on the same message until the grace period or the
  SIGKILL. The parameter exists to bound it (`forceRetry: false`), but the
  honest configuration here is a normal return.

Returning normally acks the message and it is gone. Nothing was committed, so
the run still owes the turn — the same position it would be in had the worker
been killed before the message was delivered. That is the desired end state for
both a container stop and a "halt the model" button.

**Do not release early.** `release()` hands back a free claim, which is a slot
the other workers race for. The `finally` release is correct because it happens
as the delivery ends; calling it *when the stop flag is set* is the opposite of
stopping. The one-line rule: **when the stop flag is set, the only correct
actions are "commit nothing" and "return".**

One consequence worth stating rather than discovering: a preempted turn's
successor is never committed, so the run sits in `running` with no carrier —
exactly the state `app:run:requeue` already exists to repair. That is fine and
is the subject of S4. It also means the two halves of this design *depend* on
each other: S1–S3 without S4 produces runs that stop cleanly and then wait for
an operator.

### S4 — the boot sweep

SIGKILL, OOM, power loss: no cleanup runs. But there is one moment when the
question "is this claim actually live?" has a cheap and *sound* answer — at
boot, before any worker exists. Combined with `app:run:requeue`, which is
already idempotent by construction (it re-derives owed work from committed
state, and the claim plus state checks make a duplicate a no-op), this gives
recovery that does not have to wait out `CLAIM_STALE_SECONDS` (3600s) or the
lanes' `redeliver_timeout` (7200s):

```
serve boot, after check_schema, before spawn_fleet:
  php bin/console app:run:reap-claims --startup && php bin/console app:run:requeue
```

## The trap: *eligible* vs *owed*

This is the concept the whole design turns on, and it is the thing that will
be broken at 2am by someone who means well.

Every gate in the engine exists to answer "may this delivery proceed?" —
`isTerminal()`, `graph->isSkipped()`, the step check, the pending-tool-turn
check. The stop flag adds one more "no" to that list, and it must stay on that
list. What it must never do is feed any of those gates' *answers*.

The tempting shortcut is some form of:

```php
if ($this->stop->isRequested()) {
    $this->incomplete($run);   // ← this is fine
    return RunTurnResult::Preempted;
}

if ($this->stop->isRequested()) {
    $run->markIncomplete();    // ← this destroys the run
    $this->em->flush();
}
```

The first is reasonable (optionally state `queued`, which is literally defined
as "waiting for the engine to pick it up"). The second marks a run terminal —
and for a step child, terminal advances the graph, so a stop request would
settle its parent as incomplete and the task's remaining steps would never
run. **The stop flag governs eligibility, never state.** Pause buttons kill
this distinction first; the design note exists partly so the next person
pressing for a "stop all runs" button is told the difference before writing it.

## Verified starting points

| Fact | Where it was checked |
|---|---|
| SIGTERM → `Worker::stop()`; the loop exits after the current message | `ConsumeMessagesCommand::handleSignal()`, `Worker::run()` (`while (!$this->shouldStop)`) |
| `ConsoleEvents::SIGNAL` fires before the command's `handleSignal()` | console `Application::doRunCommand()`; the FrameworkBundle Application installs the dispatcher |
| `on_progress` throwing aborts the transfer, and the exception is re-thrown from the response | http-client-contracts `OPTIONS_DEFAULTS`; `CurlResponse` (queues `[null, $e]`, returns `1` from the progress callback) |
| Nothing is committed until a turn returns; the claim release is in a `finally` | `RunEngine::llmTurn()` / `commitTurn()` |
| `RecoverableMessageHandlingException` retries past `max_retries` unless `forceRetry: false` | messenger `RecoverableMessageHandlingException` docblock |
| The entrypoint's syscall there is SIGTERM; SIGKILL is "after us" | `docker/entrypoint.sh` `stop_children` / `kill_children`; compose `stop_grace_period: 60s` |

## The restart envelope, and why the arithmetic is not enough

If the turn in flight is at step N and step N's LLM turn completed before the
stop — its **tool turn** is pending, and the tool-turn delivery is what is in
flight — the pending tool turn and the successor LLM turn's message commit in
one transaction, and the preemption kills the turn before either. The
committed state still has the pending tool turn, so `requeue` dispatches
`ToolTurnMessage` and the run resumes exactly where it stopped.

The rectangle that ignores this and just leaves a window open is the bug the
whole note is about:

```
               web up                 workers exit
─────────────────┬────────────────────────┬──────────►
   boot gates    │   fleet running        │  SIGTERM→exit
                 │                        │
   the model is NOT protected here ──────┴─ a turn in
   (a worker that claims work while the    flight is
    model is unreachable marks runs failed) SIGNKILLed at 30s
```

Both ragged edges matter:

- **On stop:** stop admitting first, then stop the model. Reverse the order and
  the harness manufactures the broken-socket-as-failure bug from the other end.
- **On start:** do not let `llm` workers consume until the model answers.
  `TASKLOOM_LLM_BASE_URL` may be across the network now (see below), so
  "worker up" and "model reachable" are different facts. A worker that claims
  a turn against an unreachable model gets a transport failure and — through
  the existing `catch (LlmRequestException)` → `failRun()` path — commits a
  failed run. Since `/ready` is already a thin wrapper (`HealthController`),
  this is a `depends_on: condition: service_healthy` on a real probe, or a
  worker-side pre-flight in the entrypoint.

## Where this design's assumptions stop

Three of them, stated so they are decisions rather than surprises.

### 1. The LLM is off-host, so the boot sweep needs a witness

The original framing of S4 was "at boot no worker is alive, so every claim is
dead." That argument holds **only for the process group that is being
restarted together**. It is not an argument about the run table.

Concretely: `docker compose up -d taskloom` after `down` restarts the web and
the fleet together, and the blanket sweep is exactly right. But the project
already ships a case that breaks it — `compose.yaml` has a second service,
`migrate`, which is one-shot and never touched the run table, so it would be a
sweep by a process with no authority. And the moment there are two
deployments sharing one database — a workers-only host and a UI you start when
you want it — `taskloom` starting up says nothing about the workers.

The rule: **a startup sweep must be scoped to claims the starting process group
has witnessed.** A one-shot or web-only process performs S4 only when it owns
the worker fleet, and otherwise runs the ordinary lease-based
`app:run:requeue`, which is always safe because it re-derives from committed
state instead of assuming.

The honest mechanism for the multi-host case is a **worker heartbeat**: the
fleet writes a liveness row, and a claim is reapable when its claimant is not
alive *and* the claim is not fresh (a wide lease, say 15 minutes, which still
beats the hour). This note does not build it. It records that S4's cheap
version is only cheap because everything shares one clock and one boot.

### 2. "Workers without the web interface" is not a shape this repo runs yet

`docker/entrypoint.sh` has exactly two modes:

- `serve` — always spawns the web process, and treats the web process's exit
  as the end of the container (`EXIT_CODE=$rc; begin_shutdown`).
- anything else — one-shot: warm cache, `exec "$@"`, no fleet.

There is no "workers only" fleet. Today that is reached by running
`messenger:consume llm` by hand, outside the supervisor, which is precisely
the deployment in which S4's blanket sweep is unsound — and in which there is
no `serve` to hang a `run --startup` sweep on in the first place. If a
workers-only mode is wanted (and it is a reasonable want, since it is the
cheapest way to run TaskLoom on a box that also runs a model), it should be an
explicit entrypoint argument — `serve --no-web` — that (a) skips the web spawn
and the web-is-critical rule, (b) marks itself as a fleet owner so it may run
the startup sweep, and (c) is covered by `EntrypointSupervisorTest` like the
rest of the fleet contract.

### 3. Restart-time preemption needs a separate control channel, and may not

S2's hook is `on_progress`, which only exists for the duration of a request.
Preempting an in-flight turn at container shutdown therefore needs two paths:
the *pre-stop* is "stop admitting, then wait for turns to end on their own
grace", and the *preempt* is for the case where that wait is too long.

The gap is that the entrypoint's SIGTERM goes to the worker, not into curl. The
worker's own SIGTERM handler sets `shouldStop`, which (S1) sets the stop flag
the next time anything reads it — and the only thing that reads it during a
request is S2's callback, which is what we are trying to trigger. So the abort
fires no sooner than the next callback tick, which is fine in practice (1/s)
*provided the flag is what the callback reads*, and useless if it is not.

Options, in order of how much I would recommend them:

- **The 1/s heartbeat is enough.** It fires whether or not bytes move, so the
  worker notices the flag within a second. The pre-stop's "stop admitting then
  let turns end" therefore has a working backstop. This is the design.
- **A file check in the callback.** Turning the callback into a `file_exists()`
  on a path the entrypoint touches before SIGTERM gives a control plane that
  works even for a request that has not yet produced a callback. It costs one
  `stat` per second. Worth it if preemption ever needs to be *immediate*.
- **Do nothing, and accept SIGKILL for the last 30 seconds.** Correct only
  because S4 repairs it. This is the fallback, not the design.

The pre-flight health check (§"the restart envelope") belongs here too: it is
a *pre-stop* in reverse, and it is the reason `TASKLOOM_LLM_BASE_URL` being
remote changes the boot sequence rather than just the URL.

## Preemption, beyond restarts

Once S2 exists, the operator-facing features are nearly free, and they are the
reason the infrastructure is worth building at all:

- **Halt / resume.** A flag in a place the callback can read (a row, or a
  sentinel file) makes a button that stops the model in about a second.
  Without S2 the same button takes up to 300 seconds per task.
- **Chat with the agent.** "Stop feeding the model, let me in, then resume"
  is one flag with two readers: the turn cores (admission) and the callback
  (abort). The queue keeps its place; the VIP request is not a second lane.
- **Interactive priority.** SPEC §6 promises plain FIFO. Preemption is what
  makes "a long multi-step task cannot starve others" true *with an operator
  in the room*, which is where it was most obviously false.

## Tests this design implies

The behaviour that silently rots is the *classification*, so most of the
coverage is there — and one of these is a correction to a plausible-looking
test.

- **Preempted turn is not failed, and is not terminal.** Drive `llmTurn()`
  with a fake `LlmClientInterface` that throws the preemption exception;
  assert `RunTurnResult::Preempted`, assert the run's status is unchanged,
  assert no terminal event reached the ledger.
- **Preempted run is owed.** Follow with `app:run:requeue --dry-run` and
  assert it reports the run(s) it just preempted. This is the assertion that
  would have caught a `markIncomplete()` in the turn core.
- **Claim released, state untouched.** After a preemption, assert
  `claimed_at IS NULL` and `lock_version` unchanged relative to the claim.
- **Abort really aborts.** `MockResponse::cancel()` invokes `on_progress`, and
  `writeRequest()` invokes it at start, so a test can drive the callback with
  a `MockHttpClient` and assert the request was abandoned rather than
  completing.
- **The callback runs. In the container.** `CURLOPT_PROGRESSFUNCTION` firing
  while a model is thinking is curl behaviour, not framework behaviour, and no
  unit test proves it. The container test (`EntrypointSupervisorTest`, or a
  DB-backed variant) should run a real local endpoint that stalls for longer
  than `TASKLOOM_SHUTDOWN_TIMEOUT`, send SIGTERM, and assert the turn aborted
  and nothing was written.
- **The status race is left alone.** A test that preempts a `queued` sibling
  and a `running` sibling must assert the sibling is `running` — and it must
  *not* assert that the parent didn't settle, because the stop flag changes
  nothing about the graph. A blanket `queued`-restore deliberately overrides
  this and would settle the parent early; that is why it is written as a
  separate, conditional rule above.
- **One-shot containers do not sweep.** `app:run:requeue --startup` from a
  process that is not a fleet owner must leave a live claim alone.

## SPEC and doc changes this implies

- **SPEC §6** — the semaphore section should say that the worker count is an
  *upper bound at admission*, not a promise about the wire, because a
  preempted turn is re-owed and a re-delivery happens at the back of the lane.
- **SPEC §6.2 (new)** — the claim protocol gains a third outcome beside
  "taken" and "abandoned": *interrupted*, committed as nothing, re-owed.
- **`SINGLE_CONTAINER_RUNTIME.md`** — its "Shutdown" section currently says "a
  run is never abandoned mid-turn," which is exactly the claim this note
  revises. It becomes: a turn is never abandoned *unaccounted for*.
- **`.env.example` / README** — `TASKLOOM_LLM_TIMEOUT` and the shutdown window
  should be described as one arithmetic relation (`timeout ≤ grace` is a
  requirement, not a coincidence), and the remote-endpoint case should be
  called out where the concurrency and shutdown knobs are documented.

## Build order

1. **S4 scoping, standalone.** `app:run:requeue --startup`, fleet-owner gated,
   tested both ways. Correct without any of the rest.
2. **S1.** The signal listener and the stop port. No behaviour change yet.
3. **S2 + S3.** The preemption exception, the callback, the new
   `RunTurnResult`, and the two catch sites.
4. **S3 without S2** is also a valid intermediate: a turn that is *about* to
   start consults the flag and returns preempted. That alone removes most of
   the stop-time failure window, with none of the curl surface.
5. **The boot pre-flight** for a remote endpoint, as part of the entrypoint's
   boot gates (it already has one for the schema and one for the env contract).

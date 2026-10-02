# Single-container runtime

**Status:** decided · implements SPEC §6 concurrency inside one container
**Supersedes:** the three-service compose example (`taskloom` + `worker-llm` +
`worker-tools`), which asked the operator to scale a service by hand.

## The shape

`docker compose up` runs **one container**. Inside it, `docker/entrypoint.sh`
(the image's entrypoint, `serve` mode) starts and supervises:

| Process | Count | Why |
|---|---:|---|
| `frankenphp run …` | 1 | The admin UI **and** the MCP server role (`POST /mcp`) — one process, one port. The SDK's HTTP transport is a PSR-7 handler, not a web server, so there is no second listener to run. Omitted by `serve --no-web`. |
| `messenger:consume llm` | `TASKLOOM_LLM_MAX_CONCURRENCY` | One worker holds at most one LLM request on the wire. **N workers are the concurrency semaphore** (SPEC §6) — the variable stopped being a suggestion to scale a service and became the count the supervisor starts. |
| `messenger:consume tools` | `TASKLOOM_TOOL_MAX_CONCURRENCY` | Tool turns mostly wait on external servers; several run at once without competing for the model. A slow tool never blocks the `llm` lane. |
| `app:schedule:run` | `TASKLOOM_SCHEDULER_ENABLED` (0 or 1) | The scheduler daemon (SPEC §14): ticks on `TASKLOOM_SCHEDULE_INTERVAL` and launches due scheduled tasks through the `llm` lane. One process; it holds no message and shuts down gracefully on SIGTERM (the current tick finishes). |

There is no separate worker service, no orchestrator process, and no
sidecar. The entrypoint *is* the process supervision — ~200 lines of bash with
tests, replacing what used to be "remember to run two more containers and to
scale one of them correctly".

### `serve --no-web`

`serve` takes exactly one flag. `--no-web` starts the worker fleet and the
scheduler, and not the web process — for a host that is only here for the
model. It is the same fleet, the same supervisor, and the same shutdown path;
the only differences are the missing `web` child, and that the container no
longer ends when the web process does, so SIGTERM is what stops it.

Two rules keep the mode honest rather than a footgun:

- It **still owns the fleet**, so it is entitled to run the boot sweep (§6.2).
  That entitlement is the entrypoint's to grant, because the entrypoint is what
  knows whether it started workers — which is also why the flag is *not* part of
  the compose env contract: services share an environment anchor, so a
  compose-supplied `TASKLOOM_FLEET_OWNER` would be handed to the one-shot
  `migrate` service too, the exact process the gate exists to exclude.
- It **refuses to supervise nothing**: `--no-web` with no llm workers, no tool
  workers and no scheduler exits with an error rather than holding a container
  open that looks healthy and does nothing.

Anything else after `serve` is refused by name, not ignored — a typo that
silently drops the web process, or silently keeps it, is the quiet wrongness
the rest of this script refuses.

## Why the entrypoint, and not a supervisor daemon

The alternative was `supervisord` (or s6, or a compose file with replicas).
The entrypoint wins on three counts:

1. **It reads the same env the app does.** `TASKLOOM_LLM_MAX_CONCURRENCY`
   already exists; the entrypoint is the first thing that actually *reads* it.
   A supervisor config would need a generator to translate env → process
   count, which is the same logic in a less testable place.
2. **It is testable without Docker.** `tests/Container/EntrypointSupervisorTest`
   drives the real script against stub `php`/`frankenphp` executables: fleet
   composition, restart-with-backoff, web-exit propagation, TERM propagation,
   escalation. A supervisor daemon's behaviour needs a container to test.
3. **It keeps the container honest.** The web process is critical: when it
   exits, the fleet stops and the container exits with *its* code. Compose's
   restart policy then sees one lifecycle, not three independent ones racing.

The known cost is written down rather than papered over: **bash is the
supervisor**. It is exercised by tests, `shellcheck`-clean, and small — but it
is not a general-purpose init. If the fleet ever needs per-process resource
limits, ordered startup, or structured supervision status, that is the signal
to reconsider, and the entrypoint's shape (one `spawn` per process, one
`CHILD_LABEL` map) makes the replacement local.

## Boot sequence

```
warm cache          cache:warmup            (prod only — needs injected secrets)
env contract        lint:container --resolve-env-vars
migrate (optional)  TASKLOOM_MIGRATE_ON_BOOT=1
schema gate         doctrine:migrations:up-to-date   → refuses to start the fleet
boot recovery       app:run:requeue --startup (fleet-owner only; see §6.2)
catalog sync        app:catalog:sync        (non-fatal — a down server never blocks boot)
fleet               web + N llm + M tools + scheduler (unless TASKLOOM_SCHEDULER_ENABLED=0)
```

**The boot recovery step has one ordering requirement, and it is not
negotiable: it runs before any worker starts.** Both of its halves depend on
it. The reap is only sound while no worker exists — once one is consuming, a
claim it takes is indistinguishable from a claim a corpse left, and only the
staleness window separates them. And the requeue must precede the spawn,
because a run sitting owed on a lane no consumer has reached yet is precisely
the state it is repairing. A clean stop needs neither half (workers finish
their message and release), so on the ordinary path it reports zero and moves
on.

Two of those deserve their reasoning written down:

- **The env contract check.** With `disable_dotenv`, a variable the container
  was not given surfaces *lazily* — on the first service that resolves it. In
  a worker fleet that means a worker boots fine, takes a message, and dies
  mid-run (or worse: a run reaches the LLM step and only then discovers
  `TASKLOOM_LLM_BASE_URL` is missing). `lint:container --resolve-env-vars`
  compiles the container with every referenced variable resolved, so the
  failure lands at boot, names the variable, and starts nothing. It costs
  ~0.6s.
- **The schema gate.** `doctrine:migrations:up-to-date` exits `1` for *both*
  "pending migrations" and "cannot connect", so the entrypoint classifies on
  the message as well as the code — an operator with a broken DSN is not sent
  chasing migrations.

## Migrations: still an explicit step (§8.6)

The entrypoint **does not** migrate on the `serve` path by default. A
container that migrates at boot cannot be scaled past one replica, and two
replicas racing migrations is the failure GUIDING-LIGHT §8.6 exists to avoid.

`docker compose up` still does everything, because the compose files run
migrations in a **one-shot service** first:

```
services:
  migrate:     runs migrations to completion, exits
  taskloom:    depends_on: migrate: condition: service_completed_successfully
```

A failed migration therefore blocks the app rather than leaving it running
against a schema it does not understand. For a deployment outside compose,
set `TASKLOOM_MIGRATE_ON_BOOT=1` (single replica only) or run the command
manually — the schema gate tells you exactly which.

## Shutdown

`docker stop` → tini forwards SIGTERM → the entrypoint stops the fleet:

1. SIGTERM to every child. A Messenger worker finishes the message it is
   processing, then exits — see the arithmetic warning below.
2. If children are still alive after `TASKLOOM_SHUTDOWN_TIMEOUT` (default
   30s), SIGKILL, and the container reports the escalation.

**The arithmetic is the thing to get right.** A Messenger worker's SIGTERM
handler only sets a flag; the worker loop checks it *after* `handleMessage()`
returns. So "finish the message it is processing" means up to one whole turn —
and a turn is one blocking LLM request bounded by `TASKLOOM_LLM_TIMEOUT`
(default 300s), not by the shutdown window. With the defaults, a stop during a
model call escalates to SIGKILL, which kills the socket mid-request, which the
engine reads as a transport failure and commits as a *terminal* run failure.

Stopping is therefore safe for the state machine — but it is not free, and the
fix (abort the turn instead of killing it) is designed in
`docs/design/GRACEFUL_RESTART.md`. Until that lands, the practical guidance is
the relation itself: keep `TASKLOOM_SHUTDOWN_TIMEOUT` and compose's
`stop_grace_period` above the turns you expect, and prefer stopping when the
queue is quiet. Nothing is corrupted either way — a failure is recorded and the
run is recoverable from its page — but a healthy task can end up in the
attention queue because you restarted a container.

`stop_grace_period` in the compose files is set **above** that window (60s),
or Docker would SIGKILL the fleet before the graceful path ever ran. A second
SIGTERM skips the wait. A worker stopped this way leaves a claim behind, which
the engine's staleness window (`CLAIM_STALE_SECONDS`) and `app:run:requeue`
already handle — stopping is safe at any point in a run. When the stop was a
*restart*, the boot sweep (§6.2) does not have to wait out that window: the
fleet being restarted is the reason the claim is known dead.

## What this deliberately is not

- **Not a second worker tier.** Same image, same code, same DB. The web
  process and the workers differ only in which command they run.
- **Not a task queue redesign.** The lanes, the claim protocol, the
  state-derived dispatch — all unchanged. This change only decides *who runs
  the consumers*.
- **Not multi-replica.** One container is the supported shape. Two would each
  start a fleet (which is fine — the claim protocol arbitrates) but the
  NLP-ish knobs (`TASKLOOM_LLM_MAX_CONCURRENCY`) would then be per-container,
  so the effective concurrency becomes the sum. If that day comes, move the
  concurrency knob to the deployment layer and keep one fleet owner.

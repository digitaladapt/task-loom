# Single-container runtime

**Status:** decided · implements SPEC §6 concurrency inside one container
**Supersedes:** the three-service compose example (`taskloom` + `worker-llm` +
`worker-tools`), which asked the operator to scale a service by hand.

## The shape

`docker compose up` runs **one container**. Inside it, `docker/entrypoint.sh`
(the image's entrypoint, `serve` mode) starts and supervises:

| Process | Count | Why |
|---|---:|---|
| `frankenphp run …` | 1 | The admin UI **and** the MCP server role (`POST /mcp`) — one process, one port. The SDK's HTTP transport is a PSR-7 handler, not a web server, so there is no second listener to run. |
| `messenger:consume llm` | `TASKLOOM_LLM_MAX_CONCURRENCY` | One worker holds at most one LLM request on the wire. **N workers are the concurrency semaphore** (SPEC §6) — the variable stopped being a suggestion to scale a service and became the count the supervisor starts. |
| `messenger:consume tools` | `TASKLOOM_TOOL_MAX_CONCURRENCY` | Tool turns mostly wait on external servers; several run at once without competing for the model. A slow tool never blocks the `llm` lane. |

There is no separate worker service, no orchestrator process, and no
sidecar. The entrypoint *is* the process supervision — ~200 lines of bash with
tests, replacing what used to be "remember to run two more containers and to
scale one of them correctly".

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
catalog sync        app:catalog:sync        (non-fatal — a down server never blocks boot)
fleet               web + N llm + M tools
```

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
   processing, then exits — a run is never abandoned mid-turn.
2. If children are still alive after `TASKLOOM_SHUTDOWN_TIMEOUT` (default
   30s), SIGKILL, and the container reports the escalation.

`stop_grace_period` in the compose files is set **above** that window (60s),
or Docker would SIGKILL the fleet before the graceful path ever ran. A second
SIGTERM skips the wait. A worker stopped this way leaves a claim behind, which
the engine's staleness window (`CLAIM_STALE_SECONDS`) and `app:run:requeue`
already handle — stopping is safe at any point in a run.

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

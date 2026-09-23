# TaskLoom

A local-first agentic harness: a Symfony application that runs LLM tasks against a
deliberately minimal set of MCP tools, where **the model only ever sees the tools its
current task needs**, every failure is captured as structured, categorized data, and the
system can propose changes to its own tasks — which never take effect until a human
approves them.

Successor to task-loop (Python) and task-weaver (PHP/Symfony). Design docs:
[`docs/design/SPEC.md`](docs/design/SPEC.md) ·
[`docs/design/DESIGN_CONSIDERATIONS.md`](docs/design/DESIGN_CONSIDERATIONS.md) ·
[`docs/design/ROADMAP.md`](docs/design/ROADMAP.md).

## What it does

- **Least context by construction.** Each task declares a toolbox (explicit tool list or
  tags, resolved once at run start). The model can never call a tool outside it — which is
  the prompt-injection mitigation *and* the performance win in one mechanism.
- **Observability-first robustness.** Every LLM request, tool call, validation error, and
  retry is a typed `RunEvent` row with a fixed error-class enum. Runs only reach
  `succeeded` with a schema-validated completion artifact (justified completion).
- **Gated autonomy.** Agents may create and edit tasks via the task MCP tools, but every
  agent-authored change persists disabled; a human enables it. Enabled tasks are immutable
  records — updates create replacement drafts, never mutations.
- **Local-first concurrency.** `TASKLOOM_LLM_MAX_CONCURRENCY` is a semaphore on LLM
  wire time: the run engine is turn-based (one LLM request = one queued message), and
  `N` `llm` workers mean at most `N` requests in flight. Tasks interleave naturally
  during tool I/O — see "Concurrency" below.

## Quick start

Requirements: PHP 8.5, Composer 2, SQLite.

```bash
composer install
cp .env.example .env           # then set APP_SECRET + TASKLOOM_ADMIN_PASSWORD
php bin/console doctrine:migrations:migrate
php bin/console app:taskloom:admin-user    # creates the admin user from TASKLOOM_ADMIN_PASSWORD
symfony serve                          # or: php -S 127.0.0.1:8000 -t public/
```

Docker:

```bash
cp .env.example .env           # set APP_SECRET + TASKLOOM_ADMIN_PASSWORD
docker compose -f docs/examples/compose.yaml up -d
# schema is an explicit deployment step, never a per-boot side effect (§8.6):
docker compose -f docs/examples/compose.yaml run --rm taskloom \
    php bin/console doctrine:migrations:migrate --no-interaction
# admin UI: http://localhost:8080
```

The compose example starts the app plus `worker-llm` / `worker-tools`
Messenger workers; scale the llm lane to your concurrency setting:
`docker compose ... up -d --scale worker-llm=2`.

## Concurrency

The run engine runs as **turns on two Messenger lanes** (`config/packages/messenger.yaml`),
backed by the Doctrine transport (one shared `messenger_messages` table, lanes selected
by `queue_name`):

- `llm` — **one LLM request per message.** This is the semaphore unit: each worker
  holds at most one request on the wire, so the number of `llm` workers *is*
  `TASKLOOM_LLM_MAX_CONCURRENCY`. Re-enqueued turns go to the back of the lane, so
  FIFO between runs — a long multi-step task never starves others.
- `tools` — executes the pending tool calls of an exchange. A slow tool never blocks
  the `llm` lane, and runs release their LLM slot while on tool I/O.
- `failed` — messages a worker could not process (infrastructure errors); inspect with
  `messenger:failed:show`, requeue with `messenger:failed:retry`. Classified run
  failures never land here — they are recorded in the run's ledger (the engine owns all
  retry semantics; the transport's own retry is disabled).

Run a task through the lanes:

```bash
php bin/console app:run:now <task-id> --queue     # enqueue
php bin/console messenger:consume llm tools      # one llm worker + one tools worker
# N workers for concurrency N (e.g. 1 on a single local GPU):
php bin/console messenger:consume llm            # start N of these, plus one `tools`
```

Reliability properties, by construction: a turn's checkpoint and its successor message
commit in one database transaction (no "committed but not dispatched" window); a
duplicate or late delivery is dropped against committed state; a dead worker's turn is
re-covered by transport redelivery or, for messages lost outside the transport's sight
(purged queue, restored backup), by `php bin/console app:run:requeue`. Run state lives
in the `run` row + checkpoint, so any worker can pick up any turn.

## Configuration

See [`.env.example`](.env.example) — every variable documented
inline. Key knobs:

| Variable | Purpose |
|---|---|
| `TASKLOOM_LLM_BASE_URL` / `TASKLOOM_LLM_MODEL` | OpenAI-compatible endpoint (Ollama / vLLM / llama.cpp) |
| `TASKLOOM_LLM_MAX_CONCURRENCY` | Concurrent LLM requests — run this many `messenger:consume llm` workers (1 on a single local GPU) |
| `TASKLOOM_STEP_BUDGET` | Max tool-call exchanges per run (fail closed) |
| `TASKLOOM_CONTEXT_LIMIT` | Context window for the fail-closed token budget |
| `MESSENGER_TRANSPORT_DSN` | Doctrine-backed lane table; `auto_setup=0` — create it with `doctrine:migrations:migrate` |

## Development

```bash
composer lint    # php-cs-fixer, dry-run
composer cs-fix  # php-cs-fixer, fix
composer stan    # PHPStan
composer test    # PHPUnit
.ci/conformance.sh --profile=web-app   # conformance checks (37 checks)
```

## License

[MIT](LICENSE) — © digitaladapt. Third-party notices in thirdparty-notices.
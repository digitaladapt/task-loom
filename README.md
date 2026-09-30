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

## Authentication

Two front doors, two credentials:

- **The admin UI is a session.** Visiting any page signs you in through
  `/login` with `TASKLOOM_ADMIN_PASSWORD`; the form offers "stay signed in on
  this device", which keeps the password in the browser's localStorage and
  signs you back in seamlessly on the next visit (a CSRF-protected re-login
  endpoint; the stored value is cleared by signing out or by a failed
  attempt). Sign out from the strip on any page.
- **The MCP endpoint takes a bearer key.** `POST /mcp` authenticates
  `Authorization: Bearer <TASKLOOM_MCP_API_KEY>`. It is deliberately not the
  admin password: rotate the agent key without touching the human login, and
  vice versa. The `mcp` firewall is stateless — a UI session cookie cannot
  open the endpoint, and the key cannot reach the UI.

Both credentials fail closed when their variable is unset. **Migration note:**
earlier versions used HTTP Basic (`Authorization: Basic base64(admin:password)`)
for both. That is gone; MCP clients must switch to `Authorization: Bearer
<key>`, and browsers to the login page. Generate a key with
`openssl rand -base64 32`.

## Quick start

Requirements: PHP 8.5, Composer 2, SQLite.

```bash
composer install
cp .env.example .env           # then set APP_SECRET + TASKLOOM_ADMIN_PASSWORD (+ TASKLOOM_MCP_API_KEY)
php bin/console doctrine:migrations:migrate
symfony serve                          # or: php -S 127.0.0.1:8000 -t public/
```

Docker:

```bash
cp .env.example .env           # set APP_SECRET + TASKLOOM_ADMIN_PASSWORD + TASKLOOM_MCP_API_KEY
docker compose -f docs/examples/compose.yaml up -d
# admin UI: http://localhost:8080
```

One container runs everything: the admin UI, the MCP endpoint, and the worker
fleet (N `llm` workers + M `tools` workers), supervised by the image's
entrypoint. `docker compose up` also deploys the schema — a one-shot `migrate`
service runs to completion before the app is allowed to start (§8.6: schema
changes stay an explicit step, they are simply wired for you). `TASKLOOM_LLM_MAX_CONCURRENCY`
is the number of llm workers the container runs, so there is nothing to scale
by hand. See [docs/design/SINGLE_CONTAINER_RUNTIME.md](docs/design/SINGLE_CONTAINER_RUNTIME.md).

## Authoring tasks

Tasks are authored in the admin UI: **New task** from the task list, or **Edit
task** on any task's page. The editor covers everything a task is — title and
brief, kind, the toolbox (by tag or by explicit tool, chosen from the catalog),
an optional **step graph**, and a **schedule**.

- **Steps are levels.** Steps in the same level run in parallel; each level
  waits for the one before it, and every step's run receives the previous
  level's outputs as inputs. Add and remove levels and steps in place; the
  graph is saved as authored (nested levels in, `depends_on` edges stored —
  SPEC §13.2).
- **Schedules are composed, not typed.** Pick one of the offered schedules
  (every N minutes, hourly, daily, weekdays, weekly, monthly) or write your own
  cron. The preview updates as you change the fields and shows the composed
  expression, a plain-English sentence, and the next three times it will
  actually run — in `TASKLOOM_TIMEZONE`, which is where it fires.
- **Saving lands a disabled draft.** For you exactly as for an agent
  (SPEC §4.3): nothing runs until it is enabled from the task's page. Editing
  an *enabled* task produces a **replacement draft** (SPEC §4.4) — the running
  task is never touched until you approve the replacement.

The editor and the task MCP tools share one gated write path, so the two
cannot drift: what the browser submits is valid authoring/write format, and
what an agent submits is what the editor reopens. The difference is only the
author recorded on the draft (`user` vs `agent`), which is what the task list's
approval queue shows you.

## Run surface

The admin UI covers the whole run lifecycle (SPEC §8). **Run now** on an
enabled task's page queues a run on the worker lanes (never inline in the
request) and takes you to its page:

- **`/runs`** — the scheduler: who holds an execution claim right now (the
  claim is the wire slot, shown with its lane and age; a stale claim is
  flagged for takeover), who is waiting, which step graphs are in flight —
  over the run history.
- **`/runs/{id}`** — the attempt ledger: every `RunEvent` with its typed
  payload (filterable by `?error_class=…`), the full transcript
  reconstructed from the ledger and the frozen prompt head, the completion
  artifact or the failure reason. For a stepped task, the run page shows
  the step graph grouped into levels, with the final consumer labelled.
- **`/attention`** — `needs_attention` / `incomplete` runs grouped by error
  class; a failed step graph appears as its parent, carrying the failing
  step's diagnosis (SPEC §13.5).

One manual trigger, two faces: the web **Run now** and `app:run:now --queue`
share the same launch path (`RunLauncher`), so a UI run and a CLI run are the
same run — and the scheduler tick fires through the same path (see
[Scheduling](#scheduling)).

On the task page, **Edit task** opens the [authoring surface](#authoring-tasks).

## Scheduling

A task can carry a **cron schedule** (`task.schedule`; e.g. `0 8 * * *` for
08:00 daily) and then fires on its own — no external cron needed, the
container's fleet runs a scheduler daemon. Schedules are wall-clock in
`TASKLOOM_TIMEZONE` (required; an 08:00 schedule silently running at 08:00
UTC for a Chicago operator is not a thing this project does).

How it works, in one breath: the **cursor** (`task.next_run_at`, epoch
seconds) is the record of truth — an enabled scheduled task is *armed* on the
first tick (cursor = next occurrence) and *fires* when its cursor arrives.
Firing launches through `RunLauncher`, the same queue path as Run now, so a
scheduled run is carried by the worker lanes; the run's ledger records
`triggered_by = scheduled` (vs `manual`). The cursor advances by
**compare-and-swap**, so a due occurrence fires **at most once** even if two
ticks race; a **delayed tick catches the slot up** instead of skipping it; and
a due occurrence is **held** (still owed) while a previous run of the same
task is active, firing when it settles.

- Invalid cron expressions are refused when the task is written *and* when it
  is enabled — an invalid schedule never becomes a running task.
- A scheduled launch that fails at dispatch (a toolbox that no longer
  resolves) becomes a classified **failed run** in the ledger; the occurrence
  is consumed, not retried forever.
- The tick is one command — run it by hand, from cron, or not at all:

```bash
php bin/console app:schedule:tick     # one tick: arm / fire / report
php bin/console app:schedule:run      # the daemon (what the container runs)
```

In the container the entrypoint supervises the daemon like any worker
(`TASKLOOM_SCHEDULER_ENABLED`, `TASKLOOM_SCHEDULE_INTERVAL`); it shuts down
gracefully on SIGTERM — the current tick finishes, exit 0.

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

In the container, the entrypoint starts the fleet for you: `TASKLOOM_LLM_MAX_CONCURRENCY`
llm workers and `TASKLOOM_TOOL_MAX_CONCURRENCY` tools workers, restarted on exit.
Locally (or when working outside the container) run them by hand:

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
(purged queue, restored backup), by `php bin/console app:run:requeue` — which re-derives
both the owed turns and any lost step-graph advancement (a step child that was never
created, a final consumer that never ran, a settlement that never committed). Run state
lives in the `run` row + checkpoint, so any worker can pick up any turn.

## Configuration

See [`.env.example`](.env.example) — every variable documented
inline. Key knobs:

| Variable | Purpose |
|---|---|
| `TASKLOOM_ADMIN_PASSWORD` | The admin UI's sign-in password (the browser may keep it in localStorage for seamless re-login) |
| `TASKLOOM_MCP_API_KEY` | The MCP endpoint's bearer key — the credential external agents present (`Authorization: Bearer <key>`) |
| `TASKLOOM_LLM_BASE_URL` / `TASKLOOM_LLM_MODEL` | OpenAI-compatible endpoint (Ollama / vLLM / llama.cpp) |
| `TASKLOOM_LLM_MAX_CONCURRENCY` | Concurrent LLM requests — the container runs this many llm workers (1 on a single local GPU) |
| `TASKLOOM_TOOL_MAX_CONCURRENCY` | Concurrent tool-turn workers (default 2) |
| `TASKLOOM_TIMEZONE` | Wall-clock timezone cron schedules are evaluated in — also what the editor's schedule preview shows (required) |
| `TASKLOOM_SCHEDULER_ENABLED` / `TASKLOOM_SCHEDULE_INTERVAL` | Run the scheduler daemon in the fleet; tick interval (default 60s) |
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
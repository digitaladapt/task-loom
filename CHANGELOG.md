# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- **Task authoring in the admin UI (SPEC §8) — create and edit tasks from the
  browser, including the step graph and the schedule.** The last v1.x roadmap
  item: until now the admin surface covered a task's *lifecycle* (list,
  enable/approve/reject/archive, run, ledger) while authoring existed only as
  MCP tools and the console, so a human who wanted to change a task needed an
  agent or SQL. The editor is one form: title/brief/kind, the toolbox (by tag
  or by explicit tool, picked from the discovered catalog), an optional
  multi-level step graph with per-step toolboxes, and a schedule.

  **Authoring is gated for the human exactly as for an agent.** A save lands a
  *disabled draft* (SPEC §4.3) and enabling stays a separate, deliberate act
  from the task's page — the queue is a queue, not a formality. Editing an
  *enabled* task opens a replacement draft and says so on the page before
  anything is saved; the running task is never mutated (SPEC §4.4). The editor
  and the MCP tools now share **one gated write path** (`TaskCrud`, taught to
  record the author: `user` vs `agent`), so the two cannot drift — what the
  browser submits is valid authoring/wire format, accepted verbatim by
  `task_create`/`task_update`, and the tests assert exactly that.

  **Schedules are composed, not typed.** Presets (every N minutes, hourly,
  daily, weekdays, weekly, monthly) compose to cron *server-side*, so the
  picker, the preview, and the stored expression cannot disagree about what
  "every weekday at 6:30am" means; a live preview shows the composed
  expression, a plain-English sentence, and the next three occurrences in
  `TASKLOOM_TIMEZONE`. Composition and recognition are round-trippable, so a
  saved schedule reopens on the preset that produced it — while an expression
  no preset composed reopens as *custom* rather than being silently rewritten
  on the next save. Invalid cron is refused at the editor boundary with the
  same authority the enable gate uses (SPEC §14.5).

  A submission with problems is re-rendered with every problem anchored to its
  field and the human's work intact — never a redirect that loses it.

### Fixed

- **A step's toolbox checkboxes were never saved.** The step card rendered
  them as `steps[l][s][toolbox_tags[]]` — the empty bracket suffix landed
  *inside* the prefix's closing bracket instead of after it. That is not a PHP
  array: the form parser keeps `toolbox_tags[` as a literal key, so every tick
  in a step's tag or tool panel was silently discarded, while the
  comma-separated companion next to it kept working. A step authored by
  clicking catalog checkboxes therefore ended up with an empty toolbox (and an
  empty-toolbox preview warning), while the same action at task level worked.
  The names are now built from explicit variables (`name_tags`,
  `name_tools_extra`, …) with the suffix outside the scope bracket, and a
  functional test serializes the *rendered* form the way a browser submits it
  and runs it through PHP's form parser, so a name that renders but does not
  parse cannot pass again.
- **Each toolbox panel is seeded only from a declaration of its own mode.**
  Both free-text companions were seeded from the same stored list, so a
  tags-mode task reopened with its tags pasted into the "More tools" field —
  and switching the radio to "Explicit tools" then saving persisted those tags
  as tool names. Toggling back the other way did the mirror image. Now the tags
  field is seeded from a tags declaration and the tools field from an explicit
  one, each only with the entries the catalog cannot offer as checkboxes; the
  other panel's field stays empty until the human types in it.
- **An approved replacement presented itself as a pending proposal.** After
  the SPEC §4.4 swap the approved replacement *is* the live task, but it keeps
  its `replacement_for` pointer for the record's history, and the task detail
  page branched on that pointer alone. The result: the newly-enabled task
  showed **Approve replacement** / **Reject** where every other enabled task
  shows **Run now** — and the Reject button was both visible and dangerous. The
  branch now requires a *pending* draft (`replacementFor` and not enabled),
  and the entity refuses the two misdirected actions outright: `reject()` on
  an enabled task would have archived the task the swap just made runnable,
  and `approve()` on an already-approved replacement is refused rather than
  re-running the swap against a stale original.
- **No JavaScript ran anywhere in the admin UI: the strict CSP had no nonce
  for the app's own inline scripts.** `base.html.twig` renders the AssetMapper
  importmap and entrypoint import, which are inline `<script>` blocks by
  design, while `SecurityHeadersSubscriber` serves `script-src 'self'` with no
  `'unsafe-inline'` — so the browser blocked both. The visible symptom was a
  service worker that never registered; the latent one was that any scripted
  surface (the new step-graph builder) would have been dead on arrival. The
  policy was right and the delivery was missing its nonce: `CspNonce` mints
  one per request, the header names it, and the tags carry it. Responses with
  no inline script do not advertise a nonce they never use.
- **A CSS module published as a `data:` script was blocked by that same
  policy.** `assets/app.js` imported `styles/app.css`, which AssetMapper
  surfaces as an importmap entry spelled `data:application/javascript,…`; the
  browser loaded it as a *script* and the CSP refused it, so the entrypoint
  module never evaluated. The stylesheet is now a `<link>` — CSS is CSS — and
  a test asserts the importmap carries no `data:` entry.
- **A newly added step's toolbox could not be used.** The mode switcher bound
  its listeners per fieldset at load, so field sets that the step builder
  cloned into existence never got one: the explicit-tools panel stayed hidden
  and its checkboxes could not be ticked. Delegation from the form fixes it.
- **The schedule's preset fields are now hidden when the chosen preset does
  not use them.** An author rule setting `display` beats the `hidden`
  attribute, so a `[hidden] { display: none }` utility is needed for anything
  the editor toggles; without it "every 15 minutes" sat next to a day-of-month
  picker.
- **`SchedulePreset::compose()` read `HH:MM` backwards**, producing `06 30 *
  * *` (30:06) for a 06:30 schedule. Caught by the round-trip test, which
  asserts both the composed expression and the recovered time.
- **A declaration outside the catalog is no longer dropped on re-save.** The
  toolbox free-text field is seeded with the declared entries the catalog does
  not offer as checkboxes; previously, opening a task whose tools were not in
  the catalog and pressing save silently emptied its toolbox.
- `button.small` rendered at 14px, tripping the GUIDING-LIGHT §3.3a
  controls-16px check (the iOS auto-zoom trigger). Compact by padding, never
  by font-size.

### Changed

- **The deployment is one container: `serve` runs the whole application.**
  `docker/entrypoint.sh` now supervises the fleet — the web/MCP process plus
  `TASKLOOM_LLM_MAX_CONCURRENCY` llm workers and `TASKLOOM_TOOL_MAX_CONCURRENCY`
  tools workers — instead of asking the operator to run (and scale) two extra
  compose services. That variable was previously only a comment telling the
  operator how many containers to start; the entrypoint is now what actually
  reads it, so the count *is* the semaphore (SPEC §6). Workers are restarted
  with backoff when they exit; the web process is critical, so the container's
  lifetime and exit code follow it. Reasoned through in
  `docs/design/SINGLE_CONTAINER_RUNTIME.md`.
- **Both compose files were unbootable and now boot.** The image runs with
  dotenv disabled, so every `%env(...)%` the app resolves must be passed in —
  `DEFAULT_URI` and the run-engine budget variables were not, and
  `cache:warmup` failed on the way up. Compose files now pass the full
  contract, and a test derives the required list from `config/` so a newly
  added variable cannot be forgotten in them.
- **Migrations are wired, not remembered.** A one-shot `migrate` service runs
  before the app and the app waits for its success
  (`service_completed_successfully`). Still an explicit deployment step per
  §8.6 — a container that migrates at boot cannot be scaled — but
  `docker compose up` is once again a single command. The entrypoint verifies
  the schema is current before it starts the fleet, and fails naming the
  command to run if it is not.
- **MCP libraries: `php-mcp/client` + `php-mcp/server` (fork) → the official
  `mcp/sdk`** (pinned `0.8.1`). Upstream `php-mcp/server` has not been pushed to
  since 2025-08-09 and `php-mcp/client` since 2025-05-07; the server side was
  the same private fork context-shuttle had to maintain. The official SDK is
  that project's successor — same original author, now maintained with the PHP
  Foundation and Symfony. Reasoning and verified API mapping:
  `docs/design/MCP_SDK_MIGRATION.md`. This supersedes the `php-mcp/*` entries
  below, which describe the state before the migration.
- **The MCP server role moved in-app: `POST /mcp` is now a controller.**
  `app:mcp:serve` (a standalone ReactPHP socket server) is deleted. The SDK's
  HTTP transport is a PSR-7 request handler, not a web server, so the endpoint
  now shares one FrankenPHP process with the admin UI. **The route is
  admin-guarded** — it inherits `security.yaml`'s final rule (`^/ → ROLE_ADMIN`),
  so external agents authenticate with the admin credentials; its previous
  loopback-only, unauthenticated exposure is gone along with the process. No
  `access_control` exemption was added.
- **~380 lines of hand-rolled transport deleted.**
  `src/Toolbox/Transport/StreamableHttpTransport.php` (250 lines) and its
  `StreamableTransportFactory` (56) existed only because php-mcp/client's
  built-in transport opened a legacy HTTP+SSE stream (a GET) that Streamable
  HTTP servers answer with 405. The official SDK's transport POSTs, handles
  both JSON and SSE response framing, and manages the session header itself.
- `ToolExecutor` and `McpServerReader` now use `Mcp\Client` / `HttpTransport`.
  Every failure is still classified `ErrorClass::ServerError`; the SDK's
  `ToolCallException`/`RequestException` distinction is not surfaced to the
  ledger because the run engine's remedy is the same either way.
- `php-mcp/server` VCS repository URL moved to the public
  `code.digitaladapt.com/public/php-mcp-server` host — the `code.devgnome.com`
  name is LAN-only, so installs outside the LAN could not resolve it. (Moot
  after the migration above: the VCS repository entry is gone entirely.)

### Added

- **Scheduling (SPEC §14) — tasks can now run on a cron schedule.** `task.schedule`
  (the dormant v1 column) is live: an enabled scheduled task is armed on the first
  scheduler tick (cursor = next occurrence) and fires on it, through the same
  `RunLauncher` queue path as Run now — a scheduled run is born exactly like a manual
  one and is carried by the worker lanes. The cursor (`task.next_run_at`, epoch
  seconds) is the record of truth, advanced by **compare-and-swap**, so a due
  occurrence fires at most once even if two ticks race; a delayed tick catches the
  slot up instead of skipping it; a due occurrence is held (still owed) while a
  previous run of the task is active. `run.triggered_by` records `manual` vs
  `scheduled`, so "why did this run at 3am?" is ledger data. An invalid cron
  expression is refused at create/update *and* at enable/approve; a scheduled launch
  that fails at dispatch becomes a classified failed run, not a log line. Schedules
  are wall-clock in `TASKLOOM_TIMEZONE` (required env; named at boot when missing).
  New commands: `app:schedule:tick` (one tick) and `app:schedule:run` (the daemon the
  container fleet supervises; graceful SIGTERM shutdown).
- **`dragonmantank/cron-expression`** (the dependency SPEC §11 already approved) —
  cron parsing/validation and next-occurrence computation, evaluated in the
  deployment timezone.
- **`TASKLOOM_SCHEDULER_ENABLED` / `TASKLOOM_SCHEDULE_INTERVAL` /
  `TASKLOOM_TIMEZONE`** — the scheduler fleet knobs and the schedule timezone,
  documented in `.env.example`.
- **Container entrypoint (`serve`):** supervised single-container runtime —
  boot gates (env contract, schema), worker fleet + scheduler daemon,
  restart-with-backoff, signal-driven shutdown with a SIGKILL escalation window
  (`TASKLOOM_SHUTDOWN_TIMEOUT`), and `exec` pass-through for one-shot commands
  (`docker compose run --rm taskloom php bin/console …`). `lint:container
  --resolve-env-vars` runs before anything starts, so a missing variable fails
  at boot, named, rather than as a worker dying mid-run.
- **`TASKLOOM_TOOL_MAX_CONCURRENCY`, `TASKLOOM_WORKER_TIME_LIMIT`,
  `TASKLOOM_WORKER_MEMORY_LIMIT`, `TASKLOOM_SHUTDOWN_TIMEOUT`,
  `TASKLOOM_MIGRATE_ON_BOOT`** — the fleet and boot knobs, documented in
  `.env.example`.
- **Tests for the container contract** (`tests/Container/`): the supervisor
  suite drives the real entrypoint against stub `php`/`frankenphp` executables
  (fleet composition, crash restart, web-exit propagation, TERM propagation,
  escalation, boot gates); the deployment suite asserts the compose files
  against the app's actual env requirements. No Docker daemon required.
- **Run surface (SPEC §8):** the admin UI now covers the whole run lifecycle.
  `GET /runs` is the scheduler view (who holds an execution claim — the claim
  *is* the wire slot, with its lane and staleness) over the run history;
  `GET /runs/{id}` is the per-run attempt ledger — a filterable timeline
  (`?error_class=…`), the full transcript reconstructed from the ledger plus
  the frozen prompt head, the completion artifact or failure reason, the
  frozen toolbox, and (for a stepped task) the step graph grouped into levels
  with the final consumer labelled; `GET /attention` is the attention queue
  grouped by error class, parents carrying their failing step's diagnosis
  (SPEC §13.5). **Run now** — the only trigger in v1 — is a POST on the task
  page: it launches through the same queue path as `app:run:now --queue`
  (one shared `RunLauncher`, so the triggers cannot drift) and redirects to
  the run. The task list shows each task's latest run; the task detail links
  the run surface.
- `App\RunEngine\RunLauncher` — the shared queue-path launch (create the
  run, dispatch its first turn), used by both the CLI and the web trigger.
- `RunRepository::findRecent()` / `findLatestForTasks()`; the attention queue
  now returns top-level runs only (graph children render inside their
  parent's page).
- `RunEventRepository::findTimeline()` takes an optional error-class filter;
  `distinctErrorClasses()` powers the filter control.
- `App\Controller\McpController` — the MCP endpoint (SPEC §§10–11).
- `docs/design/MCP_SDK_MIGRATION.md` — what changed and why.
- `mcp_sessions` cache pool, for MCP session storage.
- `tests/Functional/Mcp/Server/TaskMcpServerEndToEndTest`: two new cases — a
  request without a session is rejected (`400`/`-32600`), and an
  unauthenticated request is rejected (`401`).
- **Run concurrency (SPEC §6):** the run engine is turn-based — one LLM request per
  `LlmTurnMessage` on the `llm` lane, one exchange's tool calls per `ToolTurnMessage` on
  the `tools` lane (Symfony Messenger, Doctrine transport, one shared
  `messenger_messages` table). `N` `llm` workers = `N` concurrent LLM requests: the
  worker count IS the `TASKLOOM_LLM_MAX_CONCURRENCY` semaphore. `app:run:now --queue`
  enqueues; `app:run:requeue` recovers runs whose message was lost (purged queue,
  restored backup).
- Runs carry an execution claim (`run.lock_version`/`claim` timestamps) so duplicate
  deliveries cannot execute a turn concurrently; a dead worker's claim is taken over
  after an hour, below the transport's redeliver timeout.
- `run.checkpoint` now persists the full loop state (exchange window, failure streaks,
  budgets, compiled prompt head, in-flight tool turn), so any fresh worker — or the
  next consumer of a redelivered message — can pick a run up mid-turn and a run
  finishes under the budgets it started with.
- Initial repository scaffold: Symfony 8.1 (PHP 8.5) skeleton, vendored configs
  (phpstan, php-cs-fixer, editorconfig), Dockerfile (FrankenPHP, non-root),
  compose example, docs/examples with inline-documented .env.example, CI workflow
  (lyra/ci php-test.yaml@v1), docs/design trio (SPEC, DESIGN_CONSIDERATIONS, ROADMAP).
- Compose example ships the application as one container (web + MCP endpoint +
  supervised worker fleet) with a one-shot migration service ahead of it; the
  separate `worker-llm` / `worker-tools` services this example used to define
  are gone — see the entrypoint change above.

### Removed

- `src/Command/McpServeCommand.php`, `src/Toolbox/Transport/*` (both files),
  and the `php-mcp-server` VCS `repositories` entry in `composer.json`.
- Runtime dependencies: `php-mcp/client`, `php-mcp/server`, `php-mcp/schema`,
  `react/http`, `react/async`, `fig/http-message-util`.

### Fixed

- **Secured MCP servers now authenticate: `cred_var` is resolved to an
  `Authorization` header.** A server's `cred_var` names an env var (SPEC §7),
  but nothing ever read it: both client call sites built a bare transport, so a
  server that required a credential answered every catalog sync with a 401 and
  the sync reported a plain connection failure. `App\Toolbox\CredentialResolver`
  now reads the named variable from the environment at sync AND call time — for
  both MCP servers (`McpServerReader`, `ToolExecutor`) and OpenAPI servers
  (`OpenApiServerReader`, whose spec endpoint is guarded the same way) — and
  sends it as `Authorization` — a bare token becomes `Bearer <token>`, a value
  that spells its own scheme (`Bearer …`, `Basic …`) is sent verbatim. The value
  is resolved from the process environment, never stored or logged; the run's
  frozen toolbox snapshot carries the variable NAME only. A missing/empty
  variable fails loudly with a message naming the variable, rather than sending
  an unauthenticated request.
- `TaskMcpServerEndToEndTest` no longer spawns a background process and hunts
  for a free port. Its own docblock recorded the pain — a fixed port "invites
  collisions with leaked processes from earlier runs", and a plain
  "port accepts connections" probe once passed against a stale leftover process
  from an unrelated test. It is now a `KernelBrowser` request: no process, no
  port, no readiness poll, and it runs in ~0.4s.
- Vulnerability reports now go to `security@digitaladapt.com`.

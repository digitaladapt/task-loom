# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- **Chat — conversations (SPEC §15).** The first cut of the thing the capacity
  design was for: you can hold a conversation with the assistant, and it goes
  to the front of the queue to answer you.

  Three nouns, and no fourth: `Chat` (the conversation), `ChatExchange` (one
  execution over it — the run-analog), `ChatExchangeEvent` (one row in its
  ledger). An inbound message is the trigger; everything until the reply
  concludes is one exchange; each thing inside it is one typed event. There is
  deliberately no turn table — the transcript is a filtered read over the
  ledger, so a turn *is* an event.

  **Attribution is a safety invariant, not a formatting choice.** Every turn
  carries its speaker in typed columns and reaches the model on the role the
  roster assigns (`andrew → user`, `nia → assistant`). Flatten a two-party
  transcript and it breaks in both directions: the assistant's own past output
  reads as instructions she then follows (a prompt-injection surface grown
  inside her own history, in a system where she holds tools), or the human's
  instructions read as her own prior words and may not be followed. You never
  type your name — "morning" arrives as a turn *from Andrew*, attributed by
  the pipeline at the render layer.

  **The chat lane is the priority head** (SPEC §15.3). The LLM workers now
  consume `chat llm`, and `messenger:consume` is strict-priority across its
  receivers — it drains them in the order listed, every iteration — so a
  queued reply is taken before any task turn the same worker could have taken.
  No new process, no lock, no async runtime. It is worth being precise about
  what this does *not* buy: `TASKLOOM_LLM_MAX_CONCURRENCY` is the **total** in
  flight, so at the default of 1 a chat goes *next*, never *now*, and waits
  out at most one generation. Sub-second preemption needs a streaming client
  that can abort mid-generation — that is not built, and the client is still
  deliberately non-streaming.

  **A failure is loud.** A run that throws lands in `failed` and the run page
  shows it; a chat turn that throws has a person staring at it. So a failed
  exchange is classified in its ledger and the surface says so out loud —
  "I couldn't get a turn" with the reason. It is deliberately not
  re-dispatched: the human's next message is the retry.

  **Shared machinery, not shared tables.** The ledger row is a
  `MappedSuperclass` both event tables extend, so they are layout-identical by
  compiler rather than by convention; the claim protocol is written once
  against a closed set of claimable aggregates (`App\Claims\ClaimStore`). The
  run tables are *not* reused, and §15.6 records why: `Run.task_id` is NOT NULL
  and a synthetic "Internal: Chat" task would be a lie at the centre of the
  schema, while `findRecent()`/`findAttention()` filter on `parent IS NULL`
  only, so chat rows would appear in the run history and the attention queue
  *silently*.

  **Boot recovery covers chat too.** `ClaimReaper` sweeps every claimable
  aggregate and `serve` runs `app:chat:requeue` beside `app:run:requeue
  --startup`. An abandoned exchange is worse than an abandoned run in one
  respect — a human is waiting for an answer that will not come — so the boot
  line reports the split.

  Respond-only, with the seam left: `ChatExchange.triggered_by` exists now
  with `inbound` as its only value, so the assistant opening a conversation
  later is a new enum case rather than a migration. Alerts, streaming, and
  initiation are not built (`docs/design/CHAT_AND_CAPACITY.md` §8).

- **Boot recovery: a restart no longer costs an hour of recovery latency**
  (SPEC §6.2). A fleet that is *killed* rather than *stopped* — SIGKILL, OOM,
  power loss, or the shutdown-window escalation described in
  `SINGLE_CONTAINER_RUNTIME.md` — leaves execution claims behind that no
  process will ever release. The engine's answer was the staleness window
  (`CLAIM_STALE_SECONDS`, one hour): always safe, and an hour of a run sitting
  idle. At boot there is a better answer, because the process that holds the
  claim is *known* to be gone — the fleet that ran it is the fleet being
  restarted.

  `app:run:requeue --startup` clears the abandoned claims and then re-derives
  and dispatches what those runs are owed. The container's `serve` path runs it
  after the schema gate and **before any worker starts**, and the ordering is
  load-bearing in both directions: the reap is only sound while no worker
  exists (once one is consuming, its claim is indistinguishable from a
  corpse's), and the requeue must precede the spawn (a run owed on a lane no
  consumer has reached is the state being repaired). On the ordinary path — a
  clean stop, where workers finish their message and release — it reports zero
  and moves on.

  **The authority is granted, not inferred.** "We are booting, so nobody can be
  working" is a statement about a process group, not about the run table, and
  this repo already ships a process that would be wrong about it: the one-shot
  `migrate` service. So the reap happens only when the entrypoint's `serve`
  path sets `TASKLOOM_FLEET_OWNER=1`; everyone else — the migrate service, an
  ad-hoc console command, a UI-only deployment sharing a database with a host
  still running workers — does nothing and says so. An unrecognized value fails
  closed *and is reported*, because a deployment must not be able to believe a
  sweep is armed when a typo disarmed it.

  **The sweep decides on what could still be in flight** — after two wrong
  answers, both of which the field killed within a day. (That history is kept
  because each version was plausible and the reason each failed is the useful
  part.)

  *First* it inferred abandonment from claim age, reusing the engine's
  `CLAIM_STALE_SECONDS` lease. Safe, and useless: a container `down`'d and `up`'d
  inside a minute leaves claims seconds old, so the sweep cleared nothing while
  the requeue beside it dispatched the owed turns — and every delivery was then
  dropped on arrival, because taking over a claim requires the same hour.

  *Second* it tried per-start identity: record which fleet took each claim
  (`run.claim_fleet`) and clear the ones stamped with the previous start's id.
  That cannot work. Every restart is a new identity, so **every** leftover claim
  is "another fleet" and the sweep declines all of them by construction — the
  boot line said exactly that (`0 cleared …, 2 held by another fleet`). The bug
  was visible in the test written to prove the mechanism, which passed the same
  id on both sides, a situation that never occurs in production.

  *Now* it asks **could anyone still be holding this?** The sweep runs before
  any worker starts — the one moment the question has a clean answer — and a
  claim is held for exactly one message, so nothing legitimately holds one for
  long. A claim older than `TASKLOOM_FLEET_GRAB_AFTER` is therefore one whose
  owner is gone. The default is `0` (any age), correct for the single container
  in SPEC §6. Raise it to `TASKLOOM_LLM_TIMEOUT + 60` only if more than one
  fleet shares the database, where a claim found at boot may belong to a peer
  working right now. The boot line always prints the bound in force, so a slow
  recovery can be told from a broken one:

  ```
  Claims: cleared 2 (any age), 0 left to the lease, 0 of those without an owner label.
  ```

  `run.claim_fleet` is kept as a *label* (and the count of cleared claims that
  had none is reported), because it makes a leftover claim legible after the
  fact even though it no longer decides anything.

  **Bugs this turned up on the way, all of which made the feature look like it
  worked while it did not:** `TASKLOOM_SHUTDOWN_TIMEOUT`,
  `TASKLOOM_WORKER_TIME_LIMIT` and `TASKLOOM_WORKER_MEMORY_LIMIT` were
  documented in `.env.example` but never forwarded by a compose file, so setting
  them was silently inert and a stop always used the 30s fallback (a test now
  pins every variable the entrypoint reads against the compose files);
  `stop_grace_period` is now 90s, so the outer Docker bound cannot undercut the
  entrypoint's inner window and SIGKILL a fleet that is still winding down; and
  a dropped delivery that loses a race to a **live** claim now logs at `warning`
  rather than `debug`, which prod's `fingers_crossed` buffering was discarding —
  the boot log could read "3 run(s) requeued" while all three were stuck.

  And the data-directory derivation had the placeholder bug it was written to
  avoid: `DATABASE_URL` carries `%kernel.project_dir%` **literally** (the
  application resolves it at runtime, not the environment), so treating it as
  ordinary path text created a directory actually named
  `%kernel.project_dir%` and published the fleet identity into it. Caught by
  the test that asserts the file is written *where it is supposed to be* — a
  best-effort write needs a test that the effort succeeded, or "best-effort"
  becomes "never".

  **Measured while diagnosing the above, and now recorded in the design note
  because it changes what S2 needs:** a `pcntl` signal handler on the worker
  runs promptly *inside* a blocking `chat()` call, and if it **throws**, the
  exception unwinds out of the HTTP client and aborts the transfer (30s stall →
  2.00s, twice). A handler that merely sets a flag does **not** — the signal is
  delivered and PHP returns to the socket and waits out the full timeout. So an
  interruptible turn does not need the `on_progress` callback or a file-based
  control plane that `GRACEFUL_RESTART.md` assumed; the signal is enough. The
  remaining work is to *use* it (see the design note), which is deliberately not
  in this change — at present a stop that lands during an LLM call still ends in
  SIGKILL once the shutdown window expires, and the claim is then cleared on the
  next start by the sweep above.

- **`serve --no-web`** — the worker fleet and the scheduler without the web
  process, for a host that is only there for the model. Same fleet, same
  supervisor, same shutdown path; it still owns the fleet (so it may run the
  boot sweep) and it refuses to start if that would leave it supervising
  nothing. Any other argument after `serve` is refused by name rather than
  ignored. See `docs/design/SINGLE_CONTAINER_RUNTIME.md`.

### Fixed

- **A task that has run is now immutable, so disabling it no longer re-opens
  it for editing (SPEC §4.4, §13).** The rule was always "once a task is
  enabled it is a record", but the write gate asked `isEnabled()`, and
  disabling clears that flag. A task that was enabled, misbehaved, and was
  disabled to fix it therefore came back to the editor looking like an
  untouched draft: the save went through the **in-place** path, and
  `replaceSteps()` deleted the task's step rows — including the one
  `run.step_id` still pointed at. The task's own page could then not be loaded
  at all ("Entity of type `App\Entity\Step` for IDs `id(71)` was not found"),
  because the run surface pulls a task's runs by `task_id` and one dangling
  step reference is fatal to the whole render.

  The gate now asks whether the version is a **record**, and a run makes it
  one: `Task::isContentLocked()` is `enabled || hasRuns`, and that is what
  `TaskCrud::update()` consults. A disabled task with run history gets a
  replacement draft, exactly as an enabled task does; only a never-enabled,
  never-run draft is edited in place. Everything downstream of the old premise
  moved with it — the entity's content guard, `Step`'s mutations, the
  editor's notice and flash, and the `task_list`/`task_get` status, which no
  longer calls a paused task with history a "draft".

  **The answer comes from the store, not from the entity.** `hasRuns` is
  deliberately *not* a column: the runs table is the record of it, and the
  write gate reads it there (`TaskRepository::hasRuns()`) at the moment of the
  write. A replacement draft does **not** inherit its original's runs — that
  is what makes a draft editable into shape before approval, and safe, because
  grants and reclaims are the only content mutations in the codebase and every
  one of them replaces the frozen original instead of touching it.

  Also fixed in passing, and found by the new test: `replaceSteps()` now drops
  a task's runs' references to step rows that no longer exist, in the same
  transaction. `run.step_id` is declared `ON DELETE SET NULL`, but SQLite does
  not enforce foreign keys unless `PRAGMA foreign_keys` is on and nothing sets
  it (the connection middleware sets `journal_mode` only), so the action has
  never actually run. The sweep keeps the ledger loadable without depending on
  that pragma; turning it on belongs with the other connection-level settings,
  and is worth doing separately.

- **The boot sweep no longer reaps claims on a lane this fleet does not consume**
  (SPEC §6.2). The sweep's soundness rests on "no worker in this process group
  can be mid-turn", and at the default `TASKLOOM_FLEET_GRAB_AFTER` that premise
  clears *every* claim in the table. But it was gated on the fleet-owner flag,
  which is coarser than the premise: `serve` sets it whenever it starts any
  worker, including `serve --no-web` with `TASKLOOM_LLM_MAX_CONCURRENCY=0` —
  the worker-fleet-with-no-model shape, where the llm lane is consumed by a
  peer in another container. Such a process would clear claims that peer was
  holding, re-dispatch its runs, and reset `lock_version`; the peer's committed
  turn is then discarded as `Stale` and the work is done twice, live side
  effects and all. The same hole existed on the tools lane (worst there, since
  that is where the side effects are). The sweep now runs only when the fleet
  consumes both lanes, and a `serve` that does not says so out loud — a quietly
  skipped sweep would be indistinguishable from a sweep with nothing to do.
  Nothing is stranded: the single container's stop-time failure is repaired by
  its successor, which runs the full fleet. Operators who want the sweep on a
  partial fleet set `TASKLOOM_FLEET_GRAB_AFTER` above the longest turn. Found by
  asking whether a fleet with no local llm worker could steal a peer's job.

- **A tool call the model repeats inside one turn is dispatched once**
  (SPEC §5.1, §5.3). A local model sometimes asks for the same call twice
  (or four times) in a single assistant turn — the same tool with the same
  arguments. The engine dispatched every repeat, so the run paid a duplicate
  ledger row and a whole extra tool round-trip for an answer it already had.
  On a single local GPU that wall-clock is the scarce resource, so the
  repeats are now dropped before dispatch.

  **It is a within-turn rule, deliberately.** Only exact repeats in the
  *same* assistant message are removed; the same call in a *later* turn is
  left alone, because that can be a legitimate poll for state that has
  changed (the digest makes the same distinction between waste and polling).
  Arguments are compared as canonical JSON — key order is not semantic
  difference — the first occurrence wins, and it keeps its original call id,
  so the replayed assistant message still matches the tool results exactly
  (which the endpoint requires).

  **The drop is recorded, not swallowed.** Each drop logs an `info` line
  naming the run, step and tools. But a log line is not enough on its own:
  the production handlers are `fingers_crossed` at `action_level: error`, so
  under normal operation an `info` line is buffered and discarded. The
  durable record is the ledger: the `llm_response` event now carries the
  model's full `toolCalls` list **and** a `droppedDuplicates` list, so
  "did the model repeat itself, or did the harness double-fire?" is
  answerable from the record. `run_review`'s funnel gains a
  `dropped_duplicates` count, and `run_read_log`'s `tool_args` entries carry
  the `tool_call_id` — two rows sharing one id is a harness double-fire, two
  rows with different ids is the model repeating itself.

### Added

- **`TASKLOOM_DEBUG_RAW_LLM` dumps the raw LLM response for debugging.**
  Off by default. Set it (`1`/`true`/`on`) and every response body is logged
  **before any parsing**, so "the model is being silly" and "our parsing is
  being silly" can be told apart from the wire bytes — including a body the
  parser rejects. The dump includes the full assistant turn and any
  reasoning, which is why it is opt-in rather than always-on. A value that
  is not a recognized boolean fails loudly at boot rather than leaving the
  operator believing a dump is running when it is not.

- **Disable an enabled task — the pause (SPEC §4.4, §8).** An enabled task
  now has a **Disable** action on its detail page. Disabling is a lifecycle
  flag, not a content edit: it is the one mutation an enabled record is
  allowed, and it is what "stop this for now" actually needed. The task
  keeps its title, brief, toolbox, schedule, and run history; it simply
  stops being runnable. Run now refuses it ("not enabled", SPEC §4.2) and
  the scheduler tick skips it, because both read the same `enabled` flag
  (`findRunnable()`). **Enable** brings it straight back, and the tick
  re-arms it on its schedule like any fresh enable.

  **It is a pause, not a discard.** Archiving a draft (the approval queue's
  "Discard draft") is a dead record and stays that way; the disabled flag is
  the reversible stop. The two are kept distinct in the UI: the detail
  status reads `disabled` for a task that has run and been paused (never
  `draft`, which would imply it was never live), and the "Discard draft"
  button is not offered for a task with run history — a paused record is
  worth keeping.

  **One predicate, so the UI and the guards cannot disagree.**
  `Task::isPendingReplacement()` is now the single question the approval
  actions and the lifecycle guards ask ("is this a proposal still awaiting a
  decision?"). An approved replacement keeps its `replacementFor` pointer
  for the record's history and can now be disabled and re-enabled, so
  `replacementFor !== null` alone is not "pending" — the predicate also
  checks `enabled`, `archivedAt`, and whether the original was superseded.
  `reject()` gained the matching superseded-original guard, so a stale
  reject aimed at an approved-then-paused task is refused rather than
  archiving a live record (SPEC §4.4).

- **The run prompt is tunable, and the grounding block finally tells the
truth about time, units and place (SPEC §4.1, §4.2).** Five deployment
knobs and two real bugs.

  **Grounding was reporting UTC to everyone, and metric to everyone.** The
  block was autowired with no arguments, so it rendered
  `new DateTimeImmutable('now')` in the *container's* zone: a deployment
  whose `TASKLOOM_TIMEZONE` says `America/Chicago` still told every run it
  was 09:15 (UTC). A model that reads "today" off the block stamps the
  deliverable with a date the operator is not on — the quiet-wrongness the
  scheduler's timezone rule exists to prevent, leaking back in through the
  prompt. `Grounding` is now constructed with `TASKLOOM_TIMEZONE` and
  reports that zone's wall clock; units come from `TASKLOOM_UNITS`
  (`metric`/`imperial`, unset → metric); and `TASKLOOM_LOCATION` supplies
  the block's `Location:` line (free text, unset omits it, blank counts as
  unset so an empty forwarded variable adds nothing). A value spanning
  lines is refused: the block's promise is a fixed shape, one fact per
  line, and a newline would let one knob forge what read like additional
  harness-authored lines. Every knob fails
  closed and names its variable on a bad value. The clock fix is also
  structural: the constructor takes its timezone as a required argument, so
  the old no-argument autowiring cannot compile — there is no silent
  default left to fall into.

  **The prompt's shape is now explicit and mostly fixed.** Sections render
  in a defined order — preamble → `## Task` → `## Inputs` → `## Toolbox` →
  `## Completion` → `## Grounding` — with grounding **last**, nearest the
  model's first reply, since most of what a run states back is stamped with
  the date, time, zone and units. Three parts are configurable because
  their wording depends on the deployment rather than on the engine:
  `TASKLOOM_SYSTEM_PROMPT`/`_FILE` (the preamble),
  `TASKLOOM_COMPLETION_PROMPT`/`_FILE` (the text under `## Completion`),
  and `TASKLOOM_PROMPT_TOOLBOX_LIST` (whether the toolbox summary renders).

  Each text knob takes an inline value **or** a file; setting both is
  refused (two sources for one value is ambiguous), a missing file and an
  empty file are refused by name, and unset means the built-in text — so no
  deployment's prompt *wording* changes on upgrade unless it opts in. The
  **order** does change for everyone, deliberately: grounding moves from
  second to last, which is the whole point of putting it nearest the model's
  reply. The completion *rule* is not configurable either: the engine still
  refuses a contentless terminal message and still fails closed into
  `incomplete` at the step budget. The toolbox toggle is prose-only: tool
  definitions are sent on every request regardless.

  The deployment contract test also got stricter while wiring this up: it
  discovered only plainly-spelled `%env(NAME)%` variables, so a knob behind
  a processor prefix (`default::`, `int:`…) could be added to config and
  never be forwarded by the compose files — silently inert in a container,
  where dotenv is disabled. It now discovers the name under any prefix,
  holds required variables and optional ones to the standard each deserves,
  and skips Symfony's own plumbing (`SYMFONY_*`, `TEST_TOKEN`) rather than
  demanding it in compose.

- **One menu bar on every page (SPEC §8).** Until now each template grew its
  own `<nav>` with whatever links that page happened to need: the tool
  catalog was reachable from exactly one page, the attention queue from two,
  and a page's "where can I go from here" depended on where you already
  were. The bar replaces all of them — the same five sections (`Tasks`,
  `Runs`, `Attention`, `Tool catalog`, `New task`) plus the sign-out control,
  rendered once from `base.html.twig`, so a new page cannot forget it and a
  link cannot drift between pages.

  **It says where you are, and "where" is precise.** The item matching the
  current route carries `aria-current="page"`; on a sub-page (`/tasks/47`,
  `/tasks/47/edit`) the containing section carries `aria-current="true"`
  instead, because the Tasks item does not point at that page. Marking the
  section `page` when it navigates elsewhere is exactly the small lie the
  attribute exists to avoid.

  **Mobile-friendly without depending on JavaScript.** The header is a real
  flex layout at every width: two rows with a `Menu` disclosure under 40rem,
  one row with inline links above it. The toggle is progressive
  enhancement — the markup ships the panel and the control, `assets/menu.js`
  (loaded with the shell entrypoint) adds the collapsible state, and CSS
  hides the toggle until the script claims the header. A browser that never
  runs the script gets a plain, fully visible navigation list and no button
  that does nothing. Tap targets are 44px and labels stay at 16px
  (GUIDING-LIGHT §3.3a), and Escape closes the panel and returns focus to
  the control.

  Contextual links are *not* navigation and did not move: the task editor
  keeps its "back to this task" link, since that is about the page's own
  parent rather than the app's sections.

### Changed

- **The tool catalog and the task editor's tool picker now list tools by
  server, then tool name.** A single `ToolRepository::findAllOrdered()`
  backs every human-facing list — the catalog page, the task-level picker,
  and every step's — so a tool sits in the same position wherever the
  operator meets it, and one server's tools stay together instead of
  interleaving alphabetically with every other server's. Name-only order put
  `weather.get_forecast` between `calendar.create_event` and
  `calendar.list_events`, which is not an order a person can scan.

  The run engine's resolution passes (`ToolboxResolver`, `ToolboxPreviewer`)
  and the tag listing share it too, so a toolbox preview, the frozen
  snapshot, and the picker that authored the declaration all present the
  same tools in the same order rather than three opinions about it. Tags
  stay alphabetical: they are a flat vocabulary with no server axis.

- **Browser sign-in for the admin UI, a bearer key for MCP — HTTP Basic is
  gone (SPEC §4.3).** Two front doors, two credentials, both fail closed:
  - **The UI is a session now.** `GET /login` renders a one-field password
    form; a successful POST establishes a session and `/logout` ends it.
    "Stay signed in on this device" keeps the password in the browser's
    localStorage and trades it for a session through `POST /login/api-key`
    (CSRF-required, constant-time comparison) — a returning visitor is
    signed in seamlessly, and a failed attempt clears the stored value so a
    changed password cannot loop. `assets/auth.js` is the small module that
    does both, loaded on every page.
  - **The MCP endpoint authenticates with `Authorization: Bearer
    <TASKLOOM_MCP_API_KEY>`.** A new, dedicated variable — deliberately not
    the admin password, so the agent credential rotates without touching the
    human login (and vice versa). The endpoint got its own *stateless*
    firewall (`^/mcp`), so a UI session cookie cannot be replayed against it
    and the key cannot reach the UI; failures answer with a
    `WWW-Authenticate: Bearer` challenge. The old HTTP Basic flow is removed
    — MCP client configs must migrate to Bearer (README has the note).

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

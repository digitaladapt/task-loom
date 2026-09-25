# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed

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
- Compose example ships `worker-llm` / `worker-tools` services (same image); scale
  `worker-llm` to the concurrency setting.

### Removed

- `src/Command/McpServeCommand.php`, `src/Toolbox/Transport/*` (both files),
  and the `php-mcp-server` VCS `repositories` entry in `composer.json`.
- Runtime dependencies: `php-mcp/client`, `php-mcp/server`, `php-mcp/schema`,
  `react/http`, `react/async`, `fig/http-message-util`.

### Fixed

- `TaskMcpServerEndToEndTest` no longer spawns a background process and hunts
  for a free port. Its own docblock recorded the pain — a fixed port "invites
  collisions with leaked processes from earlier runs", and a plain
  "port accepts connections" probe once passed against a stale leftover process
  from an unrelated test. It is now a `KernelBrowser` request: no process, no
  port, no readiness poll, and it runs in ~0.4s.
- Vulnerability reports now go to `security@digitaladapt.com`.

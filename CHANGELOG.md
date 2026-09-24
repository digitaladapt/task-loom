# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
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
- `php-mcp/client` ^1.0 and `php-mcp/server` (via our fork
  `public/php-mcp-server@feat/symfony-8-support` — relaxes the symfony/finder
  constraint to allow ^8.0; upstream has not released Symfony 8 support) for the
  harness's dual MCP role: client to tool servers, server for task tools.
- Compose example ships `worker-llm` / `worker-tools` services (same image); scale
  `worker-llm` to the concurrency setting.

### Changed
- `php-mcp/server` VCS repository URL moved to the public
  `code.digitaladapt.com/public/php-mcp-server` host — the `code.devgnome.com`
  name is LAN-only, so installs outside the LAN could not resolve it. The
  pinned commit is unchanged (`715b462`).
- Vulnerability reports now go to `security@digitaladapt.com`.
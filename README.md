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
- **Local-first concurrency.** `LLM_MAX_CONCURRENCY` semaphore on LLM wire time; tasks
  interleave naturally during tool I/O.

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

## Configuration

See [`.env.example`](.env.example) — every variable documented
inline. Key knobs:

| Variable | Purpose |
|---|---|
| `TASKLOOM_LLM_BASE_URL` / `TASKLOOM_LLM_MODEL` | OpenAI-compatible endpoint (Ollama / vLLM / llama.cpp) |
| `TASKLOOM_LLM_MAX_CONCURRENCY` | Concurrent LLM requests (1 on a single local GPU) |
| `TASKLOOM_STEP_BUDGET` | Max tool-call exchanges per run (fail closed) |
| `TASKLOOM_CONTEXT_LIMIT` | Context window for the fail-closed token budget |

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
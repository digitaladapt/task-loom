# Contributing to TaskLoom

Thanks for your interest in contributing.

## Ground rules

- **Design docs are the source of truth.** Read `docs/design/SPEC.md` before changing
  anything the spec covers. Disagreements are settled in
  `docs/design/DESIGN_CONSIDERATIONS.md` — update the doc, then the code.
- **The three verification tiers apply to every PR:** the shared rules
  (`guiding-light/docs/STRUCTURE-FOR-NEW-PROJECTS.md`), the vendored configs (never
  hand-edited — changes land via `sync-configs.sh`), and `.ci/conformance.sh`.
- **PHPStan baseline never grows.** Fix violations, don't baseline them.
- **Non-Symfony dependencies need sign-off first.** Open an issue describing what you
  need and why the Symfony-native alternative (if any) doesn't fit.

## Setup

```bash
composer install
cp .env.example .env
php bin/console doctrine:migrations:migrate
```

## Before you push

```bash
composer lint && composer stan && composer test
.ci/conformance.sh --profile=web-app
```

CI runs the same suite via `lyra/ci` reusable workflows plus `composer audit`.

## Commit style

Subject line imperative, ≤72 chars; body explains *why* when non-obvious.
Reference design doc sections (`SPEC §x.y`) when a change touches a covered behavior.
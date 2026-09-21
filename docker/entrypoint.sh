#!/bin/sh
#
# TaskLoom container entrypoint.
#
# Responsibilities:
#   1. Warm the prod cache with the injected secrets.
#   2. Hand off to CMD (FrankenPHP server, or a console worker override:
#      `docker compose run taskloom php bin/console messenger:consume`).
#
# Migrations are NOT run here — GUIDING-LIGHT §8.6: schema changes are an
# explicit deployment step (`docker compose run --rm taskloom php bin/console
# doctrine:migrations:migrate --no-interaction`), not a per-boot side effect.
# Running them from every container breaks the moment a second replica or
# worker boots against the same volume.
#
# Secrets are env vars injected at runtime, never baked into images (§8.12).

set -e

if [ "${APP_ENV:-prod}" = "prod" ]; then
    echo "Warming cache..."
    php bin/console cache:warmup

    # Tool catalog boot sync (ROADMAP §2): refreshes discovered tools and
    # records per-server status. Deliberately non-fatal — a down server
    # never blocks boot and never wipes known tools (SPEC §7). Disable with
    # TASKLOOM_SYNC_ON_BOOT=0 (e.g. for worker-only containers).
    if [ "${TASKLOOM_SYNC_ON_BOOT:-1}" = "1" ]; then
        echo "Syncing tool catalog..."
        php bin/console app:catalog:sync || echo "warning: tool catalog sync reported failures; boot continues with known tools"
    fi
fi

exec "$@"

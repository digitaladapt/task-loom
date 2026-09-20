#!/bin/sh
#
# TaskLoom container entrypoint.
#
# Responsibilities:
#   1. Run migrations (idempotent) so the schema matches the image.
#   2. Warm the prod cache with the injected secrets.
#   3. Hand off to CMD (FrankenPHP server, or a console worker override:
#      `docker compose run taskloom php bin/console messenger:consume`).
#
# Secrets are env vars injected at runtime, never baked into images (§8.12).

set -e

if [ "${APP_ENV:-prod}" = "prod" ]; then
    echo "Running migrations..."
    php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
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

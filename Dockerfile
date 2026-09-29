# syntax=docker/dockerfile:1.7
#
# TaskLoom — one app, one image, one container at runtime.
#
# The admin UI and the MCP task-tools server share the FrankenPHP process
# (`POST /mcp` is an app route), and the run engine's worker fleet is started
# from this same image by docker/entrypoint.sh: `serve` supervises the web
# process plus N `llm` and M `tools` Messenger workers (SPEC §6). The image is
# production-shaped: no dev dependencies, APP_ENV=prod.
#
# Runtime: FrankenPHP (non-root, SQLite WAL on a volume); TLS is terminated
# upstream of the container and FrankenPHP serves :80.

# ── Stage: deps — composer dependencies (layer-cached) ─────────────────────
FROM dunglas/frankenphp:1-php8.5-trixie AS deps

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# unzip: extraction of dist archives. git is not required — every locked
# package resolves to a dist archive (the fork that needed a VCS repository
# was removed with the mcp/sdk migration).
RUN apt-get update \
    && apt-get install -y --no-install-recommends unzip \
    && rm -rf /var/lib/apt/lists/*

COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist \
    --optimize-autoloader --no-scripts

# ── Stage: build — full app + prod autoloader ──────────────────────────────
FROM deps AS build

COPY . .

RUN composer dump-autoload --classmap-authoritative --no-dev \
    && APP_ENV=prod APP_DEBUG=0 APP_SECRET=build-secret \
    APP_RUNTIME_OPTIONS='{"disable_dotenv":true}' php bin/console asset-map:compile \
    && rm -rf var/cache/* var/log/*

# No cache warmup here: the prod container resolves its deployment secrets
# (DATABASE_URL, APP_SECRET, …) at boot, so a warm prod cache cannot be built
# at image-build time. The entrypoint warms it inside the container instead,
# before the fleet starts.

# ── Stage: app — the runtime image ─────────────────────────────────────────
FROM dunglas/frankenphp:1-php8.5-trixie AS app

# Runtime set: ca-certificates (TLS for LLM/MCP calls), curl (health check),
# tini (PID 1: reaps zombies and forwards signals to the entrypoint, which
# supervises the fleet), bash (the entrypoint's interpreter), sqlite3 (the
# CLI bin/backup-db.sh uses for WAL-aware snapshots).
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        bash ca-certificates curl sqlite3 tini \
    && install-php-extensions pcntl \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app
COPY --from=build /app /app

# Non-root app user (uid/gid 1000, same convention as task-weaver's worker).
RUN groupadd --system --gid 1000 app \
 && useradd  --system --uid 1000 --gid app \
             --home-dir /app --shell /usr/sbin/nologin app \
 && mkdir -p /app/var /app/data \
 && chown -R app:app /app

COPY docker/php.ini $PHP_INI_DIR/conf.d/zz-taskloom.ini
COPY docker/Caddyfile /etc/frankenphp/Caddyfile
COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

USER app

# FrankenPHP listens on :80; TLS is terminated by the external proxy.
ENV APP_ENV=prod \
    APP_DEBUG=0 \
    SERVER_NAME=:80 \
    APP_RUNTIME_OPTIONS='{"disable_dotenv":true}'

EXPOSE 80

# Liveness only: /health touches no dependencies, so a database hiccup cannot
# kill the container (§8.4). Readiness (which may query the DB) is /ready.
HEALTHCHECK --interval=30s --timeout=3s --start-period=10s --retries=3 \
    CMD curl -f http://localhost/health || exit 1

ENTRYPOINT ["/usr/bin/tini", "--", "/usr/local/bin/entrypoint"]
# `serve` = the whole application: web + MCP endpoint + the worker fleet,
# supervised (docker/entrypoint.sh). Override CMD for a one-shot container:
#   docker compose run --rm taskloom php bin/console doctrine:migrations:migrate
CMD ["serve"]

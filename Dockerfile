# syntax=docker/dockerfile:1.7
#
# TaskLoom — one app, one image. The admin UI, run engine, and MCP task-tools
# server all live in this single Symfony application; the scheduler tick
# (v1.1) is a Messenger worker started from the same image.
#
# Runtime: FrankenPHP (worker mode, warm kernel), non-root, SQLite (WAL) on a
# volume. TLS is terminated upstream of the container; FrankenPHP serves :80.

# ── Stage: deps — composer dependencies (layer-cached) ─────────────────────
FROM dunglas/frankenphp:1-php8.5-trixie AS deps

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Manifests first so dependency layers only rebuild when they change.
# php-mcp/server is referenced via a VCS repo (our fork with the Symfony 8
# constraint fix) — composer needs git to resolve it.
RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip \
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

# Attempt a build-time cache warm. The prod boot guard (Kernel::boot)
# rejects APP_SECRET=build-secret — a placeholder — so this ALWAYS fails;
# the warm-up is a no-op that also exercises the autoloader. The real
# warm-up runs at container start with injected secrets (entrypoint).
# (asset-map:compile above also sets APP_ENV=prod: with no APP_ENV the
# runtime defaults to dev, which boots MakerBundle — dev-only, absent
# from the --no-dev autoloader — and fatals.)
RUN APP_ENV=prod APP_SECRET=build-secret \
    APP_RUNTIME_OPTIONS='{"disable_dotenv":true}' \
    bin/console cache:warmup || true \
    && rm -rf var/cache/*

# ── Stage: app — the runtime image ─────────────────────────────────────────
FROM dunglas/frankenphp:1-php8.5-trixie AS app

# Runtime set: ca-certificates (TLS for LLM/MCP calls), curl (health checks),
# tini (PID 1 / signal handling for the Messenger worker).
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates curl tini \
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

# Health check the actual endpoints: /health (liveness, no deps) and /ready
# (readiness, may query the DB). (§8.4)
HEALTHCHECK --interval=30s --timeout=3s --start-period=10s --retries=3 \
    CMD curl -f http://localhost/health || exit 1

ENTRYPOINT ["/usr/local/bin/entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]
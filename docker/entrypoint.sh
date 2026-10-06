#!/usr/bin/env bash
# shellcheck shell=bash
#
# TaskLoom container entrypoint — the single-container runtime.
#
# One container runs the whole application (SPEC §6, §11):
#
#     web + MCP endpoint    frankenphp run --config /etc/frankenphp/Caddyfile
#                            (the admin UI and the MCP server role at POST /mcp
#                            share this process — the SDK's HTTP transport is
#                            a PSR-7 handler, not a web server)
#     llm workers  × N      messenger:consume chat llm
#                           (N = TASKLOOM_LLM_MAX_CONCURRENCY: a worker holds
#                           at most one LLM request on the wire, so N workers
#                           ARE the concurrency semaphore — SPEC §6.
#
#                           `chat` comes FIRST, and that is the whole
#                           preemption mechanism (SPEC §15):
#                           messenger:consume is strict-priority across its
#                           receivers — it drains them in the order listed,
#                           every iteration — so a queued chat reply is taken
#                           before any task turn the same worker could have
#                           taken. No new process, no lock, no async runtime.
#
#                           It does not create capacity: the semaphore is the
#                           *total* in flight, so at the default N=1 a chat
#                           goes next, never now, and waits out at most one
#                           in-flight generation.)
#     tools workers × M     messenger:consume tools
#                           (M = TASKLOOM_TOOL_MAX_CONCURRENCY; tool turns
#                           mostly wait on external servers, so several run at
#                           once without competing for the model)
#     scheduler             app:schedule:run
#                           (the scheduler daemon, SPEC §14: ticks on an
#                           interval, launches due scheduled tasks through the
#                           llm lane. One process; disable with
#                           TASKLOOM_SCHEDULER_ENABLED=0.)
#
# `serve` supervises that fleet: a worker that exits — its --time-limit or
# --memory-limit recycle, or a crash — is restarted, with backoff on rapid
# crashes. The web process is the critical one: when it exits, the whole fleet
# is stopped and the container exits with its code, so compose's restart
# policy and the health check see one coherent lifecycle.
#
# Any other command is exec'd directly, for example:
#
#     docker compose run --rm taskloom php bin/console doctrine:migrations:migrate
#
# Migrations are NOT run on the serve path by default — GUIDING-LIGHT §8.6:
# schema changes are an explicit deployment step, because a container that
# migrates at boot cannot be scaled past one replica. The compose files wire a
# one-shot `migrate` service ahead of the app; outside compose, either set
# TASKLOOM_MIGRATE_ON_BOOT=1 (single-replica deployments only) or run the
# migrate command manually. `serve` verifies the schema is current before it
# starts the fleet, and fails with the exact command if it is not.
#
# Shutdown is signal-driven: TERM/INT stops the workers gracefully (a worker
# finishes the message it is processing), with an escalation to SIGKILL after
# TASKLOOM_SHUTDOWN_TIMEOUT seconds so a wedged worker cannot block a stop
# forever. Set compose `stop_grace_period` above that timeout.
#
# Secrets are env vars injected at runtime, never baked into images (§8.12).
# The deployment env contract is documented in .env.example; docs/design/
# SINGLE_CONTAINER_RUNTIME.md explains this script's shape and its deliberate
# limits.

# `set -e` is deliberately absent: this script inspects every phase's exit
# status itself (die on the boot phases, restart-with-backoff on workers,
# propagate the web server's code), and `set -e` would fight the supervision
# loop over what "failed" means.
set -uo pipefail

# Captured once: subshells must send signals to THIS shell, and `$$` inside a
# subshell is not portable enough to rely on.
SCRIPT_PID=$$

# ── Configuration knobs ─────────────────────────────────────────────────────
# Defaults mirror docker/Dockerfile + config/services.yaml; every knob is
# documented in .env.example.
APP_ENV="${APP_ENV:-prod}"
PROJECT_DIR="${TASKLOOM_PROJECT_DIR:-/app}"

LLM_WORKERS="${TASKLOOM_LLM_MAX_CONCURRENCY:-1}"
TOOL_WORKERS="${TASKLOOM_TOOL_MAX_CONCURRENCY:-2}"
# Whether this fleet includes the web process: decided by the `serve --no-web`
# argument alone (see the entry gate below), never by a variable — two ways to
# say one thing is the ambiguity this script avoids elsewhere. 1 is the
# ordinary single-container shape, where web + workers share one lifecycle.
WEB_ENABLED=1
SCHEDULER_ENABLED="${TASKLOOM_SCHEDULER_ENABLED:-1}"
SCHEDULE_INTERVAL="${TASKLOOM_SCHEDULE_INTERVAL:-60}"
WORKER_TIME_LIMIT="${TASKLOOM_WORKER_TIME_LIMIT:-3600}"
WORKER_MEMORY_LIMIT="${TASKLOOM_WORKER_MEMORY_LIMIT:-256M}"
MIGRATE_ON_BOOT="${TASKLOOM_MIGRATE_ON_BOOT:-0}"
SYNC_ON_BOOT="${TASKLOOM_SYNC_ON_BOOT:-1}"
SHUTDOWN_TIMEOUT="${TASKLOOM_SHUTDOWN_TIMEOUT:-30}"
# Where a process that does not inherit this one's environment — the web UI,
# most importantly — can read which fleet this is. Derived from DATABASE_URL,
# because that is where the deployment's durable state already lives; see the
# serve path below.
DATA_DIR="${TASKLOOM_DATA_DIR:-}"

# A worker that exits within this many seconds is treated as crashing (rather
# than recycling after a healthy run) and restarted with backoff.
RAPID_EXIT_SECONDS=10
RAPID_EXIT_BACKOFF_MAX=30

# ── Logging / failure ───────────────────────────────────────────────────────
# Supervisor lines go to stderr as plain prefixed text; child processes write
# their own streams (prod Monolog JSON) straight through, untouched.
log() { printf '[entrypoint] %s\n' "$*" >&2; }

TIMER_PID=""
NAP_PID=""

# Defined before `die`, because validation below can fail before anything else
# exists — and cleaned up defensively, because it may run before they are set.
cleanup_timer() {
    if [ -n "${TIMER_PID:-}" ]; then
        kill "$TIMER_PID" 2>/dev/null || true
        wait "$TIMER_PID" 2>/dev/null || true
        TIMER_PID=""
    fi
}

die() { log "error: $*"; cleanup_timer; exit 2; }

# ── Validation ──────────────────────────────────────────────────────────────
# Validate BEFORE anything starts; a typo in a knob should fail the container
# immediately with a named variable, not after a worker crash-loop.
validate_uint() { # name value min
    case "$2" in
        '' | *[!0-9]*) die "$1 must be a whole number (got: '$2')" ;;
    esac
    if [ "$2" -lt "$3" ]; then
        die "$1 must be >= $3 (got: $2)"
    fi
}

validate_memory() { # name value
    case "$2" in
        '' | *[!0-9KMGkmg]*) die "$1 must look like 256M (got: '$2')" ;;
    esac
}

validate_uint TASKLOOM_LLM_MAX_CONCURRENCY "$LLM_WORKERS" 0
validate_uint TASKLOOM_TOOL_MAX_CONCURRENCY "$TOOL_WORKERS" 0
validate_uint TASKLOOM_SCHEDULE_INTERVAL "$SCHEDULE_INTERVAL" 1
validate_uint TASKLOOM_WORKER_TIME_LIMIT "$WORKER_TIME_LIMIT" 1
validate_uint TASKLOOM_SHUTDOWN_TIMEOUT "$SHUTDOWN_TIMEOUT" 0

case "$SCHEDULER_ENABLED" in
    0 | 1) ;;
    *) die "TASKLOOM_SCHEDULER_ENABLED must be 0 or 1 (got: '$SCHEDULER_ENABLED')" ;;
esac
validate_memory TASKLOOM_WORKER_MEMORY_LIMIT "$WORKER_MEMORY_LIMIT"

# ── State ───────────────────────────────────────────────────────────────────
declare -A CHILD_LABEL=() # pid → label (web, llm-1, tools-2, …)
declare -A CHILD_STARTED=() # pid → $SECONDS when the child started
declare -A CHILD_RAPID=() # label → consecutive rapid exits

STOPPING=0 # set once a shutdown is under way (TERM received, or web exit)
EXIT_CODE=0
ALARM_FIRED=0

# ── Process helpers ─────────────────────────────────────────────────────────
# `sleep` is not interruptible by a trap: bash runs a handler only after a
# foreground command returns, so a 30s backoff sleep would delay shutdown by
# up to 30s. Running it in the background and using the `wait` builtin keeps
# TERM handling immediate (the same reason the main loop uses `wait -n`).
nap() { # seconds
    sleep "$1" &
    NAP_PID=$!
    wait "$NAP_PID" 2>/dev/null || true
    NAP_PID=""
}

abort_nap() {
    if [ -n "${NAP_PID:-}" ]; then
        kill "$NAP_PID" 2>/dev/null || true
    fi
}

# ── Signal handling ─────────────────────────────────────────────────────────
on_term() {
    abort_nap

    if [ "$STOPPING" = 0 ]; then
        log "shutdown requested; stopping children"
        begin_shutdown
    else
        log "second signal received; killing children"
        kill_children
    fi
}

on_alarm() {
    ALARM_FIRED=1
    log "children did not exit within ${SHUTDOWN_TIMEOUT}s; killing them"
    kill_children
}

stop_children() {
    [ "${#CHILD_LABEL[@]}" -gt 0 ] || return 0
    kill -TERM "${!CHILD_LABEL[@]}" 2>/dev/null || true
}

kill_children() {
    [ "${#CHILD_LABEL[@]}" -gt 0 ] || return 0
    kill -KILL "${!CHILD_LABEL[@]}" 2>/dev/null || true
}

begin_shutdown() {
    STOPPING=1
    stop_children
    arm_shutdown_timer
}

# The timer is armed only once there is something to wait for: a stop requested
# during boot (before the fleet exists) must not leave an alarm to fire later.
# It checks this shell is still alive before signalling, so a cancelled timer's
# detached `sleep` cannot deliver a stale alarm to a reused PID.
arm_shutdown_timer() {
    [ "${#CHILD_LABEL[@]}" -gt 0 ] || return 0
    if [ "$SHUTDOWN_TIMEOUT" -gt 0 ]; then
        log "children have ${SHUTDOWN_TIMEOUT}s to finish before being killed"
        (sleep "$SHUTDOWN_TIMEOUT"; kill -0 "$SCRIPT_PID" 2>/dev/null && kill -ALRM "$SCRIPT_PID") &
        TIMER_PID=$!
    fi
}

# The traps are armed only once every helper the handlers reach is defined —
# a signal arriving during the definitions above must not run a half-wired
# handler.
trap on_term TERM INT
trap on_alarm ALRM

# ── Boot phases ─────────────────────────────────────────────────────────────
# During boot each phase is followed by this check, so a stop requested while
# a phase was running is honoured before the next (possibly slow) one starts.
bail_if_stopping() {
    if [ "$STOPPING" = 1 ]; then
        log "shutdown requested during boot; exiting"
        cleanup_timer
        exit 0
    fi
}

warm_cache() {
    if [ "$APP_ENV" = "prod" ]; then
        log "warming the prod cache"
        php bin/console cache:warmup --no-interaction || die "cache warmup failed (see the log above)"
    fi
}

# Verify the deployment's env contract up front. Missing variables otherwise
# surface one at a time, lazily, on the first service that needs them — which
# in a worker fleet means a handler crashing mid-run (or a worker that boots
# fine and only fails once a task reaches the LLM). `lint:container
# --resolve-env-vars` compiles the container with every referenced variable
# resolved, so an incomplete environment fails here, naming the variable,
# before anything is started.
check_data_dir() {
    # Where the fleet publishes its identity for processes that do not inherit
    # this environment. Derived from DATABASE_URL when not set explicitly: in
    # the documented deployment that is the data volume, so the file lands on
    # the same durable mount as the database itself.
    [ -n "$DATA_DIR" ] && return 0

    local url="${DATABASE_URL:-}"

    # `%kernel.project_dir%` is resolved by the application at runtime, NOT in
    # the environment — so the raw value here still contains the placeholder,
    # and treating it as ordinary path text silently produces a literal
    # directory named `%kernel.project_dir%`. Substitute it the way the
    # application will.
    url="${url//\%kernel.project_dir\%/$PROJECT_DIR}"

    case "$url" in
        sqlite:///*) DATA_DIR="$(dirname "${url#sqlite:///}")" ;;
        *) DATA_DIR="$PROJECT_DIR/data" ;;
    esac

    # A relative sqlite path is relative to the project directory, not to
    # wherever this script happens to have been started from.
    case "$DATA_DIR" in
        /*) ;;
        *) DATA_DIR="$PROJECT_DIR/$DATA_DIR" ;;
    esac
}

check_env_contract() {
    local output

    if ! output=$(php bin/console lint:container --resolve-env-vars --no-interaction 2>&1); then
        printf '%s\n' "$output" >&2
        die "environment misconfigured — see the error above (every variable is documented in .env.example)"
    fi
}

run_migrations() {
    log "running database migrations (TASKLOOM_MIGRATE_ON_BOOT=${MIGRATE_ON_BOOT})"
    php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration \
        || die "database migrations failed"
}

# Exit code alone cannot classify the failure: `migrations:up-to-date` returns
# 1 both for "there are pending migrations" and for a connection error (a bad
# DSN, an unreadable file), and a bare exit code would send an operator chasing
# migrations for a plumbing problem. The message is the discriminator.
check_schema() {
    local rc=0 output

    output=$(php bin/console doctrine:migrations:up-to-date --no-interaction 2>&1) || rc=$?

    if [ "$rc" = 0 ]; then
        log "database schema is up to date"
        return 0
    fi

    if [ "$rc" = 1 ] && printf '%s' "$output" | grep -q "Out-of-date"; then
        die "database schema is out of date. Run migrations first, e.g.:
       docker compose run --rm taskloom php bin/console doctrine:migrations:migrate --no-interaction
     (or set TASKLOOM_MIGRATE_ON_BOOT=1 for a single-container deployment)"
    fi

    printf '%s\n' "$output" >&2
    die "could not inspect the database schema (exit $rc); check DATABASE_URL and that the data volume is writable"
}

sync_catalog() {
    if [ "$SYNC_ON_BOOT" = "1" ]; then
        log "syncing tool catalog"
        # Deliberately non-fatal: a down server never blocks boot and never
        # wipes known tools (SPEC §7). Disable with TASKLOOM_SYNC_ON_BOOT=0.
        php bin/console app:catalog:sync \
            || log "warning: tool catalog sync reported failures; boot continues with known tools"
    fi
}

# ── Child processes ─────────────────────────────────────────────────────────
# Commands are built once, as arrays, so quoting cannot mangle an argument.
WEB_CMD=(frankenphp run --config /etc/frankenphp/Caddyfile)
# `chat` before `llm`: the LLM workers are the fleet's chat-aware ones, and
# receiver order is consume order (strict priority, verified in
# symfony/messenger Worker::run()). See the header.
LLM_CMD=(php bin/console messenger:consume chat llm
    --time-limit="$WORKER_TIME_LIMIT" --memory-limit="$WORKER_MEMORY_LIMIT" --no-interaction)
TOOLS_CMD=(php bin/console messenger:consume tools
    --time-limit="$WORKER_TIME_LIMIT" --memory-limit="$WORKER_MEMORY_LIMIT" --no-interaction)
# The scheduler daemon (SPEC §14). No --time-limit/--memory-limit here: it
# holds no message and its own loop is cheap; the shutdown path below stops
# it cleanly (its signal handler finishes the current tick and exits 0).
SCHEDULER_CMD=(php bin/console app:schedule:run --interval="$SCHEDULE_INTERVAL" --no-interaction)

spawn() { # label cmd…
    local label="$1"
    shift

    "$@" &
    local pid=$!

    CHILD_LABEL["$pid"]="$label"
    CHILD_STARTED["$pid"]="$SECONDS"
    log "started $label (pid $pid): $*"
}

spawn_fleet() {
    # `serve --no-web`: the worker fleet and nothing else. The web process is
    # what the operator's UI is, and there is no reason a host that is only
    # here for the model has to run one — but the fleet's shape, the
    # supervisor, and the shutdown path are identical either way. This mode
    # still owns the fleet (TASKLOOM_FLEET_OWNER), which is exactly what
    # makes it allowed to run the boot sweep. See
    # docs/design/SINGLE_CONTAINER_RUNTIME.md.
    if [ "$WEB_ENABLED" = "1" ]; then
        spawn web "${WEB_CMD[@]}"
    else
        log "web process disabled (serve --no-web); admin UI and MCP endpoint will not be served"
    fi

    if [ "$LLM_WORKERS" -gt 0 ]; then
        local i
        for ((i = 1; i <= LLM_WORKERS; i++)); do
            spawn "llm-$i" "${LLM_CMD[@]}"
        done
    else
        log "warning: no llm workers (TASKLOOM_LLM_MAX_CONCURRENCY=0); queued runs will not advance"
    fi

    if [ "$TOOL_WORKERS" -gt 0 ]; then
        local j
        for ((j = 1; j <= TOOL_WORKERS; j++)); do
            spawn "tools-$j" "${TOOLS_CMD[@]}"
        done
    else
        log "warning: no tools workers (TASKLOOM_TOOL_MAX_CONCURRENCY=0); tool turns will not run"
    fi

    if [ "$SCHEDULER_ENABLED" = "1" ]; then
        spawn scheduler "${SCHEDULER_CMD[@]}"
    else
        log "scheduler disabled (TASKLOOM_SCHEDULER_ENABLED=0); scheduled tasks will not fire"
    fi
}

# ── Supervision loop ────────────────────────────────────────────────────────
# The loop reaps whichever child exits first (`wait -n`), restarts workers, and
# treats the web process as critical: when it exits, everything stops.
#
# A trap that fires while `wait` is blocked makes it return non-zero with an
# empty destination variable, which is why the emptiness check below is the
# signal-interrupt path rather than an error.
supervise() {
    local finished rc label lifetime started_at fails delay

    while [ "${#CHILD_LABEL[@]}" -gt 0 ]; do
        rc=0
        finished=""
        wait -n -p finished || rc=$?

        if [ -z "${finished-}" ]; then
            # Interrupted by a trap: the handler already ran.
            continue
        fi

        label="${CHILD_LABEL[$finished]-}"
        if [ -z "$label" ]; then
            # Not one of ours (e.g. the shutdown timer): nothing to do.
            continue
        fi

        started_at="${CHILD_STARTED[$finished]-$SECONDS}"
        unset "CHILD_LABEL[$finished]" "CHILD_STARTED[$finished]"
        lifetime=$((SECONDS - started_at))
        log "$label exited (rc=$rc after ${lifetime}s)"

        if [ "$STOPPING" = 1 ]; then
            # A non-zero web exit during a stop still matters: it is what the
            # container should report. (A graceful stop exits 0.)
            if [ "$label" = "web" ] && [ "$rc" -ne 0 ]; then
                EXIT_CODE="$rc"
            fi
            continue
        fi

        case "$label" in
            web)
                EXIT_CODE="$rc"
                log "the web server exited; shutting everything down"
                begin_shutdown
                ;;
            llm-* | tools-* | scheduler)
                if [ "$lifetime" -lt "$RAPID_EXIT_SECONDS" ]; then
                    fails=$(( ${CHILD_RAPID[$label]:-0} + 1 ))
                    CHILD_RAPID["$label"]="$fails"
                    if [ "$fails" -ge 5 ]; then
                        log "warning: $label has crashed $fails time(s) in a row; check the log above"
                    fi
                    delay=$((2 ** (fails - 1)))
                    if [ "$delay" -gt "$RAPID_EXIT_BACKOFF_MAX" ]; then
                        delay=$RAPID_EXIT_BACKOFF_MAX
                    fi
                    log "restarting $label in ${delay}s"
                    nap "$delay"
                else
                    CHILD_RAPID["$label"]=0
                    log "restarting $label"
                fi

                # A stop may have arrived while backing off.
                if [ "$STOPPING" = 1 ]; then
                    continue
                fi

                case "$label" in
                    llm-*) spawn "$label" "${LLM_CMD[@]}" ;;
                    scheduler) spawn "$label" "${SCHEDULER_CMD[@]}" ;;
                    *) spawn "$label" "${TOOLS_CMD[@]}" ;;
                esac
                ;;
            *)
                log "warning: unknown child $label exited (rc=$rc); not restarting"
                ;;
        esac
    done

    cleanup_timer

    if [ "$ALARM_FIRED" = 1 ]; then
        log "shutdown complete after escalation"
    else
        log "all children stopped"
    fi

    return "$EXIT_CODE"
}

# ── Entry ───────────────────────────────────────────────────────────────────
cd "$PROJECT_DIR" || die "project directory not found: $PROJECT_DIR"

command="${1:-serve}"
shift || true

if [ "$command" = "serve" ]; then
    # `serve` takes one optional flag, --no-web: the worker fleet without the
    # web process, for a host that runs only workers. Anything else is
    # refused rather than ignored — a typo that silently drops the web
    # process, or silently keeps it, is the kind of quiet wrongness this
    # project refuses elsewhere.
    SERVE_NO_WEB=0
    while [ "$#" -gt 0 ]; do
        case "$1" in
            --no-web) SERVE_NO_WEB=1 ;;
            *) die "serve takes no arguments but --no-web (got: '$1')" ;;
        esac
        shift
    done

    # This process group is about to start the worker fleet, which is what
    # entitles it to clear execution claims a previous fleet left behind
    # (SPEC §6.2). Exported here and not at the top of the file: the one-shot
    # path at the bottom must NOT carry it, because a process that starts no
    # workers has no authority over claims
    # (App\RunEngine\FleetOwnership). It is deliberately not in the compose
    # env contract either — services share an environment anchor, so a
    # compose-supplied flag would be handed to the one-shot `migrate` service
    # as well, which is the exact process this gate exists to exclude.
    export TASKLOOM_FLEET_OWNER=1

    # ...and this is *which* fleet it is. A fresh identity per container start,
    # so a leftover claim can be *attributed* to a particular run of the
    # container — useful in the log and on the admin surface.
    #
    # Note what the value deliberately is NOT used for: deciding what to sweep.
    # A first attempt did that ("clear claims stamped with some other id, since
    # a restart has a new one"), and it cannot work. Every restart is a new
    # identity, so every leftover claim from the previous run is "another
    # fleet", and the sweep declines all of them by construction. The bug was
    # visible in the very test written to prove the mechanism, which passed the
    # same id on both sides — a situation that never occurs in production. A
    # per-start value can say "not me"; it can never say "me, from last time".
    # The sweep decides on what could still be in flight instead; see
    # App\RunEngine\ClaimReaper.
    #
    # $RANDOM is fine here: this is a label, not a secret. Nothing is
    # authorized by knowing it.
    # shellcheck disable=SC2155 # a fresh value in one statement; there is no
    # failing command whose status could be masked (date/$$/$RANDOM cannot fail).
    export TASKLOOM_FLEET_ID="$(date +%s)-$$-$RANDOM"

    # Written into the data volume so a process that did NOT inherit this
    # environment can still recognise a claim as this container's — the web UI
    # shares the process tree but not these variables. Best-effort on purpose:
    # a missing file costs attribution, never correctness.
    #
    # The directory is derived here rather than at the top of the serve path,
    # because the identity it holds is only known at this point.
    if [ -z "$DATA_DIR" ]; then
        check_data_dir
    fi
    if [ -n "$DATA_DIR" ]; then
        mkdir -p "$DATA_DIR" 2>/dev/null || true
        printf '%s' "$TASKLOOM_FLEET_ID" >"$DATA_DIR/fleet-id" 2>/dev/null || true
    fi

    WEB_ENABLED=1
    if [ "$SERVE_NO_WEB" = "1" ]; then
        WEB_ENABLED=0
        if [ "$LLM_WORKERS" -eq 0 ] && [ "$TOOL_WORKERS" -eq 0 ] && [ "$SCHEDULER_ENABLED" != "1" ]; then
            die "serve --no-web with no llm workers, no tool workers and no scheduler would supervise nothing; refusing to start"
        fi
    fi

    warm_cache
    bail_if_stopping

    check_env_contract
    bail_if_stopping

    if [ "$MIGRATE_ON_BOOT" = "1" ]; then
        run_migrations
        bail_if_stopping
    fi

    check_schema
    bail_if_stopping

    # Boot recovery (SPEC §6.2), in this order and for a reason: reap what a
    # fleet that was killed left claimed, then re-dispatch what those runs are
    # owed. A clean stop needs neither (workers finish their message and
    # release), so on the ordinary path this reports zero and moves on.
    #
    # Both halves must run BEFORE any worker starts. The reap is only sound
    # while no worker exists — once one is consuming, a claim it takes is
    # indistinguishable from one a corpse left, and only the staleness window
    # separates them. The requeue must precede the spawn for the same reason
    # the fence exists at all: a run sitting owed on a lane no consumer has
    # reached yet is exactly the state being repaired.
    #
    # ...and "no worker exists" has to mean *no worker on that lane*, which is
    # narrower than the fleet-owner flag. The flag's real content is "I am the
    # process group that consumes the run queues", and the reap's authority is
    # derived from it: at the default bound it clears every claim in the table,
    # on the premise that nothing in this process group can be mid-turn. That
    # premise covers only the lanes this fleet actually consumes. With
    # TASKLOOM_LLM_MAX_CONCURRENCY=0 the llm lane belongs to a peer this process
    # cannot see, and the sweep would clear claims that peer is holding right
    # now — re-dispatch its runs, reset lock_version — after which the peer's
    # committed turn is discarded as Stale and the work is simply done twice,
    # live side effects and all. Tool claims are the same case on the other
    # lane, and worse in consequence, since that is where the side effects are.
    #
    # So the sweep runs only when this fleet consumes every lane the claim
    # protocol spans, and says so rather than skipping in silence — a sweep that
    # quietly did nothing is indistinguishable from a sweep with nothing to do,
    # which is the failure mode this whole feature exists to stop repeating.
    #
    # This is deliberately coarser than it looks necessary, and it has to be.
    # The reap is a blanket `UPDATE run ... WHERE claimed_at IS NOT NULL`
    # (App\RunEngine\ClaimReaper) with no notion of which lane a claim belongs
    # to, so "does this fleet own everything it is about to clear?" cannot be
    # answered per row. A fleet running a co-located tools worker beside a
    # peer-owned llm lane is in fact the only consumer of every llm claim in the
    # table — but nothing in the sweep can express that, so the check is stated
    # on the fleet's shape and declines on any lane it does not own.
    #
    # The `serve --no-web` refusal above is a *different* rule and does not
    # cover this one: it fires only in --no-web mode and only when llm, tools
    # and the scheduler are all off. A `serve` with llm 0 and tools 1 is a
    # perfectly good fleet under that check, and was the one clearing a peer's
    # llm claims.
    #
    # The operator who genuinely wants the sweep here can say so explicitly:
    # set TASKLOOM_FLEET_GRAB_AFTER above the longest a turn can run (safely
    # TASKLOOM_LLM_TIMEOUT + 60), which is the same knobs-and-judgement answer
    # the multi-replica topology already gets (App\RunEngine\ClaimReaper).
    #
    # A fleet with no llm worker at all is *not* stranded by this: the single
    # container's stop-time trouble is a turn that outlived its shutdown window,
    # and that is repaired by its successor — which runs the full fleet and does
    # sweep. Skipping here costs nothing on the documented path.
    # The chat lane is owned by exactly the same predicate as `llm`, because
    # the LLM workers drain it (LLM_CMD consumes `chat llm`). So this check
    # covers both lanes without naming the new one — which is the one thing
    # that had to be true for adding a lane to be safe (SPEC §6.2, §15).
    if [ "$LLM_WORKERS" -gt 0 ] && [ "$TOOL_WORKERS" -gt 0 ]; then
        php bin/console app:run:requeue --startup --no-interaction \
            || log "warning: boot sweep reported failures; the fleet is starting anyway"

        # Chat exchanges owe a reply in the same sense runs owe a turn, and the
        # consequence of missing one is worse: an unanswered exchange is a
        # person sitting in front of a conversation that will never continue.
        # The claim above is already cleared (ClaimReaper sweeps every
        # claimable aggregate), so this half only has to re-dispatch.
        php bin/console app:chat:requeue --no-interaction \
            || log "warning: chat requeue reported failures; the fleet is starting anyway"
    else
        # Named explicitly rather than counted, because the two cases have
        # different remedies in the field: no llm worker means a peer owns the
        # model, no tools worker means a peer owns the side effects.
        MISSING_LANES=""
        if [ "$LLM_WORKERS" -eq 0 ]; then
            MISSING_LANES="llm"
        fi
        if [ "$TOOL_WORKERS" -eq 0 ]; then
            MISSING_LANES="${MISSING_LANES:+$MISSING_LANES and }tools"
        fi
        log "warning: boot sweep skipped — this fleet consumes no local ${MISSING_LANES} worker, so claims on that lane may belong to a peer that is working right now."
        log "warning:   abandoned claims will wait out CLAIM_STALE_SECONDS instead. If this host is the only consumer, set TASKLOOM_FLEET_GRAB_AFTER above the longest turn (TASKLOOM_LLM_TIMEOUT + 60) to sweep safely."
    fi
    bail_if_stopping

    sync_catalog
    bail_if_stopping

    spawn_fleet

    # A TERM can arrive while the fleet is being spawned; re-stopping here
    # covers a child that was started after the handler's stop_children ran —
    # it would otherwise never receive its TERM and would hold the container
    # open (the shutdown timer is the backstop).
    if [ "$STOPPING" = 1 ]; then
        stop_children
    fi

    if [ "$WEB_ENABLED" = "1" ]; then
        log "running: web + ${LLM_WORKERS} llm worker(s) + ${TOOL_WORKERS} tools worker(s); send TERM to stop"
    else
        log "running: ${LLM_WORKERS} llm worker(s) + ${TOOL_WORKERS} tools worker(s), no web; send TERM to stop"
    fi
    supervise
    exit $?
fi

# Anything else: a one-shot command (migrate, console, …). Warm the cache
# exactly as before, then hand over — no fleet, no supervision. The command
# name was shifted off the argument list to parse the serve flags, so it is
# put back here: this path must hand the process its argv verbatim, including
# the fact that it starts no workers and therefore owns no fleet.
warm_cache
log "exec: $command $*"
exec "$command" "$@"

<?php

declare(strict_types=1);

namespace App\Session;

/**
 * The session alarm: a session that cannot continue, published where the
 * container's health check can see it.
 *
 * ## The problem this solves
 *
 * A session that stops needs a human. Until now the only place that fact
 * lived was a derived state in the database and a line in the web UI — which
 * is fine if someone is looking at the UI, and useless otherwise. The
 * operator's requirement is the right one: a session that has wedged should be
 * visible at the level people actually monitor, which is the container, not a
 * row.
 *
 * So a session that stops raises this alarm, and `/health` reports unhealthy.
 * Docker's HEALTHCHECK marks the container `unhealthy`, which is visible in
 * `docker ps`, in compose, and to any external monitor that scrapes it.
 *
 * ## Why a file, and not the database
 *
 * `/health` is deliberately dependency-free (§8.4): its controller says so,
 * the Dockerfile comment says so, and the reason is sound — a database hiccup
 * must not be able to kill the container. Reading a row there would break that
 * invariant and turn every DB blip into an orchestration event.
 *
 * A file is the smallest thing that crosses the process boundary without
 * becoming a dependency. It cannot fail slowly, cannot deadlock, and its
 * absence is unambiguous. This is the same projection the entrypoint already
 * uses to publish the fleet identity to processes that did not inherit its
 * environment (`$DATA_DIR/fleet-id`) — one process knows something another
 * needs, and the filesystem is the channel they already share.
 *
 * ## Why `var/` and not the data volume
 *
 * The alarm belongs to THIS process's knowledge, not to the installation. A
 * restart is a fresh start: the sentinel is gone, and the boot heartbeat
 * re-derives whether the condition still holds. If it does, the alarm is
 * raised again within one beat; if a restart fixed it, the container comes up
 * healthy instead of wearing a stale red light forever. That is why this is
 * NOT written to the data volume beside `fleet-id`, which must survive
 * restarts precisely because it is history.
 *
 * ## Writing is idempotent and cheap
 *
 * The heartbeat calls {@see self::publish()} once per pass while anything is
 * stopped. The file is rewritten only when its content actually changes, so a
 * long outage does not churn the disk once a minute for no reason.
 */
final readonly class SessionAlarm
{
    public function __construct(private string $path)
    {
    }

    /**
     * Where the sentinel lives. Defaults to `var/session-alarm.json` beside
     * the other runtime scratch; overridable so tests need not touch the
     * project's own var/.
     */
    public static function defaultPath(string $projectDir): string
    {
        $configured = getenv('TASKLOOM_SESSION_ALARM_FILE');

        if (\is_string($configured) && '' !== trim($configured)) {
            return trim($configured);
        }

        return rtrim($projectDir, '/').'/var/session-alarm.json';
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * Publish the alarm: `$stopped` is the stopped sessions, one entry each.
     *
     * An empty list CLEARS the alarm — that is the heartbeat noticing an
     * operator resumed or fixed things, and it is as important as raising it.
     *
     * @param list<array{taskId: int, title: string, state: string, reason: string}> $stopped
     */
    public function publish(array $stopped): void
    {
        if ([] === $stopped) {
            $this->clear();

            return;
        }

        $payload = json_encode(
            ['status' => 'unhealthy', 'sessions' => $stopped, 'raisedAt' => (new \DateTimeImmutable())->format(\DATE_ATOM)],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT,
        );

        if (false === $payload) {
            return; // nothing sensible to publish; a health check stays green rather than lying
        }

        $existing = @file_get_contents($this->path);

        // Compare WITHOUT the timestamp, so an unchanged outage does not
        // rewrite the file every beat. The timestamp is refreshed only when
        // the substance changes.
        if (false !== $existing && self::substance($existing) === self::substance($payload)) {
            return;
        }

        $this->write($payload);
    }

    public function clear(): void
    {
        if (is_file($this->path)) {
            @unlink($this->path);
        }
    }

    /**
     * The alarm as `/health` reads it: null when nothing is stopped (the
     * ordinary case), else the decoded payload. A malformed file is treated
     * as NO alarm — a broken sentinel must not be able to take down a
     * container that is otherwise fine.
     *
     * @return array{status: string, sessions: list<array{taskId: int, title: string, state: string, reason: string}>, raisedAt: string}|null
     */
    public function read(): ?array
    {
        if (!is_file($this->path)) {
            return null;
        }

        $raw = @file_get_contents($this->path);

        if (false === $raw || '' === trim($raw)) {
            return null;
        }

        try {
            $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!\is_array($decoded) || !\is_array($decoded['sessions'] ?? null) || [] === $decoded['sessions']) {
            return null;
        }

        /* @var array{status: string, sessions: list<array{taskId: int, title: string, state: string, reason: string}>, raisedAt: string} $decoded */
        return $decoded;
    }

    private function write(string $payload): void
    {
        $dir = \dirname($this->path);

        if (!is_dir($dir) && !@mkdir($dir, 0o775, true) && !is_dir($dir)) {
            return;
        }

        // Write-then-rename: a health check that reads mid-write must see
        // either the old file or the new one, never a truncated one.
        $temp = $this->path.'.tmp';

        if (false === @file_put_contents($temp, $payload)) {
            return;
        }

        @rename($temp, $this->path);
    }

    /** A payload minus its timestamp — what "the same alarm" means. */
    private static function substance(string $json): string
    {
        try {
            $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $json;
        }

        if (\is_array($decoded)) {
            unset($decoded['raisedAt']);
        }

        return (string) json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}

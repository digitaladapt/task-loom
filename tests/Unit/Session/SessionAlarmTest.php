<?php

declare(strict_types=1);

namespace App\Tests\Unit\Session;

use App\Session\SessionAlarm;
use PHPUnit\Framework\TestCase;

/**
 * The alarm sentinel: the channel that carries "a session stopped" from the
 * heartbeat to `/health` without making `/health` depend on anything.
 *
 * The most important case here is the ugly one: a malformed sentinel must read
 * as NO alarm. A health check that a corrupt file can turn red is a health
 * check that will eventually mark a working container unhealthy for no reason,
 * and the operator learns to ignore it.
 */
final class SessionAlarmTest extends TestCase
{
    private string $dir = '';

    #[\Override]
    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/taskloom-alarm-'.bin2hex(random_bytes(6));
    }

    #[\Override]
    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->dir);
    }

    public function testNoFileMeansNoAlarm(): void
    {
        $alarm = new SessionAlarm($this->dir.'/session-alarm.json');

        self::assertNull($alarm->read());
    }

    public function testPublishingStoppedSessionsIsReadableBack(): void
    {
        $alarm = new SessionAlarm($this->dir.'/session-alarm.json');

        $stopped = [[
            'taskId' => 7,
            'title' => 'Nightly triage',
            'state' => 'stopped',
            'reason' => 'run #12 failed (server_error)',
        ]];

        $alarm->publish($stopped);
        $read = $alarm->read();

        self::assertNotNull($read);
        self::assertSame('unhealthy', $read['status']);
        self::assertSame($stopped, $read['sessions']);
        self::assertArrayHasKey('raisedAt', $read);
    }

    /**
     * Clearing is as important as raising: an operator who resumes a session
     * must get a green light on the next beat, with no code path that has to
     * remember to reset anything.
     */
    public function testPublishingAnEmptyListClearsTheAlarm(): void
    {
        $alarm = new SessionAlarm($this->dir.'/session-alarm.json');

        $alarm->publish([['taskId' => 1, 'title' => 't', 'state' => 'stopped', 'reason' => 'r']]);
        self::assertNotNull($alarm->read());

        $alarm->publish([]);

        self::assertNull($alarm->read());
        self::assertFileDoesNotExist($this->dir.'/session-alarm.json');
    }

    /**
     * An unchanged outage must not rewrite the file every beat — the whole
     * point of the derived sentinel is that it is cheap to publish.
     */
    public function testAnUnchangedAlarmDoesNotRewriteTheFile(): void
    {
        $path = $this->dir.'/session-alarm.json';
        $alarm = new SessionAlarm($path);

        $stopped = [['taskId' => 1, 'title' => 't', 'state' => 'stopped', 'reason' => 'r']];

        $alarm->publish($stopped);
        $first = file_get_contents($path);
        $firstMtime = filemtime($path);

        self::assertIsString($first);

        // Same substance, different instant: still no rewrite.
        sleep(1);
        $alarm->publish($stopped);

        self::assertSame($first, file_get_contents($path));
        self::assertSame($firstMtime, filemtime($path));
    }

    public function testAChangedAlarmIsRewritten(): void
    {
        $path = $this->dir.'/session-alarm.json';
        $alarm = new SessionAlarm($path);

        $alarm->publish([['taskId' => 1, 'title' => 't', 'state' => 'stopped', 'reason' => 'r']]);
        $alarm->publish([['taskId' => 2, 'title' => 'other', 'state' => 'blocked', 'reason' => 'r']]);

        $read = $alarm->read();

        self::assertNotNull($read);
        self::assertSame(2, $read['sessions'][0]['taskId']);
    }

    public function testAMalformedSentinelReadsAsNoAlarm(): void
    {
        $path = $this->dir.'/session-alarm.json';
        mkdir($this->dir, 0o775, true);
        file_put_contents($path, '{ this is not json');

        self::assertNull((new SessionAlarm($path))->read());
    }

    public function testAnEmptySentinelReadsAsNoAlarm(): void
    {
        $path = $this->dir.'/session-alarm.json';
        mkdir($this->dir, 0o775, true);
        file_put_contents($path, '');

        self::assertNull((new SessionAlarm($path))->read());
    }

    public function testASentinelWithNoSessionsReadsAsNoAlarm(): void
    {
        $path = $this->dir.'/session-alarm.json';
        mkdir($this->dir, 0o775, true);
        file_put_contents($path, '{"status":"unhealthy","sessions":[],"raisedAt":"2026-01-01T00:00:00+00:00"}');

        self::assertNull((new SessionAlarm($path))->read());
    }

    public function testItCreatesItsDirectoryOnDemand(): void
    {
        $path = $this->dir.'/nested/deeper/session-alarm.json';
        $alarm = new SessionAlarm($path);

        $alarm->publish([['taskId' => 1, 'title' => 't', 'state' => 'stopped', 'reason' => 'r']]);

        self::assertFileExists($path);
        self::assertNotNull($alarm->read());

        @unlink($path);
        @rmdir($this->dir.'/nested/deeper');
        @rmdir($this->dir.'/nested');
    }
}

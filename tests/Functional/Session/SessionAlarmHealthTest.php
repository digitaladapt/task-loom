<?php

declare(strict_types=1);

namespace App\Tests\Functional\Session;

use App\Session\SessionAlarm;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The operator's requirement, end to end: a session that has stopped must be
 * visible at the CONTAINER level, not only as a row in the UI.
 *
 * `/health` returning 503 is what makes Docker mark the container `unhealthy`,
 * which is what shows in `docker ps` and what an external monitor scrapes.
 * Nothing here asserts that anything gets killed — deliberately. The container
 * going unhealthy is a red light, and the fleet keeps serving so an operator
 * can open the UI and act.
 *
 * The other half of the contract is that /health stays dependency-free: the
 * alarm is read from a file, so this works with the database unavailable.
 */
final class SessionAlarmHealthTest extends WebTestCase
{
    private string $alarmFile = '';

    #[\Override]
    protected function setUp(): void
    {
        $this->alarmFile = sys_get_temp_dir().'/taskloom-health-'.bin2hex(random_bytes(6)).'.json';
    }

    #[\Override]
    protected function tearDown(): void
    {
        @unlink($this->alarmFile);

        // createClient() refuses a second boot, and each test wires the alarm
        // to its own scratch file, so the kernel must come down between them.
        self::ensureKernelShutdown();
    }

    /**
     * Wire the container's alarm to a scratch file, so the test neither reads
     * nor disturbs whatever the real heartbeat has published.
     */
    private function bootWithScratchAlarm(): \Symfony\Bundle\FrameworkBundle\KernelBrowser
    {
        $client = static::createClient();

        static::getContainer()->set(SessionAlarm::class, new SessionAlarm($this->alarmFile));

        return $client;
    }

    public function testAStoppedSessionMakesTheContainerUnhealthy(): void
    {
        $client = $this->bootWithScratchAlarm();

        (new SessionAlarm($this->alarmFile))->publish([[
            'taskId' => 7,
            'title' => 'Nightly triage',
            'state' => 'stopped',
            'reason' => 'run #12 failed (server_error)',
        ]]);

        $client->request('GET', '/health');

        self::assertResponseStatusCodeSame(503);

        $body = json_decode((string) $client->getResponse()->getContent(), true);

        self::assertIsArray($body);
        self::assertSame('unhealthy', $body['status']);
        self::assertSame(7, $body['sessions'][0]['taskId']);
        self::assertSame('Nightly triage', $body['sessions'][0]['title']);
    }

    /**
     * The ordinary case, and the one that must never regress: nothing stopped
     * means 200, and an unchanged healthy container stays healthy.
     */
    public function testNoStoppedSessionsKeepsTheContainerHealthy(): void
    {
        $client = $this->bootWithScratchAlarm();

        $client->request('GET', '/health');

        self::assertResponseIsSuccessful();
        self::assertSame(['status' => 'ok'], json_decode((string) $client->getResponse()->getContent(), true));
    }

    /**
     * Resuming a session must take the light back down. The heartbeat derives
     * the sentinel on every beat, so this is the operator's whole recovery
     * path: fix it, and the next beat clears it.
     */
    public function testClearingTheAlarmRestoresHealth(): void
    {
        $client = $this->bootWithScratchAlarm();

        $alarm = new SessionAlarm($this->alarmFile);
        $alarm->publish([['taskId' => 7, 'title' => 't', 'state' => 'stopped', 'reason' => 'r']]);

        $client->request('GET', '/health');
        self::assertResponseStatusCodeSame(503);

        $alarm->publish([]);

        $client->request('GET', '/health');
        self::assertResponseIsSuccessful();
    }
}

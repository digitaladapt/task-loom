<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Security\AdminUser;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The editor's live schedule preview (SPEC §14, §8): the cron the current
 * fields compose, its English reading, and the next few real occurrences.
 *
 * Server-side by design. Cron composition and occurrence computation already
 * exist, correctly, in PHP — SchedulePreset and ScheduleExpression are what the
 * scheduler itself reads. A JavaScript re-implementation would be a second
 * opinion about *when a task runs*, and the two would eventually disagree; the
 * browser only asks.
 *
 * The property that matters is that this endpoint and the save path agree:
 * both parse the same fields through the same parser, so the preview can never
 * bless a schedule the save would refuse (SPEC §14.5).
 */
final class AdminSchedulePreviewTest extends WebTestCase
{
    private KernelBrowser $client; // @phpstan-ignore property.uninitialized (assigned in setUp)

    #[\Override]
    protected function setUp(): void
    {
        static::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->client->loginUser(new AdminUser());
    }

    public function testAPresetIsComposedAndNarrated(): void
    {
        $data = $this->preview([
            'schedule_mode' => 'preset',
            'schedule_preset' => 'weekdays',
            'schedule_time' => '06:30',
        ]);

        self::assertTrue($data['ok']);
        self::assertSame('30 6 * * 1-5', $data['expression']);
        self::assertStringContainsString('weekday', $data['description']);
        self::assertStringContainsString('6:30am', $data['description']);
        // The deployment timezone is named, because that is where it fires.
        self::assertStringContainsString('America/Chicago', $data['description']);
        self::assertCount(3, $data['upcoming']);
    }

    /**
     * The preview reports the times it will actually run, not just the
     * expression — "is that what I meant?" is the question the operator is
     * really asking.
     */
    public function testThePreviewListsRealUpcomingOccurrences(): void
    {
        $data = $this->preview([
            'schedule_mode' => 'preset',
            'schedule_preset' => 'daily',
            'schedule_time' => '07:00',
        ]);

        self::assertCount(3, $data['upcoming']);
        foreach ($data['upcoming'] as $occurrence) {
            self::assertIsString($occurrence);
            self::assertStringContainsString('07:00', $occurrence);
        }
    }

    public function testIntervalPresetsNeedNoTimeField(): void
    {
        $data = $this->preview([
            'schedule_mode' => 'preset',
            'schedule_preset' => 'every_15_minutes',
        ]);

        self::assertTrue($data['ok']);
        self::assertSame('*/15 * * * *', $data['expression']);
    }

    public function testACustomExpressionIsEchoedBackNormalized(): void
    {
        $data = $this->preview([
            'schedule_mode' => 'custom',
            'schedule_custom' => '  0 8,12 * * * ',
        ]);

        self::assertTrue($data['ok']);
        self::assertSame('0 8,12 * * *', $data['expression']);
        self::assertCount(3, $data['upcoming']);
    }

    public function testManualOnlyPreviewsAsNoSchedule(): void
    {
        $data = $this->preview(['schedule_mode' => 'none']);

        self::assertTrue($data['ok']);
        self::assertNull($data['expression']);
        self::assertNull($data['description']);
        self::assertSame([], $data['upcoming']);
    }

    /**
     * An invalid expression is reported, not thrown, and the message is the
     * one the save would show — because it is produced by the same parser.
     */
    public function testAnInvalidExpressionIsReported(): void
    {
        $data = $this->preview([
            'schedule_mode' => 'custom',
            'schedule_custom' => 'nope',
        ]);

        self::assertFalse($data['ok']);
        self::assertCount(1, $data['problems']);
        self::assertStringContainsString('not a valid cron expression', $data['problems'][0]);
    }

    public function testAnIncompletePresetIsReported(): void
    {
        $data = $this->preview([
            'schedule_mode' => 'preset',
            'schedule_preset' => 'monthly',
            'schedule_time' => '09:00',
            'schedule_day_of_month' => '99',
        ]);

        self::assertFalse($data['ok']);
        self::assertStringContainsString('day of the month', $data['problems'][0]);
    }

    public function testAnEmptyCustomExpressionIsReported(): void
    {
        $data = $this->preview([
            'schedule_mode' => 'custom',
            'schedule_custom' => '',
        ]);

        self::assertFalse($data['ok']);
        self::assertStringContainsString('cron expression', $data['problems'][0]);
    }

    /**
     * The preview is a pure function of the schedule fields: it touches no
     * state, so it needs no CSRF token and no session — and a GET is safe to
     * repeat on every keystroke.
     */
    public function testThePreviewIsAReadOnlyGet(): void
    {
        $this->client->request('GET', '/tasks/schedule/preview', [
            'schedule_mode' => 'preset',
            'schedule_preset' => 'daily',
            'schedule_time' => '07:00',
        ]);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');
    }

    public function testThePreviewRequiresAuthentication(): void
    {
        static::ensureKernelShutdown();
        $client = static::createClient();

        $client->request('GET', '/tasks/schedule/preview', ['schedule_mode' => 'none']);

        // No session: the firewall sends the visitor to the sign-in page.
        self::assertTrue($client->getResponse()->isRedirect('/login'));
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return array<string, mixed>
     */
    private function preview(array $query): array
    {
        $this->client->request('GET', '/tasks/schedule/preview', $query);
        self::assertResponseIsSuccessful();

        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}

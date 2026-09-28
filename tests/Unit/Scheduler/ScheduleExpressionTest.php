<?php

declare(strict_types=1);

namespace App\Tests\Unit\Scheduler;

use App\Scheduler\ScheduleExpression;
use App\Scheduler\ScheduleFormatException;
use PHPUnit\Framework\TestCase;

/**
 * The cron side of scheduling (SPEC §14): validation, timezone
 * interpretation, and next-occurrence computation.
 */
final class ScheduleExpressionTest extends TestCase
{
    private ScheduleExpression $expression; // @phpstan-ignore property.uninitialized (assigned in setUp)

    #[\Override]
    protected function setUp(): void
    {
        $this->expression = new ScheduleExpression();
    }

    public function testNullAndBlankMeanNoSchedule(): void
    {
        $this->expression->assertValid(null);
        $this->expression->assertValid('');
        $this->expression->assertValid('   ');

        self::assertNull(ScheduleExpression::normalize(null));
        self::assertNull(ScheduleExpression::normalize(''));
        self::assertNull(ScheduleExpression::normalize('  '));
        self::assertSame('*/5 * * * *', ScheduleExpression::normalize('  */5 * * * *  '));
    }

    public function testValidExpressions(): void
    {
        foreach (['*/5 * * * *', '0 8 * * *', '0 8 * * 1-5', '30 2 1 * *', '@daily', '@hourly', '15 0 * * 0'] as $schedule) {
            $this->expression->assertValid($schedule);
            self::addToAssertionCount(1);
        }
    }

    public function testInvalidExpressionIsRefusedWithItsName(): void
    {
        try {
            $this->expression->assertValid('not a cron');
            self::fail('an invalid expression must be refused');
        } catch (ScheduleFormatException $e) {
            self::assertStringContainsString('not a cron', $e->getMessage());
            self::assertStringContainsString('cron expression', $e->getMessage());
        }
    }

    public function testNextAfterComputesInTheDeploymentTimezone(): void
    {
        $utc = new \DateTimeZone('UTC');
        $now = new \DateTimeImmutable('2026-09-28 12:00:00', $utc);

        // 08:00 in Chicago is 13:00 UTC. At 12:00 UTC (07:00 Chicago),
        // the next 08:00 Chicago occurrence is today at 13:00 UTC.
        $next = $this->expression->nextAfter('0 8 * * *', $now, 'America/Chicago');

        self::assertSame('America/Chicago', $next->getTimezone()->getName(), 'the result is wall-clock in the deployment timezone');
        self::assertSame('2026-09-28 08:00:00', $next->format('Y-m-d H:i:s'));
        self::assertSame('2026-09-28 13:00:00', $next->setTimezone($utc)->format('Y-m-d H:i:s'));

        // The SAME expression in UTC lands five hours earlier as an instant:
        // timezone is not decoration, it decides when the task runs.
        $utcNext = $this->expression->nextAfter('0 8 * * *', $now, 'UTC');
        self::assertSame('2026-09-29 08:00:00', $utcNext->format('Y-m-d H:i:s'), '08:00 UTC has already passed at 12:00 UTC');
    }

    public function testNextAfterIsStrictlyAfter(): void
    {
        $now = new \DateTimeImmutable('2026-09-28 12:00:00', new \DateTimeZone('UTC'));

        // Exactly at an occurrence: the next one is the FOLLOWING slot.
        $next = $this->expression->nextAfter('0 * * * *', $now, 'UTC');
        self::assertSame('2026-09-28 13:00:00', $next->format('Y-m-d H:i:s'));
    }

    public function testNextAfterRejectsAnInvalidExpression(): void
    {
        $this->expectException(ScheduleFormatException::class);

        $this->expression->nextAfter('nope', new \DateTimeImmutable('now'), 'UTC');
    }

    public function testResolveTimezoneNamesTheVariableWhenWrong(): void
    {
        self::assertSame('America/Chicago', $this->expression::resolveTimezone('America/Chicago')->getName());

        try {
            $this->expression::resolveTimezone('Mars/Olympus');
            self::fail('a bad timezone must be refused');
        } catch (ScheduleFormatException $e) {
            self::assertStringContainsString('TASKLOOM_TIMEZONE', $e->getMessage());
        }
    }

    public function testSixFieldExpressionIsRefused(): void
    {
        // The library's default field factory has no seconds field, so a
        // six-field expression is NOT a valid schedule here — refused
        // loudly rather than silently misread.
        $this->expectException(ScheduleFormatException::class);

        $this->expression->assertValid('0 0 8 * * *');
    }

    public function testCorruptExpressionIsNormalizedToTheTypedException(): void
    {
        // A hand-edited/broken row must surface as the ONE typed failure
        // the tick catches — never the library's raw exception.
        try {
            $this->expression->nextAfter('definitely not cron', new \DateTimeImmutable('now'), 'UTC');
            self::fail('a corrupt expression must be refused');
        } catch (ScheduleFormatException $e) {
            self::assertStringContainsString('definitely not cron', $e->getMessage());
        }
    }
}

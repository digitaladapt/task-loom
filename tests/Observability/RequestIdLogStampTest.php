<?php

declare(strict_types=1);

namespace App\Tests\Observability;

use App\Observability\RequestIdProcessor;
use App\Observability\RequestIdSubscriber;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class RequestIdLogStampTest extends TestCase
{
    public function testProcessorStampsRecordWithRequestAttributeId(): void
    {
        $request = new Request();
        $request->attributes->set(RequestIdSubscriber::REQUEST_ATTRIBUTE, 'aabbccddeeff00112233445566778899');

        $requestStack = new RequestStack();
        $requestStack->push($request);

        $processor = new RequestIdProcessor($requestStack);

        $record = new LogRecord(
            datetime: new \DateTimeImmutable('2026-01-01 00:00:00'),
            channel: 'app',
            level: Level::Info,
            message: 'something happened',
        );

        $stamped = $processor($record);

        self::assertSame('aabbccddeeff00112233445566778899', $stamped->extra['request_id']);
    }

    public function testProcessorLeavesRecordUntouchedWhenNoRequest(): void
    {
        $processor = new RequestIdProcessor(new RequestStack());

        $record = new LogRecord(
            datetime: new \DateTimeImmutable('2026-01-01 00:00:00'),
            channel: 'app',
            level: Level::Info,
            message: 'background command',
        );

        $stamped = $processor($record);

        self::assertArrayNotHasKey('request_id', $stamped->extra);
    }
}

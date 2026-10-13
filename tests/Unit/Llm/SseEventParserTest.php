<?php

declare(strict_types=1);

namespace App\Tests\Unit\Llm;

use App\Llm\SseEventParser;
use PHPUnit\Framework\TestCase;

/**
 * The SSE parser: bytes arrive in arbitrary pieces, events are the unit.
 *
 * These are the wire shapes a local endpoint actually produces, including
 * the ones that look like bugs but are not: a `data:` line split across two
 * TCP reads, an event split across the blank-line boundary, comments
 * interleaved as keep-alives, and a stream that ends without a trailing
 * blank line because the client cut it mid-event.
 */
final class SseEventParserTest extends TestCase
{
    public function testParsesEventsSplitAcrossArbitraryChunks(): void
    {
        $parser = new SseEventParser();

        self::assertSame([], $parser->feed('data: {"a":'));
        self::assertSame([], $parser->feed('1}'));
        self::assertSame([], $parser->feed("\n"));
        self::assertSame(['{"a":1}'], $parser->feed("\n"));
        self::assertTrue($parser->hasSeenDataField());
    }

    public function testAnEventSplitAcrossTheBlankLineStillResolves(): void
    {
        $parser = new SseEventParser();

        self::assertSame([], $parser->feed('data: {"choices":[{"delta":{"content":"he'));
        self::assertSame([], $parser->feed('llo"}}]}'));
        // The terminator arrives in the next TCP read:
        self::assertSame(['{"choices":[{"delta":{"content":"hello"}}]}'], $parser->feed("\n\n"));
    }

    public function testCommentsAndUnknownFieldsAreIgnored(): void
    {
        $parser = new SseEventParser();

        $events = $parser->feed(": keep-alive\n: another\nevent: message\nid: 42\nretry: 100\ndata: {\"x\":1}\n\n");

        self::assertSame(['{"x":1}'], $events);
    }

    public function testCrlfLineEndingsAreTolerated(): void
    {
        $parser = new SseEventParser();

        self::assertSame(['{"x":1}'], $parser->feed("data: {\"x\":1}\r\n\r\n"));
    }

    public function testMultipleEventsInOneChunk(): void
    {
        $parser = new SseEventParser();

        $events = $parser->feed("data: one\n\ndata: two\n\ndata: [DONE]\n\n");

        self::assertSame(['one', 'two', '[DONE]'], $events);
    }

    public function testMultiLineDataJoinsWithNewlines(): void
    {
        $parser = new SseEventParser();

        self::assertSame(["line1\nline2"], $parser->feed("data: line1\ndata: line2\n\n"));
    }

    public function testLeadingSpaceIsStrippedExactlyOnce(): void
    {
        $parser = new SseEventParser();

        // SSE says: one optional leading space after the colon is not data.
        self::assertSame([' x'], $parser->feed("data:  x\n\n"));
        self::assertSame(['y'], $parser->feed("data:y\n\n"));
    }

    public function testFlushSurfacesACompleteEventStrandedByACut(): void
    {
        $parser = new SseEventParser();

        self::assertSame([], $parser->feed("data: {\"x\":1}\n"));
        // No blank line ever arrived (the stream was cut). The event is
        // still complete in the only sense the parser can prove: its data
        // lines are whole.
        self::assertSame(['{"x":1}'], $parser->flush());
    }

    public function testFlushDiscardsAnUnterminatedLineFragment(): void
    {
        $parser = new SseEventParser();

        self::assertSame([], $parser->feed('data: {"part'));
        // No newline: the line was torn off mid-write and is not a thing
        // that was sent.
        self::assertSame([], $parser->flush());
        self::assertSame('data: {"part', $parser->pendingLineFragment());
    }

    public function testEmptyDataFieldIsSeenButNotEmitted(): void
    {
        $parser = new SseEventParser();

        self::assertSame([], $parser->feed("data:\n\n"));
        self::assertTrue($parser->hasSeenDataField(), 'an empty data field still proves SSE framing');
    }

    public function testHasSeenDataFieldIsFalseForAPlainJsonBody(): void
    {
        $parser = new SseEventParser();

        self::assertSame([], $parser->feed('{"choices":[{"message":{"content":"hi"}}]}'));
        self::assertFalse($parser->hasSeenDataField(), 'a non-SSE body must be detectable');
    }
}

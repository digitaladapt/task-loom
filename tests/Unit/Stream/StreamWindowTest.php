<?php

declare(strict_types=1);

namespace App\Tests\Unit\Stream;

use App\Entity\StreamAuthor;
use App\Entity\StreamEvent;
use App\Entity\StreamRole;
use App\Stream\StreamWindow;
use PHPUnit\Framework\TestCase;

/**
 * StreamWindow: rows folded back into the exchange shape the context compiler
 * already understands (docs/design/THE_STREAM.md §2.1).
 *
 * The window's trim arithmetic, its batching and its render cap are all
 * written against `{assistant: {content, toolCalls}, toolResults: [...]}`, so
 * this translation is load-bearing: get it wrong and the trim unit changes
 * size without anything complaining. These cases pin the reassembly rule
 * itself, independently of whether a run happens to exercise it.
 */
final class StreamWindowTest extends TestCase
{
    /**
     * The common case, and the one that must not regress: an assistant turn
     * and the results answering its calls are one exchange, not two. Splitting
     * them would let a trim keep a call whose result it dropped.
     */
    public function testAssistantRowAndItsToolRowBecomeOneExchange(): void
    {
        $exchanges = StreamWindow::toExchanges([
            $this->row(StreamRole::Assistant, 'let me look', [
                ['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'echo']],
            ]),
            $this->row(StreamRole::Tool, '', [], [
                ['tool_call_id' => 'call_1', 'content' => 'hi'],
            ]),
        ]);

        self::assertCount(1, $exchanges);
        self::assertSame('let me look', $exchanges[0]['assistant']['content']);
        self::assertCount(1, $exchanges[0]['assistant']['toolCalls']);
        self::assertSame([['tool_call_id' => 'call_1', 'content' => 'hi']], $exchanges[0]['toolResults']);
        self::assertArrayNotHasKey('userSide', $exchanges[0]);
    }

    /**
     * A turn that calls nothing is still an exchange — most turns are. An
     * assistant row must never be dropped for lacking tool rows.
     */
    public function testAssistantRowWithNoToolRowIsItsOwnExchange(): void
    {
        $exchanges = StreamWindow::toExchanges([
            $this->row(StreamRole::Assistant, 'just talking'),
            $this->row(StreamRole::Assistant, 'still talking'),
        ]);

        self::assertCount(2, $exchanges);
        self::assertSame([], $exchanges[0]['toolResults']);
        self::assertSame([], $exchanges[1]['toolResults']);
    }

    /**
     * The operator's voice goes on the `user` side of the wire, not the
     * assistant side. Rendering it as her own turn would tell the model it had
     * said something it had not — and the marker is how the window knows.
     */
    public function testUserRowIsMarkedUserSideAndIsItsOwnExchange(): void
    {
        $exchanges = StreamWindow::toExchanges([
            $this->row(StreamRole::User, 'andrew says hello'),
        ]);

        self::assertCount(1, $exchanges);
        self::assertTrue($exchanges[0]['userSide']);
        self::assertSame('andrew says hello', $exchanges[0]['assistant']['content']);
        self::assertSame([], $exchanges[0]['assistant']['toolCalls']);
    }

    /**
     * A user row arriving mid-round closes the open assistant exchange rather
     * than swallowing it: the call was still made, and its results are still
     * what answered it.
     */
    public function testUserRowClosesAnOpenAssistantExchange(): void
    {
        $exchanges = StreamWindow::toExchanges([
            $this->row(StreamRole::Assistant, 'the first turn'),
            $this->row(StreamRole::User, 'hold on'),
        ]);

        self::assertCount(2, $exchanges);
        self::assertSame('the first turn', $exchanges[0]['assistant']['content']);
        self::assertArrayNotHasKey('userSide', $exchanges[0]);
        self::assertTrue($exchanges[1]['userSide']);
    }

    /**
     * Several tool rows after one assistant row are one round: a model may
     * answer a multi-call turn with a run of result messages, and the protocol
     * requires each to follow the call it answers.
     */
    public function testConsecutiveToolRowsAccumulateOntoOneExchange(): void
    {
        $exchanges = StreamWindow::toExchanges([
            $this->row(StreamRole::Assistant, '', [
                ['id' => 'call_1'], ['id' => 'call_2'],
            ]),
            $this->row(StreamRole::Tool, '', [], [['tool_call_id' => 'call_1', 'content' => 'a']]),
            $this->row(StreamRole::Tool, '', [], [['tool_call_id' => 'call_2', 'content' => 'b']]),
        ]);

        self::assertCount(1, $exchanges);
        self::assertCount(2, $exchanges[0]['toolResults']);
    }

    /**
     * An orphan tool row (a window whose front was cut mid-round) is skipped,
     * not rendered. Sending it alone produces a request the protocol rejects;
     * rendering it as an empty assistant turn invents a turn she never took.
     */
    public function testOrphanToolRowIsSkipped(): void
    {
        $exchanges = StreamWindow::toExchanges([
            $this->row(StreamRole::Tool, '', [], [['tool_call_id' => 'call_0', 'content' => 'orphan']]),
            $this->row(StreamRole::Assistant, 'after'),
        ]);

        self::assertCount(1, $exchanges);
        self::assertSame('after', $exchanges[0]['assistant']['content']);
        self::assertSame([], $exchanges[0]['toolResults']);
    }

    /**
     * An assistant row left open at the end of the window is still emitted —
     * the newest turn is exactly the one the next request must add to.
     */
    public function testTrailingOpenExchangeIsFlushed(): void
    {
        $exchanges = StreamWindow::toExchanges([
            $this->row(StreamRole::Assistant, 'no tools here'),
        ]);

        self::assertCount(1, $exchanges);
        self::assertSame('no tools here', $exchanges[0]['assistant']['content']);
    }

    public function testEmptyStreamYieldsNoExchanges(): void
    {
        self::assertSame([], StreamWindow::toExchanges([]));
    }

    /**
     * Rows carry their own seq and are handed to the window oldest-first; the
     * fold trusts that order rather than re-sorting, so a reversed read would
     * be visible here as reversed exchanges. This pins the direction.
     */
    public function testOutputFollowsInputOrder(): void
    {
        $exchanges = StreamWindow::toExchanges([
            $this->row(StreamRole::User, 'first'),
            $this->row(StreamRole::Assistant, 'second'),
            $this->row(StreamRole::User, 'third'),
        ]);

        self::assertCount(3, $exchanges);
        self::assertSame('first', $exchanges[0]['assistant']['content']);
        self::assertSame('second', $exchanges[1]['assistant']['content']);
        self::assertSame('third', $exchanges[2]['assistant']['content']);
    }

    /**
     * @param list<array<string, mixed>> $calls
     * @param list<array<string, mixed>> $results
     */
    private function row(
        StreamRole $role,
        string $content = '',
        array $calls = [],
        array $results = [],
    ): StreamEvent {
        $event = new StreamEvent(StreamAuthor::Being, $role);
        $event->setContent($content);
        $event->setToolCalls($calls);
        $event->setToolResults($results);

        return $event;
    }
}

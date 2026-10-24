<?php

declare(strict_types=1);

namespace App\Tests\Unit\Context;

use App\Context\ContextWindow;
use App\Context\ShedPlan;
use PHPUnit\Framework\TestCase;

/**
 * A sticky base that outlived the transcript it indexes (THE_STREAM.md §2.7).
 *
 * `Being::contextBase` is persisted per being, so it survives a rebuilt
 * database, a fixture reload, or any process that no longer holds the rows the
 * trim counted. The window used to clamp the base to `count($exchanges)`, and
 * `count` slices off everything: the wire context came out *empty*, with no
 * error and no trim flag to notice it by. The model then answered from the
 * prompt head alone, which reads like amnesia rather than a bug.
 */
final class ContextWindowStaleBaseTest extends TestCase
{
    public function testABaseBeyondTheTranscriptStillKeepsTheNewestExchange(): void
    {
        $window = new ContextWindow(contextLimitTokens: 100000, maxToolOutputPct: 15.0, windowTailExchanges: 10);
        $head = ['system' => 'SYS', 'user' => 'TASK PROMPT'];

        $exchanges = [];
        foreach (range(1, 3) as $i) {
            $exchanges[] = [
                'assistant' => ['role' => 'assistant', 'content' => 'turn '.$i],
                'results' => [],
            ];
        }

        // A watermark from a stream that had, say, ten exchanges.
        $result = $window->buildMessages($head, $exchanges, [], null, new ShedPlan(10));

        self::assertNotEmpty(
            $result->messages,
            'a stale base must not collapse the wire context to nothing',
        );
        self::assertStringContainsString(
            'turn 3',
            json_encode($result->messages, \JSON_THROW_ON_ERROR),
            'the newest exchange is the resume point, so it is what survives the clamp',
        );
    }

    public function testABaseExactlyAtTheCountIsAlsoClampedInside(): void
    {
        $window = new ContextWindow(contextLimitTokens: 100000, maxToolOutputPct: 15.0, windowTailExchanges: 10);
        $head = ['system' => 'SYS', 'user' => 'TASK PROMPT'];

        $exchanges = [
            ['assistant' => ['role' => 'assistant', 'content' => 'only turn'], 'results' => [['tool_call_id' => 'c1', 'content' => 'ok']]],
        ];

        // base === count is the off-by-one that produced the empty window.
        $result = $window->buildMessages($head, $exchanges, [], null, new ShedPlan(1));

        self::assertNotEmpty($result->messages);
        self::assertStringContainsString('only turn', json_encode($result->messages, \JSON_THROW_ON_ERROR));
    }
}

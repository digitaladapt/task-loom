<?php

declare(strict_types=1);

namespace App\Tests\Unit\Admin;

use App\Admin\TranscriptEntry;
use App\Entity\Run;
use App\Entity\RunEvent;
use App\Entity\RunEventType;
use App\Entity\Task;
use App\Entity\TaskAuthor;
use App\Entity\TaskKind;
use App\Entity\ToolboxMode;
use PHPUnit\Framework\TestCase;

/**
 * The transcript's tool line, now that a stored result is unbounded.
 *
 * The cap moved from write to render (docs/design/THE_STREAM.md §2.3), which
 * means `payload.content` on a ToolResult event is whatever the server said.
 * The transcript renders that payload into a `<pre>`, so an unbounded string
 * there is a page sized by the largest thing a tool ever returned — one
 * `read_file` of a large log and the run page stops being readable.
 *
 * The fix is a view cap, not a record cap: the ledger keeps everything and the
 * transcript says how much it withheld. These tests pin both halves — the
 * excerpt is bounded, and the bound is honest about what it dropped.
 */
final class TranscriptEntryTest extends TestCase
{
    public function testAnOversizedToolResultIsExcerptedRatherThanRenderedWhole(): void
    {
        $stored = str_repeat('x', 100 * 1024);

        $entry = $this->singleToolEntry($stored);

        self::assertLessThan(4000, \strlen($entry->content), 'a 100 KB result must not reach the template whole');
        self::assertStringContainsString('more bytes in the ledger', $entry->content, 'the view says what it withheld');
    }

    public function testTheExcerptReportsTheBytesItLeftBehind(): void
    {
        // 2000 is the excerpt length; the remainder is arithmetic a reader can
        // check, so an off-by-anything marker would be worse than none.
        $stored = str_repeat('y', 2500);

        $entry = $this->singleToolEntry($stored);

        self::assertStringContainsString('…[500 more bytes in the ledger]', $entry->content);
    }

    public function testAShortToolResultIsShownExactlyAsStored(): void
    {
        // The common case: most results are small, and the transcript is the
        // full record for them. Nothing about the bound may touch these.
        $stored = 'file not found: /tmp/nope';

        $entry = $this->singleToolEntry($stored);

        self::assertStringContainsString($stored, $entry->content);
        self::assertStringNotContainsString('more bytes in the ledger', $entry->content);
    }

    public function testTheExcerptCutIsCharacterSafe(): void
    {
        // A byte offset landing mid-character would ship half a multi-byte
        // sequence into the template — the same defect Utf8::cut() exists to
        // prevent everywhere else text is bounded.
        $stored = str_repeat('é', 3000); // 2 bytes each, so a naive cut splits one

        $entry = $this->singleToolEntry($stored);

        self::assertTrue(mb_check_encoding($entry->content, 'UTF-8'), 'the excerpt must be valid UTF-8');
    }

    private function singleToolEntry(string $stored): TranscriptEntry
    {
        $task = new Task('t', 'b', TaskKind::Run, ToolboxMode::Explicit, [], TaskAuthor::User);
        $run = new Run($task);

        $event = new RunEvent(RunEventType::ToolResult);
        $event->setPayload(['tool' => 'read_file', 'content' => $stored]);
        $run->appendEvent($event);

        $entries = TranscriptEntry::build($run, [$event]);

        foreach ($entries as $entry) {
            if ('tool' === $entry->role) {
                return $entry;
            }
        }

        self::fail('the transcript carried no tool entry');
    }
}

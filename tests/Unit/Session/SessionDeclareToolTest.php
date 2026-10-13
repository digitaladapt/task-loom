<?php

declare(strict_types=1);

namespace App\Tests\Unit\Session;

use App\Entity\SessionDeclarationOutcome;
use App\Session\SessionDeclareTool;
use App\Session\SessionEndings;
use PHPUnit\Framework\TestCase;

/**
 * The filtered `session_declare` entry (docs/design/SESSION_ENDINGS.md §3):
 * the schema and description the wire actually carries for a session —
 * and the snapshot round trip, which is where the design note planted its
 * landmine ("the read path must honour the stored entry, or the filter is
 * lost on the first rehydrated turn").
 */
final class SessionDeclareToolTest extends TestCase
{
    public function testTheUnfilteredDefaultAdmitsEveryEnding(): void
    {
        $tool = SessionDeclareTool::for(SessionDeclarationOutcome::cases());

        self::assertSame(['milestone', 'done', 'blocked'], $tool->labels());
        self::assertStringNotContainsString('no completion', $tool->getDescription());
    }

    public function testTheDescriptionAndSchemaSpeakOnlyTheEndings(): void
    {
        $tool = SessionDeclareTool::for([SessionDeclarationOutcome::Milestone, SessionDeclarationOutcome::Blocked]);

        self::assertSame(['milestone', 'blocked'], $tool->labels());
        self::assertTrue($tool->allows(SessionDeclarationOutcome::Blocked));
        self::assertFalse($tool->allows(SessionDeclarationOutcome::Done));
        self::assertStringContainsString('no completion', $tool->getDescription(), 'a session without done is told so');

        $outcome = $tool->getSchema()['properties']['outcome'] ?? null;
        self::assertIsArray($outcome);
        self::assertSame(['milestone', 'blocked'], $outcome['enum']);
        self::assertStringNotContainsString('done', (string) $outcome['description'], 'the outcome description teaches no unavailable ending');
    }

    public function testTheRefusalForAnUnavailableOutcomeNamesTheAlternatives(): void
    {
        $tool = SessionDeclareTool::for([SessionDeclarationOutcome::Milestone, SessionDeclarationOutcome::Blocked]);

        $forDone = $tool->describeUnavailable(SessionDeclarationOutcome::Done);
        self::assertStringContainsString('"done" is not available', $forDone);
        self::assertStringContainsString('no completion', $forDone);
        self::assertStringContainsString('milestone, blocked', $forDone);
    }

    public function testTheRefusalForAnUnavailableBlockedSaysHowElseToReachTheOperator(): void
    {
        // SESSION_ENDINGS.md §5: a session that cannot park itself is told
        // the alternatives, not just refused.
        $tool = SessionDeclareTool::for([SessionDeclarationOutcome::Milestone]);

        $refusal = $tool->describeUnavailable(SessionDeclarationOutcome::Blocked);
        self::assertStringContainsString('cannot park itself', $refusal);
        self::assertStringContainsString('alert', $refusal);
    }

    public function testASnapshotRoundTripPreservesTheFrozenSet(): void
    {
        // The landmine: rebuild must read the STORED entry, not re-derive
        // from the enum case — or the first rehydrated turn silently
        // re-widens the vocabulary.
        $frozen = SessionDeclareTool::for([SessionDeclarationOutcome::Milestone]);

        $rebuilt = SessionDeclareTool::fromSnapshot($frozen->getDescription(), $frozen->getSchema());

        self::assertSame(['milestone'], $rebuilt->labels());
        self::assertSame($frozen->getDescription(), $rebuilt->getDescription());
        self::assertSame($frozen->getSchema(), $rebuilt->getSchema());
    }

    public function testASnapshotWithAnUnusableSchemaFallsBackToTheFullVocabulary(): void
    {
        // A hand-built snapshot (a test fixture, a repaired row) with no
        // readable enum: the vocabulary itself is the default, and the
        // settle path still refuses nothing the vocabulary allows.
        $rebuilt = SessionDeclareTool::fromSnapshot('an old description', []);

        self::assertSame(['milestone', 'done', 'blocked'], $rebuilt->labels());
        self::assertSame('an old description', $rebuilt->getDescription(), 'the stored text is honoured');
    }

    public function testTheEndingsBuildTheToolWithTheSameSet(): void
    {
        // One derivation: SessionEndings::declareTool() is the only path a
        // slice's toolbox is assembled through, so the prompt and the wire
        // cannot teach different sets.
        $endings = new SessionEndings(endings: 'blocked');

        self::assertSame(['blocked'], $endings->declareTool()->labels());
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Unit\Session;

use App\Entity\SessionDeclarationOutcome;
use App\Session\SessionEndings;
use App\Session\SessionUndeclared;
use PHPUnit\Framework\TestCase;

/**
 * The session's endings constitution (docs/design/SESSION_ENDINGS.md §2):
 * one derivation — endings, undeclared policy, streak bound — that every
 * surface renders from. These assertions cover the parsing and the prose,
 * because the prose IS the contract the model reads; the enforcement side
 * (frozen wire schema, settle-path refusal) is proven end to end in the
 * functional suite.
 */
final class SessionEndingsTest extends TestCase
{
    public function testTheDefaultsAreEveryEndingAndStop(): void
    {
        $endings = new SessionEndings();

        self::assertSame(['milestone', 'done', 'blocked'], $endings->labels());
        self::assertTrue($endings->hasCompletion());
        self::assertSame(SessionUndeclared::Stop, $endings->undeclared);
        self::assertSame(SessionEndings::DEFAULT_MAX_UNDECLARED, $endings->maxUndeclared);
    }

    public function testAnEmptyOrBlankValueResolvesToTheDefaults(): void
    {
        // `default:` processors hand over null for an unset variable, and a
        // compose file may hand over '' — both mean "not configured".
        self::assertSame(['milestone', 'done', 'blocked'], (new SessionEndings(endings: ''))->labels());
        self::assertSame(['milestone', 'done', 'blocked'], (new SessionEndings(endings: '  '))->labels());
        self::assertSame(SessionUndeclared::Stop, (new SessionEndings(undeclared: ''))->undeclared);
    }

    public function testANonEmptySubsetResolvesInCanonicalOrder(): void
    {
        // Declared out of order; resolved in the vocabulary's own order, so
        // every surface (tool schema, prompt bullets, refusal wording)
        // renders the set the same way.
        $endings = new SessionEndings(endings: 'blocked, milestone');

        self::assertSame(['milestone', 'blocked'], $endings->labels());
        self::assertTrue($endings->allows(SessionDeclarationOutcome::Milestone));
        self::assertFalse($endings->allows(SessionDeclarationOutcome::Done));
        self::assertFalse($endings->hasCompletion());
        self::assertSame('milestone, blocked', $endings->labelList());
    }

    public function testAnUnknownEndingIsRefusedNamingTheVocabulary(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('codeship');

        new SessionEndings(endings: 'milestone,codeship');
    }

    public function testAnEndinglessSubsetIsRefused(): void
    {
        // A session that can declare nothing could only ever settle by
        // silence — refused, with the vocabulary named.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('names no outcomes');

        new SessionEndings(endings: ',');
    }

    public function testAnUnknownUndeclaredPolicyIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('TASKLOOM_SESSION_UNDECLARED');

        new SessionEndings(undeclared: 'carry-on');
    }

    public function testAnInvalidStreakBoundIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('TASKLOOM_SESSION_MAX_UNDECLARED');

        new SessionEndings(maxUndeclared: 0);
    }

    public function testTheStreakBoundAcceptsBothTheEnvStringAndAnInt(): void
    {
        // The `int:` env processor hands over an int; a hand-built call may
        // hand over the raw string. One value either way.
        self::assertSame(7, (new SessionEndings(maxUndeclared: 7))->maxUndeclared);
        self::assertSame(7, (new SessionEndings(maxUndeclared: '7'))->maxUndeclared);
    }

    public function testTheCompletionTextRendersOnlyTheAdmittedBullets(): void
    {
        $endings = new SessionEndings(endings: 'milestone,blocked');
        $text = $endings->completionText();

        self::assertStringContainsString('- milestone —', $text);
        self::assertStringContainsString('- blocked —', $text);
        self::assertStringNotContainsString('- done —', $text, 'an ending the session does not have is never taught');
        self::assertStringContainsString('no completion', $text, 'the absence of done is stated, not implied');
        self::assertStringContainsString('stops the session for a human', $text, 'the stop policy is stated');
    }

    public function testTheContinuePolicyRendersItsBound(): void
    {
        $endings = new SessionEndings(undeclared: 'continue', maxUndeclared: 4);
        $text = $endings->completionText();

        self::assertStringContainsString('settled as a continuation', $text);
        self::assertStringContainsString('4 consecutive undeclared settlements', $text, 'the bound is stated as the model will be held to it');
        self::assertStringNotContainsString('stops the session for a human — it is never treated as a completion', $text);
    }

    public function testTheSliceNoteCarriesThePolicyAndExplicitlySaysWhenThereIsNoCompletion(): void
    {
        $note = (new SessionEndings(endings: 'milestone,blocked'))->sliceNote();

        self::assertStringContainsString('milestone / blocked', $note);
        self::assertStringContainsString('no completion', $note);
        self::assertStringContainsString('ALONE', $note);
    }

    public function testTheDeclarationToolItBuildsAdmitsExactlyTheEndings(): void
    {
        $tool = (new SessionEndings(endings: 'milestone'))->declareTool();

        self::assertSame(['milestone'], $tool->labels());
        self::assertFalse($tool->allows(SessionDeclarationOutcome::Done));

        $enum = $tool->getSchema()['properties']['outcome']['enum'] ?? null;
        self::assertSame(['milestone'], $enum, 'the wire schema admits exactly the endings');
    }
}

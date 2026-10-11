<?php

declare(strict_types=1);

namespace App\Tests\Unit\Session;

use App\Session\SessionTool;
use App\Toolbox\ToolSchemaValidator;
use PHPUnit\Framework\TestCase;

/**
 * The harness's session tools (docs/design/SESSION_TASKS.md §5, build order
 * steps 3–4): a closed vocabulary with schemas the engine enforces.
 *
 * The schema assertions go through the real validator — the same one the
 * engine runs before dispatching a harness call — so "the schema refuses a
 * blank write" is proven the way it will actually be enforced, not by
 * regexing the schema.
 */
final class SessionToolTest extends TestCase
{
    public function testTheVocabularyIsTheTwoWriteToolsAndTheDeclaration(): void
    {
        self::assertSame(['session_note', 'session_objective', 'session_declare'], SessionTool::names());
    }

    public function testEveryToolCarriesADescriptionAndSchema(): void
    {
        foreach (SessionTool::cases() as $tool) {
            self::assertNotSame('', $tool->getDescription(), $tool->value.' must guide the model');
            self::assertSame('object', $tool->getSchema()['type'] ?? null);
        }
    }

    public function testTheWriteToolsTakeExactlyOneNonBlankText(): void
    {
        foreach ([SessionTool::Note, SessionTool::Objective] as $tool) {
            self::assertSame(['text'], $tool->getSchema()['required'] ?? null, $tool->value);

            // A valid write passes, and the blank/absent/extra shapes are
            // refused — through the real validator.
            self::assertSame([], ToolSchemaValidator::validate($tool->getSchema(), ['text' => 'A fact worth carrying.']), $tool->value);
            self::assertNotSame([], ToolSchemaValidator::validate($tool->getSchema(), []), $tool->value);
            self::assertNotSame([], ToolSchemaValidator::validate($tool->getSchema(), ['text' => '']), $tool->value);
            self::assertNotSame([], ToolSchemaValidator::validate($tool->getSchema(), ['text' => 'ok', 'silently' => 'ignored?']), $tool->value);
        }
    }

    public function testTheDeclarationTakesAnOutcomeAndASummary(): void
    {
        $schema = SessionTool::Declare->getSchema();

        self::assertSame(['outcome', 'summary'], $schema['required'] ?? null);
        self::assertSame(['milestone', 'done', 'blocked'], $schema['properties']['outcome']['enum'] ?? null, 'the outcome is a closed set — the vocabulary is the design');

        // Valid declarations pass, for every outcome.
        foreach (['milestone', 'done', 'blocked'] as $outcome) {
            $errors = ToolSchemaValidator::validate($schema, ['outcome' => $outcome, 'summary' => 'The slice did a thing.']);
            self::assertSame([], $errors, $outcome.' should validate');
        }

        // And the malformed shapes are refused before the engine ever has to
        // interpret them: unknown outcome, missing summary, blank summary,
        // smuggled extras.
        self::assertNotSame([], ToolSchemaValidator::validate($schema, ['outcome' => 'maybe', 'summary' => 'x']));
        self::assertNotSame([], ToolSchemaValidator::validate($schema, ['outcome' => 'done']));
        self::assertNotSame([], ToolSchemaValidator::validate($schema, ['outcome' => 'done', 'summary' => '']));
        self::assertNotSame([], ToolSchemaValidator::validate($schema, ['outcome' => 'done', 'summary' => 'x', 'extra' => true]));
    }
}

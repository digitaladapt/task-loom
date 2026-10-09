<?php

declare(strict_types=1);

namespace App\Tests\Unit\Session;

use App\Session\SessionTool;
use App\Toolbox\ToolSchemaValidator;
use PHPUnit\Framework\TestCase;

/**
 * The harness's session tools (docs/design/SESSION_TASKS.md §5, build order
 * step 3): a closed vocabulary with schemas the engine enforces.
 *
 * The schema assertions go through the real validator — the same one the
 * engine runs before dispatching a harness call — so "the schema refuses a
 * blank write" is proven the way it will actually be enforced, not by
 * regexing the schema.
 */
final class SessionToolTest extends TestCase
{
    public function testTheVocabularyIsExactlyTheTwoWriteTools(): void
    {
        self::assertSame(['session_note', 'session_objective'], SessionTool::names());
    }

    public function testEveryToolCarriesADescriptionAndSchema(): void
    {
        foreach (SessionTool::cases() as $tool) {
            self::assertNotSame('', $tool->getDescription(), $tool->value.' must guide the model');
            self::assertSame('object', $tool->getSchema()['type'] ?? null);
            self::assertSame(['text'], $tool->getSchema()['required'] ?? null);
        }
    }

    public function testAValidWritePassesValidation(): void
    {
        foreach (SessionTool::cases() as $tool) {
            self::assertSame([], ToolSchemaValidator::validate($tool->getSchema(), ['text' => 'A fact worth carrying.']), $tool->value);
        }
    }

    public function testMissingTextIsRefused(): void
    {
        $errors = ToolSchemaValidator::validate(SessionTool::Note->getSchema(), []);

        self::assertNotSame([], $errors);
        self::assertStringContainsStringIgnoringCase('required', implode(' ', $errors));
    }

    public function testBlankTextIsRefused(): void
    {
        $errors = ToolSchemaValidator::validate(SessionTool::Note->getSchema(), ['text' => '']);

        self::assertNotSame([], $errors, 'minLength 1 refuses the blank write at the schema, before the store has to');
    }

    public function testUnknownArgumentsAreRefused(): void
    {
        $errors = ToolSchemaValidator::validate(SessionTool::Objective->getSchema(), ['text' => 'The aim.', 'silently' => 'ignored?']);

        self::assertNotSame([], $errors, 'additionalProperties: false — a write is exactly the text, nothing smuggled alongside');
    }
}

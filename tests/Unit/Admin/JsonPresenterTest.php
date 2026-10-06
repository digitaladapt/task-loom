<?php

declare(strict_types=1);

namespace App\Tests\Unit\Admin;

use App\Admin\JsonPresenter;
use PHPUnit\Framework\TestCase;

final class JsonPresenterTest extends TestCase
{
    private JsonPresenter $presenter; // @phpstan-ignore property.uninitialized (assigned in setUp)

    #[\Override]
    protected function setUp(): void
    {
        $this->presenter = new JsonPresenter();
    }

    /**
     * The bug this class exists for: a tool result's `content` is a STRING
     * (the OpenAI `tool` message shape), and a real MCP server puts JSON in it.
     * Pretty-printing the payload therefore showed one long escaped line inside
     * otherwise readable JSON.
     */
    public function testJsonInsideAStringIsUnwrapped(): void
    {
        $payload = [
            'tool' => 'read_email',
            'content' => '{"uid": 3236, "subject": "Water bill", "unread": true}',
            'isError' => false,
        ];

        $pretty = $this->presenter->pretty($payload);

        // The nested object is real structure now, not an escaped string...
        self::assertStringNotContainsString('\\"uid\\"', $pretty);
        self::assertStringContainsString('"subject": "Water bill"', $pretty);
        // ...and the outer keys are still there.
        self::assertStringContainsString('"tool": "read_email"', $pretty);
        // It is one document, printed once.
        self::assertSame(1, substr_count($pretty, '"uid"'));
    }

    public function testNestedJsonStringsUnwrapAtEveryDepth(): void
    {
        $inner = json_encode(['level' => 'inner']);
        $middle = json_encode(['level' => 'middle', 'child' => $inner]);
        $payload = ['level' => 'outer', 'child' => $middle];

        $pretty = $this->presenter->pretty($payload);

        self::assertStringContainsString('"level": "inner"', $pretty);
        self::assertStringNotContainsString('\\"level\\"', $pretty);
    }

    public function testProseIsLeftAlone(): void
    {
        $payload = ['tool' => 'echo', 'content' => 'ECHO: "the fence, not the shed"'];

        $pretty = $this->presenter->pretty($payload);

        self::assertStringContainsString('"content": "ECHO: \\"the fence, not the shed\\""', $pretty);
    }

    /**
     * A bare scalar string is *also* valid JSON, and unwrapping it would change
     * what the value says. The tool chose the string; the page keeps it.
     */
    public function testScalarLookingStringsAreNotUnwrapped(): void
    {
        foreach (['3', 'true', 'null', '"already a string"', ''] as $scalar) {
            $pretty = $this->presenter->pretty(['content' => $scalar]);
            self::assertStringContainsString(
                json_encode($scalar, \JSON_UNESCAPED_SLASHES),
                $pretty,
                \sprintf('%s should have stayed a string', var_export($scalar, true)),
            );
        }
    }

    public function testMalformedJsonInAStringStaysAString(): void
    {
        $pretty = $this->presenter->pretty(['content' => '{"broken": ']);

        self::assertStringContainsString('{\\"broken\\": ', $pretty);
    }

    public function testArraysAndListsSurvive(): void
    {
        $pretty = $this->presenter->pretty(['items' => [1, 2, 3], 'empty' => []]);

        self::assertStringContainsString('"items": [', $pretty);
        self::assertStringContainsString('"empty": []', $pretty);
    }

    public function testEmptyPayload(): void
    {
        self::assertSame('[]', $this->presenter->pretty([]));
    }

    /** Recursion must stop, not recurse forever on a self-similar document. */
    public function testDepthIsBounded(): void
    {
        // 12 levels of JSON-in-a-string, past the presenter's limit of 6.
        $value = '{"leaf": true}';
        for ($i = 0; $i < 12; ++$i) {
            $value = json_encode(['wrap' => $value]);
        }

        $pretty = $this->presenter->pretty(['content' => $value]);

        self::assertStringContainsString('"content"', $pretty);
    }
}

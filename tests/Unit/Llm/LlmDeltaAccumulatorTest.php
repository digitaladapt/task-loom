<?php

declare(strict_types=1);

namespace App\Tests\Unit\Llm;

use App\Llm\LlmDeltaAccumulator;
use PHPUnit\Framework\TestCase;

/**
 * Delta reassembly: the accumulations the client depends on.
 *
 * The tool-call case is the one that actually bites in the field — a local
 * endpoint streams `arguments` as a JSON string split across chunks, and a
 * client that appends wrong produces a tool call whose arguments are half a
 * JSON document. The degenerate deliveries (a whole arguments string in one
 * chunk, an empty string, several calls interleaved by index, a non-streamed
 * `message` instead of `delta`) all appear across Ollama/vLLM/llama.cpp.
 */
final class LlmDeltaAccumulatorTest extends TestCase
{
    /**
     * Build one SSE event carrying a delta, so the tests below stay legible
     * and the nesting cannot drift.
     *
     * @param array<string, mixed> $delta
     *
     * @return array<string, mixed>
     */
    private function sse(array $delta): array
    {
        return ['choices' => [['delta' => $delta]]];
    }

    /**
     * @param array<string, mixed> $event
     *
     * @return array{model: bool, content: string, reasoning: string}
     */
    private function apply(LlmDeltaAccumulator $accumulator, array $event): array
    {
        return $accumulator->applyEvent($event);
    }

    public function testAccumulatesContentAcrossChunks(): void
    {
        $accumulator = new LlmDeltaAccumulator();

        $this->apply($accumulator, $this->sse(['content' => 'Hel']));
        $this->apply($accumulator, $this->sse(['content' => 'lo, ']));
        $this->apply($accumulator, $this->sse(['content' => 'world']));

        self::assertSame('Hello, world', $accumulator->content());
        self::assertSame(3, $accumulator->contentDeltas());
    }

    public function testToolCallArgumentsArrivingInFragmentsAreReassembled(): void
    {
        $accumulator = new LlmDeltaAccumulator();

        $this->apply($accumulator, $this->sse(['tool_calls' => [
            ['index' => 0, 'id' => 'call_1', 'function' => ['name' => 'get_weather']],
        ]]));
        foreach (['{"loc', 'ation":', '"Reykj', 'avik"}'] as $fragment) {
            $this->apply($accumulator, $this->sse(['tool_calls' => [
                ['index' => 0, 'function' => ['arguments' => $fragment]],
            ]]));
        }

        $finalized = $accumulator->finalizeToolCalls();

        self::assertSame(0, $finalized['dropped']);
        self::assertSame([[
            'id' => 'call_1',
            'name' => 'get_weather',
            'arguments' => ['location' => 'Reykjavik'],
        ]], $finalized['toolCalls']);
    }

    public function testSeveralToolCallsInterleavedByIndexResolveInOrder(): void
    {
        $accumulator = new LlmDeltaAccumulator();

        // The wire shape some servers use: fragments for both calls
        // interleave, keyed by index.
        $this->apply($accumulator, $this->sse(['tool_calls' => [
            ['index' => 0, 'id' => 'a', 'function' => ['name' => 'one', 'arguments' => '{"x"']],
        ]]));
        $this->apply($accumulator, $this->sse(['tool_calls' => [
            ['index' => 1, 'id' => 'b', 'function' => ['name' => 'two', 'arguments' => '{"y"']],
        ]]));
        $this->apply($accumulator, $this->sse(['tool_calls' => [
            ['index' => 0, 'function' => ['arguments' => ':1}']],
        ]]));
        $this->apply($accumulator, $this->sse(['tool_calls' => [
            ['index' => 1, 'function' => ['arguments' => ':2}']],
        ]]));

        $finalized = $accumulator->finalizeToolCalls();

        self::assertSame(0, $finalized['dropped']);
        self::assertSame(['one', 'two'], array_column($finalized['toolCalls'], 'name'));
        self::assertSame(['x' => 1], $finalized['toolCalls'][0]['arguments']);
        self::assertSame(['y' => 2], $finalized['toolCalls'][1]['arguments']);
    }

    public function testPartialToolCallIsDroppedAndCounted(): void
    {
        $accumulator = new LlmDeltaAccumulator();

        // A cut mid-arguments: JSON never closes.
        $this->apply($accumulator, $this->sse(['tool_calls' => [
            ['index' => 0, 'id' => 'call_1', 'function' => ['name' => 'send_email', 'arguments' => '{"to":"al']],
        ]]));

        $finalized = $accumulator->finalizeToolCalls();

        self::assertSame([], $finalized['toolCalls']);
        self::assertSame(1, $finalized['dropped'], 'a half-written call is not a call');
    }

    public function testToolCallMissingIdOrNameIsDropped(): void
    {
        $accumulator = new LlmDeltaAccumulator();

        $this->apply($accumulator, $this->sse(['tool_calls' => [
            ['index' => 0, 'function' => ['name' => 'echo', 'arguments' => '{}']],
        ]]));

        // No id ever arrived — cannot be dispatched against a provider that
        // expects result correlation.
        self::assertSame(1, $accumulator->finalizeToolCalls()['dropped']);
    }

    public function testEmptyArgumentsDecodeToEmptyArray(): void
    {
        $accumulator = new LlmDeltaAccumulator();

        $this->apply($accumulator, $this->sse(['tool_calls' => [
            ['index' => 0, 'id' => 'c', 'function' => ['name' => 'echo', 'arguments' => '']],
        ]]));

        $finalized = $accumulator->finalizeToolCalls();

        self::assertSame([], $finalized['toolCalls'][0]['arguments']);
    }

    public function testReasoningContentIsCapturedFromBothKeySpellings(): void
    {
        $accumulator = new LlmDeltaAccumulator();

        $this->apply($accumulator, $this->sse(['reasoning_content' => 'thin']));
        $this->apply($accumulator, $this->sse(['reasoning' => 'king']));

        self::assertSame('thinking', $accumulator->reasoning());
        self::assertSame(2, $accumulator->reasoningDeltas());
    }

    public function testFinishReasonAndUsageAreCaptured(): void
    {
        $accumulator = new LlmDeltaAccumulator();

        $accumulator->applyEvent(['choices' => [['delta' => ['content' => 'x'], 'finish_reason' => null]]]);
        $accumulator->applyEvent(['choices' => [['delta' => [], 'finish_reason' => 'stop']], 'usage' => ['total_tokens' => 12]]);

        self::assertSame('stop', $accumulator->finishReason());
        self::assertSame(['total_tokens' => 12], $accumulator->usage());
    }

    public function testAUsageOnlyChunkWithEmptyChoicesIsTolerated(): void
    {
        $accumulator = new LlmDeltaAccumulator();

        $result = $accumulator->applyEvent(['choices' => [], 'usage' => ['total_tokens' => 3]]);

        self::assertFalse($result['model'], 'a usage-only chunk is not model output');
        self::assertSame(['total_tokens' => 3], $accumulator->usage());
    }

    public function testANonStreamedMessageShapeIsTolerated(): void
    {
        $accumulator = new LlmDeltaAccumulator();

        $accumulator->applyEvent(['choices' => [['message' => ['content' => 'one-shot'], 'finish_reason' => 'stop']]]);

        self::assertSame('one-shot', $accumulator->content());
    }

    public function testModelFlagTracksWhetherAnythingWasProduced(): void
    {
        $accumulator = new LlmDeltaAccumulator();

        self::assertFalse($this->apply($accumulator, $this->sse([]))['model']);
        self::assertTrue($this->apply($accumulator, $this->sse(['content' => 'x']))['model']);
        self::assertTrue($this->apply($accumulator, $this->sse(['tool_calls' => [['index' => 0, 'function' => ['name' => 't']]]]))['model']);
    }
}

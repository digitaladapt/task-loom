<?php

declare(strict_types=1);

namespace App\Llm;

/**
 * Reassembles streamed `chat.completions` deltas into one assistant turn.
 *
 * Pure by design: HTTP, timeouts and cancellation never touch this class,
 * so the wire shape ("tool call arguments arrive as string fragments
 * across chunks, keyed by index") can be tested without a socket, and the
 * transport can be tested without re-testing the wire shape.
 *
 * The degenerate shapes a local endpoint may send are all tolerated:
 * arguments split mid-JSON across any number of chunks, several calls
 * interleaved by `index`, `arguments` delivered whole or empty, alternate
 * reasoning keys (`reasoning_content` on some servers, `reasoning` on
 * others), a non-streamed `message` object instead of `delta`, and a
 * usage-only final chunk whose `choices` list is empty.
 *
 * A tool call is complete only if it accumulated a non-empty id, a
 * non-empty name, and arguments that decode to a JSON object. Anything
 * less was torn off by the cut — the caller drops it (and counts it), per
 * the rule that a partial call is not a call.
 */
final class LlmDeltaAccumulator
{
    private string $content = '';

    private string $reasoning = '';

    /** @var array<int, array{id: string, name: string, arguments: string, order: int}> */
    private array $calls = [];

    private int $nextCallIndex = 0;

    private string $finishReason = '';

    /** @var array<string, mixed> */
    private array $usage = [];

    private int $contentDeltas = 0;

    private int $reasoningDeltas = 0;

    private int $toolCallDeltas = 0;

    /**
     * Apply one parsed SSE event.
     *
     * @param array<string, mixed> $event
     *
     * @return array{model: bool, content: string, reasoning: string} the newly
     *                                                                appended text per channel, and whether any model output was seen at all
     */
    public function applyEvent(array $event): array
    {
        $new = ['model' => false, 'content' => '', 'reasoning' => ''];

        if (isset($event['usage']) && \is_array($event['usage'])) {
            $this->usage = $event['usage'];
        }

        $choice = $event['choices'][0] ?? null;
        if (!\is_array($choice)) {
            return $new;
        }

        $finish = $choice['finish_reason'] ?? null;
        if (\is_string($finish) && '' !== $finish) {
            $this->finishReason = $finish;
        }

        // Streamed responses use `delta`; tolerate a non-streamed `message`
        // shape arriving through this path.
        $delta = $choice['delta'] ?? $choice['message'] ?? null;
        if (!\is_array($delta)) {
            return $new;
        }

        $content = $delta['content'] ?? null;
        if (\is_string($content) && '' !== $content) {
            $this->content .= $content;
            ++$this->contentDeltas;
            $new['model'] = true;
            $new['content'] = $content;
        }

        $reasoning = $delta['reasoning_content'] ?? $delta['reasoning'] ?? null;
        if (\is_string($reasoning) && '' !== $reasoning) {
            $this->reasoning .= $reasoning;
            ++$this->reasoningDeltas;
            $new['model'] = true;
            $new['reasoning'] = $reasoning;
        }

        foreach (($delta['tool_calls'] ?? []) as $fragment) {
            if (!\is_array($fragment)) {
                continue;
            }

            $index = $fragment['index'] ?? null;
            if (\is_int($index) || (\is_string($index) && ctype_digit($index))) {
                $index = (int) $index;
            } else {
                // Some servers omit index entirely; keep arrival order.
                $index = 0 === count($this->calls) ? $this->nextCallIndex : $this->nextCallIndex;
            }
            $this->nextCallIndex = max($this->nextCallIndex, $index + 1);

            $this->calls[$index] ??= ['id' => '', 'name' => '', 'arguments' => '', 'order' => $index];

            $id = $fragment['id'] ?? null;
            if (\is_string($id) && '' !== $id) {
                $this->calls[$index]['id'] = $id;
            }

            $function = $fragment['function'] ?? null;
            if (\is_array($function)) {
                $name = $function['name'] ?? null;
                if (\is_string($name) && '' !== $name) {
                    $this->calls[$index]['name'] = $name;
                }

                $arguments = $function['arguments'] ?? null;
                if (\is_string($arguments) && '' !== $arguments) {
                    $this->calls[$index]['arguments'] .= $arguments;
                }
            }

            ++$this->toolCallDeltas;
            $new['model'] = true;
        }

        return $new;
    }

    public function content(): string
    {
        return $this->content;
    }

    public function reasoning(): string
    {
        return $this->reasoning;
    }

    public function finishReason(): string
    {
        return $this->finishReason;
    }

    /**
     * @return array<string, mixed>
     */
    public function usage(): array
    {
        return $this->usage;
    }

    public function contentDeltas(): int
    {
        return $this->contentDeltas;
    }

    public function reasoningDeltas(): int
    {
        return $this->reasoningDeltas;
    }

    public function toolCallDeltas(): int
    {
        return $this->toolCallDeltas;
    }

    public function hasToolCallFragments(): bool
    {
        return [] !== $this->calls;
    }

    /**
     * Finalize the accumulated fragments.
     *
     * @return array{toolCalls: list<array{id: string, name: string, arguments: array<string, mixed>}>, dropped: int}
     */
    public function finalizeToolCalls(): array
    {
        $calls = [];
        $dropped = 0;

        ksort($this->calls);

        foreach ($this->calls as $call) {
            if ('' === $call['id'] || '' === $call['name']) {
                ++$dropped;

                continue;
            }

            $raw = trim($call['arguments']);
            if ('' === $raw) {
                $arguments = [];
            } else {
                $decoded = json_decode($raw, true);
                if (!\is_array($decoded)) {
                    ++$dropped;

                    continue;
                }
                $arguments = $decoded;
            }

            $calls[] = [
                'id' => $call['id'],
                'name' => $call['name'],
                'arguments' => $arguments,
            ];
        }

        return ['toolCalls' => $calls, 'dropped' => $dropped];
    }
}

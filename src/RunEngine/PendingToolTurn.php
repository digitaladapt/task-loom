<?php

declare(strict_types=1);

namespace App\RunEngine;

/**
 * The tool work of one LLM exchange, in progress: the calls the model
 * requested, the results collected so far, and how far the turn has run.
 *
 * This is what makes a tool turn resumable by a fresh worker: every
 * completed call is written back with its result before the next call
 * starts, so a worker that dies mid-turn resumes at `nextIndex` instead of
 * re-running calls that already happened. Calls that may have side effects
 * (mail sent, row written) are exactly why this exists — the smallest
 * possible re-execution window, one in-flight call, is the best an
 * at-least-once queue can offer.
 *
 * `assistantContent` rides along because the exchange appended to the
 * window at the end of the tool turn needs the assistant message exactly as
 * the model produced it (SPEC §5.6: replayed verbatim, minus reasoning).
 *
 * @internal
 */
final class PendingToolTurn
{
    /**
     * @param list<array{id: string, name: string, arguments: array<string, mixed>}> $calls
     * @param list<array{toolCallId: string, content: string}>                       $results results for calls[0 .. nextIndex-1], in order
     */
    public function __construct(
        public readonly int $step,
        public readonly ?string $assistantContent,
        public array $calls,
        public array $results = [],
        public int $nextIndex = 0,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $calls = [];
        $rawCalls = $data['calls'] ?? [];
        if (\is_array($rawCalls)) {
            foreach ($rawCalls as $call) {
                if (!\is_array($call)) {
                    continue;
                }
                $arguments = $call['arguments'] ?? [];
                $calls[] = [
                    'id' => (string) ($call['id'] ?? ''),
                    'name' => (string) ($call['name'] ?? ''),
                    'arguments' => \is_array($arguments) ? $arguments : [],
                ];
            }
        }

        $results = [];
        $rawResults = $data['results'] ?? [];
        if (\is_array($rawResults)) {
            foreach ($rawResults as $result) {
                if (!\is_array($result)) {
                    continue;
                }
                $results[] = [
                    'toolCallId' => (string) ($result['toolCallId'] ?? ''),
                    'content' => (string) ($result['content'] ?? ''),
                ];
            }
        }

        return new self(
            step: (int) ($data['step'] ?? 0),
            assistantContent: isset($data['assistantContent']) ? (string) $data['assistantContent'] : null,
            calls: $calls,
            results: $results,
            nextIndex: (int) ($data['nextIndex'] ?? 0),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'step' => $this->step,
            'assistantContent' => $this->assistantContent,
            'calls' => $this->calls,
            'results' => $this->results,
            'nextIndex' => $this->nextIndex,
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Admin;

use App\Entity\RunEvent;
use App\Entity\RunEventType;

/**
 * One attempt-ledger row as the run page renders it (SPEC §8): a type
 * label, a one-line summary drawn from the typed payload, the error class
 * and retry attempt when present, and the raw payload for the expandable
 * detail. Rendering only — nothing here writes.
 */
final readonly class TimelineEntry
{
    public function __construct(
        public RunEvent $event,
        public string $label,
        public string $summary,
        public ?string $duration,
        public bool $isError,
    ) {
    }

    public static function fromEvent(RunEvent $event): self
    {
        $payload = $event->getPayload();
        $error = null !== $event->getErrorClass();

        $summary = match ($event->getType()) {
            RunEventType::LlmRequest => \sprintf('step %s', self::value($payload, 'step', '?')),
            RunEventType::LlmResponse => self::llmResponseSummary($payload),
            RunEventType::ToolCall => self::toolSummary($payload, 'call'),
            RunEventType::ToolResult => self::toolSummary($payload, 'result'),
            RunEventType::ToolValidationError => self::toolSummary($payload, 'validation error'),
            RunEventType::ToolRetry => self::toolSummary($payload, 'retry'),
            RunEventType::CircuitBreaker => \sprintf(
                'tool %s — %s',
                self::value($payload, 'tool', '?'),
                self::value($payload, 'errorClass', '?'),
            ),
            RunEventType::ContextTrim => 'window trimmed',
            RunEventType::Checkpoint => \sprintf('step %s committed', self::value($payload, 'step', '?')),
            RunEventType::Completion => self::truncate(self::value($payload, 'result', '(empty artifact)')),
            RunEventType::Failure => self::value($payload, 'reason', 'failed'),
        };

        $attempt = $event->getAttemptNo();
        if (null !== $attempt && $attempt > 1 && !$error) {
            $summary .= \sprintf(' (attempt %d)', $attempt);
        }

        return new self(
            event: $event,
            label: self::label($event->getType()),
            summary: $summary,
            duration: self::duration($event->getDurationMs()),
            isError: $error,
        );
    }

    private static function label(RunEventType $type): string
    {
        return ucwords(str_replace('_', ' ', $type->value));
    }

    /** @param array<string, mixed> $payload */
    private static function llmResponseSummary(array $payload): string
    {
        $content = $payload['content'] ?? null;
        $finish = self::value($payload, 'finishReason', 'done');

        if (\is_string($content) && '' !== trim($content)) {
            return \sprintf('%s · %s', self::truncate($content), $finish);
        }

        return \sprintf('(no content) · %s', $finish);
    }

    /** @param array<string, mixed> $payload */
    private static function toolSummary(array $payload, string $verb): string
    {
        $tool = self::value($payload, 'tool', '?');

        // ToolResult carries either content (success) or detail (the typed
        // error message); the call itself carries its arguments.
        $detail = null;
        if (\array_key_exists('detail', $payload)) {
            $detail = self::value($payload, 'detail', '');
        } elseif (\array_key_exists('content', $payload)) {
            $detail = self::value($payload, 'content', '');
        } elseif (\array_key_exists('arguments', $payload)) {
            $detail = json_encode($payload['arguments'], \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        }

        $suffix = null !== $detail && '' !== $detail ? ' — '.self::truncate($detail) : '';

        return \sprintf('%s %s%s', $verb, $tool, $suffix);
    }

    /** @param array<string, mixed> $payload */
    private static function value(array $payload, string $key, string $fallback): string
    {
        $value = $payload[$key] ?? null;
        if (\is_scalar($value)) {
            return (string) $value;
        }

        return $fallback;
    }

    private static function truncate(string $text, int $max = 160): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);
        if (\strlen($text) <= $max) {
            return $text;
        }

        return substr($text, 0, $max - 1).'…';
    }

    private static function duration(?int $durationMs): ?string
    {
        if (null === $durationMs || $durationMs <= 0) {
            return null;
        }

        if ($durationMs < 1000) {
            return \sprintf('%d ms', $durationMs);
        }

        return \sprintf('%.1f s', $durationMs / 1000);
    }
}

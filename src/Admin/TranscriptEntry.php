<?php

declare(strict_types=1);

namespace App\Admin;

use App\Context\Utf8;
use App\Entity\Run;
use App\Entity\RunEvent;
use App\Entity\RunEventType;

/**
 * One message of the run page's transcript (SPEC §8: "the full transcript").
 *
 * Reconstructed from the attempt ledger — the DB is the full, honest
 * record, so the transcript is derived from the same events the timeline
 * shows: the frozen prompt head (system + user) from the checkpoint, then
 * every LLM response, dispatched tool call, and tool result in ledger
 * order. Only what the model saw is trimmed (SPEC §5.5); this view shows
 * everything that was committed.
 */
final readonly class TranscriptEntry
{
    public function __construct(
        public string $role,
        public string $roleLabel,
        public string $content,
        public bool $isError,
    ) {
    }

    /**
     * @param list<RunEvent> $events the run's full timeline, in seq order
     *
     * @return list<self>
     */
    public static function build(Run $run, array $events): array
    {
        $entries = [];

        $checkpoint = $run->getCheckpoint();
        $head = $checkpoint['promptHead'] ?? null;
        if (\is_array($head)) {
            $system = $head['system'] ?? null;
            if (\is_string($system) && '' !== trim($system)) {
                $entries[] = new self('system', 'System', $system, false);
            }

            $user = $head['user'] ?? null;
            if (\is_string($user) && '' !== trim($user)) {
                $entries[] = new self('user', 'User', $user, false);
            }
        }

        foreach ($events as $event) {
            $payload = $event->getPayload();

            switch ($event->getType()) {
                case RunEventType::LlmResponse:
                    $content = $payload['content'] ?? null;
                    if (\is_string($content) && '' !== trim($content)) {
                        $entries[] = new self('assistant', 'Assistant', $content, false);
                    }
                    break;

                case RunEventType::ToolCall:
                    $arguments = $payload['arguments'] ?? [];
                    $entries[] = new self(
                        'call',
                        'Tool call',
                        \sprintf(
                            '%s(%s)',
                            self::scalar($payload['tool'] ?? null, '?'),
                            json_encode(\is_array($arguments) ? $arguments : [], \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE) ?: '{}',
                        ),
                        false,
                    );
                    break;

                case RunEventType::ToolResult:
                    // The ledger now keeps a tool result whole and the window
                    // caps it at render (docs/design/THE_STREAM.md §2.3), so
                    // this transcript is the one place holding the untruncated
                    // blob — a 100 KB result in a <pre> is a page that takes
                    // seconds to lay out. Bound the *view*, not the record: the
                    // marker says how much was withheld, so a reader who wants
                    // the rest knows it is there and can go to the ledger.
                    $detail = self::scalar($payload['detail'] ?? $payload['content'] ?? '', '');
                    $entries[] = new self(
                        'tool',
                        'Tool',
                        \sprintf('%s → %s', self::scalar($payload['tool'] ?? null, '?'), self::excerpt($detail)),
                        null !== $event->getErrorClass(),
                    );
                    break;

                case RunEventType::ToolValidationError:
                    $entries[] = new self(
                        'tool',
                        'Tool',
                        \sprintf(
                            '%s → invalid arguments: %s',
                            self::scalar($payload['tool'] ?? null, '?'),
                            self::scalar($payload['detail'] ?? null, ''),
                        ),
                        true,
                    );
                    break;

                default:
                    // LlmRequest, ToolRetry, CircuitBreaker, ContextTrim,
                    // Checkpoint, Completion, Failure: not messages — the
                    // timeline shows them where they belong.
                    break;
            }
        }

        return $entries;
    }

    private static function scalar(mixed $value, string $fallback): string
    {
        return \is_scalar($value) ? (string) $value : $fallback;
    }

    /**
     * A tool result is unbounded since the cap moved to render time, so the
     * transcript shows an opening excerpt and says plainly how much it left
     * out. Character-safe for the same reason every other cut is.
     */
    private static function excerpt(string $text, int $max = 2000): string
    {
        if (\strlen($text) <= $max) {
            return $text;
        }

        return Utf8::cut($text, $max).sprintf('\n…[%d more bytes in the ledger]', \strlen($text) - $max);
    }
}

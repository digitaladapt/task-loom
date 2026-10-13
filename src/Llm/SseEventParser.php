<?php

declare(strict_types=1);

namespace App\Llm;

/**
 * Incremental Server-Sent Events parser — the subset OpenAI-compatible
 * endpoints actually emit.
 *
 * Bytes arrive in arbitrary pieces (TCP fragments, curl callbacks), so the
 * parser holds a partial line until its newline arrives, and a partial
 * event until its blank-line terminator arrives. `feed()` returns the
 * complete event payloads found in this chunk; `flush()` is called once at
 * end-of-stream to surface anything buffered.
 *
 * Deliberately tolerant where tolerance is safe, strict where it is not:
 * comments (`: keep-alive`), `event:`, `id:` and `retry:` lines are
 * recognized SSE fields we have no use for and are ignored; a `data:` line
 * is kept exactly as sent (one leading space stripped, per the spec).
 * Multi-line `data:` events join with "\n", per the spec. A line with no
 * colon is not a valid SSE field — it is ignored here rather than guessed
 * at, because a server emitting bare JSON instead of SSE is detected as
 * "no data: field was ever seen" and handled by the caller's fallback,
 * which is a decision the caller must make, not this parser.
 *
 * `hasSeenDataField()` is that signal: false means this response was never
 * SSE at all (an endpoint that ignored `stream: true` and replied with one
 * plain JSON body).
 */
final class SseEventParser
{
    private string $lineBuffer = '';

    /** @var list<string> */
    private array $dataLines = [];

    private bool $sawDataField = false;

    private int $eventsEmitted = 0;

    /**
     * Feed raw bytes; return the complete SSE event payloads they contain.
     *
     * @return list<string>
     */
    public function feed(string $bytes): array
    {
        $events = [];
        $this->lineBuffer .= $bytes;

        while (false !== $pos = strpos($this->lineBuffer, "\n")) {
            $line = substr($this->lineBuffer, 0, $pos);
            $this->lineBuffer = substr($this->lineBuffer, $pos + 1);
            $line = rtrim($line, "\r");

            if ('' === $line) {
                $this->dispatch($events);

                continue;
            }

            if (str_starts_with($line, ':')) {
                continue; // comment / keep-alive
            }

            if (str_starts_with($line, 'data:')) {
                $value = substr($line, 5);
                if (str_starts_with($value, ' ')) {
                    $value = substr($value, 1);
                }
                $this->dataLines[] = $value;
                $this->sawDataField = true;
            }

            // event:, id:, retry:, anything else — not needed for this dialect.
        }

        return $events;
    }

    /**
     * End-of-stream: surface any complete event still awaiting its blank
     * line. An unterminated line fragment is discarded (it was torn off by
     * the cut, and a half-written line is not a thing that was sent).
     *
     * @return list<string>
     */
    public function flush(): array
    {
        $events = [];
        $this->dispatch($events);

        return $events;
    }

    public function hasSeenDataField(): bool
    {
        return $this->sawDataField;
    }

    public function eventsEmitted(): int
    {
        return $this->eventsEmitted;
    }

    /**
     * The unterminated tail, for diagnostics only.
     */
    public function pendingLineFragment(): string
    {
        return $this->lineBuffer;
    }

    /**
     * @param list<string> $events
     */
    private function dispatch(array &$events): void
    {
        if ([] === $this->dataLines) {
            return;
        }

        $payload = implode("\n", $this->dataLines);
        $this->dataLines = [];

        // An empty data field (`data:` with no value) is a valid SSE shape
        // that carries nothing for this dialect; it is seen but not emitted.
        if ('' === trim($payload)) {
            return;
        }

        $events[] = $payload;
        ++$this->eventsEmitted;
    }
}

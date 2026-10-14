<?php

declare(strict_types=1);

namespace App\Llm;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\Chunk\DataChunk;
use Symfony\Component\HttpClient\Chunk\ErrorChunk;
use Symfony\Component\HttpClient\Chunk\FirstChunk;
use Symfony\Component\HttpClient\Chunk\LastChunk;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * OpenAI-compatible chat-completions client (SPEC §2.3: local-first,
 * Ollama/vLLM/llama.cpp — or any gateway speaking the same dialect), now
 * streamed.
 *
 * ## Why streaming, and what it changes
 *
 * The old client sent one blocking request and read the body at the end, so
 * a model that "spun forever" was a black box: at the timeout there was
 * nothing to look at, no way to tell a loop from a heavy prompt from a
 * wedged server, and no partial output to show a person who asked a
 * question. Streaming changes all of that:
 *
 *  - **Every delta is observed as it arrives.** The output is counted,
 *    timed, scanned for loops, and — when a guard cuts the generation —
 *    returned as the partial result (partial output is output; see
 *    {@see LlmResponse::$stop}).
 *  - **Guards replace the single blunt timeout.** Four independent windows
 *    (first token, mid-stream silence, soft deadline with bounded
 *    extensions, hard cap) plus a loop detector name exactly why a
 *    generation was cut, each with its own error class, instead of a bare
 *    `server_timeout` at the end (see {@see LlmStopReason}).
 *  - **Stopping means stopping the endpoint too.** When a guard fires (or
 *    the read otherwise ends without a clean completion), the response is
 *    cancelled, which closes the socket — the signal a local server needs
 *    to abort the generation instead of burning GPU on output nobody will
 *    read. Verified against a real socket: cancel() drops the established
 *    TCP connection at the OS level.
 *
 * ## How the read loop works
 *
 * Symfony's stream API delivers data chunks as they arrive; an *idle
 * window* with no data yields a timeout chunk (`ErrorChunk::isTimeout()`)
 * and ends that stream session, and re-entering `stream()` on the same
 * response resumes where it left off (verified against a live socket — no
 * chunk is lost, and `[DONE]`/last-chunk still arrive). Two consequences
 * shape this loop:
 *
 *  1. A session that keeps receiving data never ends — so the guard
 *     windows are checked **per chunk**, not only per idle tick. A model
 *     babbling continuously must still hit the soft/hard deadline.
 *  2. A session that idles ends cheaply, so the time-based guards are also
 *     checked between sessions. Every session is bounded by the guard
 *     tick, and `stream()` is called with that same window.
 *
 * ## What arrives on the wire
 *
 * SSE events (`data: {...}`) are parsed by {@see SseEventParser} and
 * reassembled by {@see LlmDeltaAccumulator}, including `tool_calls`
 * entries whose `arguments` string is split across many chunks. A
 * non-2xx reply is classified from its body exactly as before; a 2xx
 * reply that is *not* SSE (an endpoint that ignored `stream: true` and
 * sent one plain JSON body) is parsed with the old non-streamed parser,
 * so the change is safe against gateways that swallow the flag.
 *
 * `reasoning_content` (thinking models) is streamed, counted, and stored
 * on the response payload, but never re-sent as conversation content
 * (SPEC §5.6).
 *
 * `TASKLOOM_DEBUG_RAW_LLM=1` dumps what the parser saw to the log. It
 * stays off by default.
 */
final class LlmClient implements LlmClientInterface
{
    private readonly bool $debugRaw;

    private readonly LlmGuards $guards;

    /**
     * @param int   $timeoutSeconds   legacy bound: a whole non-streamed request. It
     *                                also feeds {@see LlmGuards::legacy()} when no guards are supplied, so an
     *                                existing deployment's number keeps its old meaning.
     * @param float $guardTickSeconds the read loop's resolution: the idle window handed
     *                                to `stream()` and the upper bound on how late any time-based guard can
     *                                fire. Internal; exposed so tests can drive guards at sub-second
     *                                resolution. Must not exceed any guard window (validated below).
     */
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $baseUrl,
        private readonly string $model,
        private readonly string $apiKey = '',
        private readonly int $timeoutSeconds = 300,
        private readonly ?LoggerInterface $logger = null,
        ?string $debugRawResponse = null,
        ?LlmGuards $guards = null,
        private readonly float $guardTickSeconds = 0.25,
    ) {
        $this->debugRaw = self::resolveBool('TASKLOOM_DEBUG_RAW_LLM', $debugRawResponse);
        $this->guards = $guards ?? LlmGuards::legacy($this->timeoutSeconds);

        if ($this->guardTickSeconds <= 0) {
            throw new \InvalidArgumentException(sprintf('The guard tick must be positive, got %s.', $this->guardTickSeconds));
        }
        foreach ([$this->guards->firstTokenSeconds, $this->guards->silenceSeconds, $this->guards->softDeadlineSeconds] as $window) {
            if ($this->guardTickSeconds > $window) {
                throw new \InvalidArgumentException(sprintf('The guard tick (%ss) is above a guard window (%ss) — the window could never fire. Lower the tick or raise the window.', $this->guardTickSeconds, $window));
            }
        }
    }

    /**
     * @param array<string, mixed> $config base_url, model, api_key, timeout, plus the guard
     *                                     knobs as unparsed env strings (see {@see LlmGuards::fromEnv()})
     */
    public static function fromConfig(array $config, ?HttpClientInterface $httpClient = null): self
    {
        $timeout = (int) ($config['timeout'] ?? 300);

        return new self(
            $httpClient ?? HttpClient::create(),
            (string) ($config['base_url'] ?? ''),
            (string) ($config['model'] ?? ''),
            (string) ($config['api_key'] ?? ''),
            $timeout,
            null,
            null,
            LlmGuards::fromEnv(
                $timeout,
                self::stringOrNull($config['first_token'] ?? null),
                self::stringOrNull($config['silence'] ?? null),
                self::stringOrNull($config['soft_deadline'] ?? null),
                self::stringOrNull($config['soft_extension'] ?? null),
                self::stringOrNull($config['max_extensions'] ?? null),
                self::stringOrNull($config['max_duration'] ?? null),
                self::stringOrNull($config['loop_min_span'] ?? null),
                self::stringOrNull($config['loop_min_repeats'] ?? null),
                self::stringOrNull($config['loop_min_recurrence_repeats'] ?? null),
            ),
            (float) ($config['guard_tick'] ?? 0.25),
        );
    }

    #[\Override]
    public function chat(array $messages, array $tools = []): LlmResponse
    {
        $payload = [
            'model' => $this->model,
            'messages' => $messages,
            'stream' => true,
            // Ask for the usage block on a streamed response. OpenAI-compatible
            // endpoints (llama.cpp, vLLM, Ollama) only emit the final
            // usage-only chunk when this is set; without it `usage` comes back
            // empty and the ledger loses the token counts — including
            // `prompt_tokens_details.cached_tokens`, which is how a KV-cache
            // hit is seen at all (see docs/design/KV_CACHE.md §7.1). The flag
            // adds nothing but the final chunk, so it is safe for every
            // endpoint in the dialect.
            'stream_options' => ['include_usage' => true],
        ];

        if ([] !== $tools) {
            $payload['tools'] = $tools;
            $payload['tool_choice'] = 'auto';
        }

        $headers = ['Content-Type' => 'application/json', 'Accept' => 'text/event-stream'];
        if ('' !== $this->apiKey) {
            $headers['Authorization'] = 'Bearer '.$this->apiKey;
        }

        $startedAt = microtime(true);

        try {
            $response = $this->httpClient->request('POST', rtrim($this->baseUrl, '/').'/v1/chat/completions', [
                'headers' => $headers,
                'json' => $payload,
                // The idle window and the guard tick are the same number on
                // purpose: every tick is one receive-window on the socket, and
                // every time-based guard moves in whole ticks.
                'timeout' => max(0.05, $this->guardTickSeconds),
                'buffer' => false,
            ]);
        } catch (TransportExceptionInterface $e) {
            throw LlmRequestException::transport($e);
        } catch (\Throwable $e) {
            throw LlmRequestException::transport($e);
        }

        return $this->consumeStream($response, $startedAt);
    }

    /**
     * Read the streamed response to its end, a guard cut, or a transport
     * failure — returning a partial result for every non-throwing exit.
     */
    private function consumeStream(ResponseInterface $response, float $startedAt): LlmResponse
    {
        $status = null;
        $accumulator = new LlmDeltaAccumulator();
        $parser = new SseEventParser();
        $contentDetector = new LoopDetector(
            minSpanChars: $this->guards->loopMinSpanChars,
            minRepeats: $this->guards->loopMinRepeats,
            minRecurrenceRepeats: $this->guards->loopMinRecurrenceRepeats,
        );
        $reasoningDetector = new LoopDetector(
            minSpanChars: $this->guards->loopMinSpanChars,
            minRepeats: $this->guards->loopMinRepeats,
            minRecurrenceRepeats: $this->guards->loopMinRecurrenceRepeats,
        );

        $chunks = 0;
        $firstDeltaMs = null;
        $firstContentMs = null;
        $extensionsUsed = 0;
        $lastOutputAt = null;
        $sawLast = false;
        $doneSeen = false;
        $nonSseMode = false;
        $nonSseBody = '';

        $stop = null;
        $fatal = null;

        $softDeadline = $this->guards->softDeadlineSeconds;

        try {
            while (true) {
                $sawLast = false;

                try {
                    foreach ($this->httpClient->stream([$response], max(0.05, $this->guardTickSeconds)) as $chunk) {
                        if ($chunk instanceof LastChunk) {
                            $sawLast = true;

                            break;
                        }

                        if ($chunk instanceof ErrorChunk) {
                            // An idle window with nothing to deliver — the
                            // tick the time-based guards run on.
                            try {
                                if ($chunk->isTimeout()) {
                                    // A quiet socket mid-generation is exactly
                                    // what the silence window watches for, so
                                    // the guards get to look here too — a
                                    // session can end without ever yielding a
                                    // data chunk to check after.
                                    $stop = $this->checkTimeGuards($startedAt, $lastOutputAt, $chunks, $softDeadline, $extensionsUsed);
                                    if (null !== $stop) {
                                        break 2;
                                    }

                                    continue;
                                }
                            } catch (TransportException $e) {
                                $fatal = LlmRequestException::transport($e);

                                break 2;
                            } catch (\Throwable $e) {
                                $fatal = LlmRequestException::transport($e);

                                break 2;
                            }

                            continue;
                        }

                        if ($chunk instanceof DataChunk) {
                            $bytes = $chunk->getContent();
                            if ('' === $bytes) {
                                continue;
                            }

                            if (null === $status) {
                                $status = $this->safeStatus($response);
                            }

                            // A non-2xx body, or a 2xx body that starts as a
                            // JSON object, is not SSE: collect it byte-for-byte
                            // and parse it after the loop with the old parser.
                            $trimmed = ltrim($bytes);
                            if (!$nonSseMode && !$parser->hasSeenDataField()
                                && ((null !== $status && $status >= 400) || ('' !== $trimmed && '{' === $trimmed[0]))) {
                                $nonSseMode = true;
                            }

                            if ($nonSseMode) {
                                $nonSseBody .= $bytes;

                                continue;
                            }

                            foreach ($parser->feed($bytes) as $eventPayload) {
                                if ('[DONE]' === trim($eventPayload)) {
                                    $doneSeen = true;

                                    break;
                                }

                                $event = json_decode($eventPayload, true);
                                if (!\is_array($event)) {
                                    $stop = new LlmStop(
                                        reason: LlmStopReason::Malformed,
                                        detail: 'an SSE event was not a JSON object',
                                        droppedToolCalls: $accumulator->finalizeToolCalls()['dropped'],
                                    );

                                    break 2;
                                }

                                $applied = $accumulator->applyEvent($event);

                                if ($applied['model']) {
                                    $now = microtime(true);
                                    $lastOutputAt = $now;
                                    ++$chunks;
                                    $firstDeltaMs ??= (int) round(($now - $startedAt) * 1000);
                                    if ('' !== $applied['content'] && null === $firstContentMs) {
                                        $firstContentMs = (int) round(($now - $startedAt) * 1000);
                                    }
                                }

                                if ('' !== $applied['content']) {
                                    $hit = $contentDetector->observe($applied['content']);
                                    if (null !== $hit) {
                                        $stop = new LlmStop(
                                            reason: LlmStopReason::LoopDetected,
                                            detail: sprintf('output stopped progressing: %s (TASKLOOM_LLM_LOOP_* thresholds)', $hit->describe()),
                                            droppedToolCalls: $accumulator->finalizeToolCalls()['dropped'],
                                        );

                                        break 2;
                                    }
                                }

                                if ('' !== $applied['reasoning']) {
                                    $hit = $reasoningDetector->observe($applied['reasoning']);
                                    if (null !== $hit) {
                                        $stop = new LlmStop(
                                            reason: LlmStopReason::LoopDetected,
                                            detail: sprintf("the model's thinking stopped progressing: %s (TASKLOOM_LLM_LOOP_* thresholds)", $hit->describe()),
                                            droppedToolCalls: $accumulator->finalizeToolCalls()['dropped'],
                                        );

                                        break 2;
                                    }
                                }
                            }

                            if ($doneSeen) {
                                // The completion announced itself as complete;
                                // stop reading rather than wait for the socket.
                                break;
                            }

                            // Per-chunk guard check: a session that keeps
                            // receiving data never ends, so the deadlines must
                            // be enforced here too.
                            $stop = $this->checkTimeGuards($startedAt, $lastOutputAt, $chunks, $softDeadline, $extensionsUsed);
                            if (null !== $stop) {
                                break 2;
                            }

                            continue;
                        }

                        if ($chunk instanceof FirstChunk && null === $status) {
                            $status = $this->safeStatus($response);
                        }
                    }
                } catch (HttpExceptionInterface $e) {
                    // A 4xx/5xx that surfaced while reading the stream (an
                    // auth failure, a model-not-found): classified from its
                    // status and body, exactly as the non-streamed path
                    // classifies one, rather than appearing as a transport
                    // failure.
                    $fatal = $this->httpErrorFromException($e);

                    break;
                } catch (TransportExceptionInterface $e) {
                    $fatal = LlmRequestException::transport($e);

                    break;
                } catch (\Throwable $e) {
                    $stop = new LlmStop(
                        reason: LlmStopReason::Malformed,
                        detail: 'the stream carried a payload that is not a valid completion: '.$e->getMessage(),
                        droppedToolCalls: $accumulator->finalizeToolCalls()['dropped'],
                    );

                    break;
                }

                // A recorded stop lands here when it came from inside the
                // event loop (an inner break exits the chunk loop, not the
                // read loop) — the one exit this level owns. A fatal always
                // lives in a catch that exits the read loop outright.
                if (null !== $stop) {
                    break;
                }

                if ($sawLast || $doneSeen) {
                    break;
                }

                // ---- Between sessions: the session ended without a last
                // chunk, so an idle tick just happened or the stream paused.
                // Run the guards against the wall clock; if nothing fires,
                // re-enter stream() and resume.
                $stop = $this->checkTimeGuards($startedAt, $lastOutputAt, $chunks, $softDeadline, $extensionsUsed);
                if (null !== $stop) {
                    break;
                }
            }
        } finally {
            // A clean end needs no signal; a cut, a failure, or an end
            // that stopped short of the socket's own close does. Cancelling
            // closes the connection — the signal a local endpoint needs to
            // stop generating — and stops a cut being mistaken for
            // completion by anything downstream.
            if (!$sawLast || null !== $stop || null !== $fatal) {
                try {
                    $response->cancel();
                } catch (\Throwable) {
                    // cancel() on an already-finished or broken response is a
                    // no-op; there is nothing left to signal.
                }
            }
        }

        // Flush a complete event still buffered when the stream was cut.
        foreach ($parser->flush() as $eventPayload) {
            if ('[DONE]' === trim($eventPayload)) {
                continue;
            }
            $event = json_decode($eventPayload, true);
            if (\is_array($event)) {
                $accumulator->applyEvent($event);
            }
        }

        $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);

        // ---- Non-SSE fallback: parse the collected body the old way.
        if ($nonSseMode || ('' !== $nonSseBody && !$parser->hasSeenDataField())) {
            if ($this->debugRaw) {
                $this->logger?->info('Raw LLM response (TASKLOOM_DEBUG_RAW_LLM=1).', [
                    'model' => $this->model,
                    'status' => $status,
                    'body' => $nonSseBody,
                ]);
            }

            $body = self::safeDecode($nonSseBody);

            if (null !== $status && $status >= 400) {
                throw LlmRequestException::httpError($status, self::errorDetail($body));
            }

            if (null !== $fatal && '' === $nonSseBody) {
                throw $fatal;
            }

            if (null !== $stop) {
                throw LlmRequestException::streamStop($stop);
            }

            try {
                return self::parseResponse($body, $elapsedMs);
            } catch (\Throwable $e) {
                throw LlmRequestException::malformed($e);
            }
        }

        if (null === $status) {
            $status = $this->safeStatus($response);
        }

        if (null !== $status && $status >= 400) {
            // The exception-carried classification (status + error body)
            // names the failure; composing one from an empty buffer would
            // say "unknown error" where "Invalid API Key" is available.
            throw $fatal ?? LlmRequestException::httpError($status, self::errorDetail(self::safeDecode($nonSseBody)));
        }

        if (null !== $fatal && null === $stop) {
            if ($chunks > 0 || '' !== $accumulator->content() || $accumulator->reasoningDeltas() > 0) {
                // Partial output exists: keep it, and record why the read
                // ended. (The alternative — an error where the user could
                // have seen what was said — is the thing this change fixes.)
                $stop = new LlmStop(
                    reason: LlmStopReason::StreamError,
                    detail: 'the connection to the model failed mid-stream: '.$fatal->getMessage(),
                );
            } else {
                throw $fatal;
            }
        }

        $finalized = $accumulator->finalizeToolCalls();
        $toolCalls = $finalized['toolCalls'];
        $droppedCalls = $finalized['dropped'];

        if (null !== $stop) {
            if (0 !== $droppedCalls && 0 === $stop->droppedToolCalls) {
                $stop = new LlmStop($stop->reason, $stop->detail, $droppedCalls);
            }

            // A cut with nothing captured is not "partial output": it is a
            // failure carrying its named reason.
            $hasOutput = '' !== $accumulator->content()
                || $accumulator->reasoningDeltas() > 0
                || [] !== $toolCalls;

            if (!$hasOutput) {
                throw LlmRequestException::streamStop($stop);
            }
        }

        if ($this->debugRaw) {
            $this->logger?->info('Streamed LLM response (TASKLOOM_DEBUG_RAW_LLM=1).', [
                'model' => $this->model,
                'status' => $status,
                'chunks' => $chunks,
                'content' => $accumulator->content(),
                'reasoning' => $accumulator->reasoning(),
                'stop' => $stop?->reason->value,
            ]);
        }

        $content = $accumulator->content();
        $reasoning = $accumulator->reasoning();

        return new LlmResponse(
            content: '' === $content ? null : $content,
            finishReason: $accumulator->finishReason(),
            toolCalls: $toolCalls,
            usage: $accumulator->usage(),
            reasoningContent: '' === $reasoning ? null : $reasoning,
            durationMs: $elapsedMs,
            stop: $stop,
            metrics: new LlmStreamMetrics(
                chunks: $chunks,
                firstDeltaMs: $firstDeltaMs,
                firstContentMs: $firstContentMs,
                elapsedMs: $elapsedMs,
                extensions: $extensionsUsed,
            ),
        );
    }

    /**
     * The four windows, evaluated against the wall clock. Returns the stop
     * that should end the generation, or null when it may continue.
     *
     * `$softDeadline` and `$extensionsUsed` are by reference because an
     * extension rewrites them; the client itself stays stateless across
     * requests.
     */
    private function checkTimeGuards(
        float $startedAt,
        ?float $lastOutputAt,
        int $chunks,
        float &$softDeadline,
        int &$extensionsUsed,
    ): ?LlmStop {
        $now = microtime(true);
        $elapsed = $now - $startedAt;

        if (null === $lastOutputAt) {
            if ($elapsed >= $this->guards->firstTokenSeconds) {
                return new LlmStop(
                    reason: LlmStopReason::FirstTokenTimeout,
                    detail: sprintf(
                        'no output at all within %ds (TASKLOOM_LLM_FIRST_TOKEN_TIMEOUT) — prefill, a queue, or a wedged server; nothing was generated',
                        (int) $this->guards->firstTokenSeconds,
                    ),
                );
            }

            return null;
        }

        $idle = $now - $lastOutputAt;
        if ($idle >= $this->guards->silenceSeconds) {
            return new LlmStop(
                reason: LlmStopReason::SilenceTimeout,
                detail: sprintf(
                    'output stalled for %ds mid-generation (TASKLOOM_LLM_SILENCE_TIMEOUT) after %d chunk(s) over %.1fs — the endpoint is not producing',
                    (int) $idle,
                    $chunks,
                    $elapsed,
                ),
            );
        }

        // The soft deadline is checked before the hard cap on purpose: an
        // extension must be grantable up to the moment the extensions are
        // spent, and the hard cap is then the arithmetic backstop (the
        // constructor keeps the extension ceiling at or under it).
        if ($elapsed >= $softDeadline) {
            if ($chunks > 0 && $extensionsUsed < $this->guards->maxSoftExtensions) {
                ++$extensionsUsed;
                $softDeadline += $this->guards->softExtensionSeconds;
                $this->logger?->info('LLM stream: output still flowing at the soft deadline; extending by {extension}s ({used}/{max}).', [
                    'extension' => (int) $this->guards->softExtensionSeconds,
                    'used' => $extensionsUsed,
                    'max' => $this->guards->maxSoftExtensions,
                    'chunks' => $chunks,
                ]);

                return null;
            }

            return new LlmStop(
                reason: LlmStopReason::DurationExceeded,
                detail: sprintf(
                    'still producing output after %ds and %d extension(s) (TASKLOOM_LLM_SOFT_DEADLINE / _SOFT_EXTENSION / _MAX_EXTENSIONS) — %d chunk(s) so far',
                    (int) $elapsed,
                    $extensionsUsed,
                    $chunks,
                ),
            );
        }

        if ($elapsed >= $this->guards->maxDurationSeconds) {
            return new LlmStop(
                reason: LlmStopReason::DurationExceeded,
                detail: sprintf(
                    'still running at the hard cap of %ds (TASKLOOM_LLM_MAX_DURATION) after %d chunk(s) — the turn asked for more time than the deployment allows',
                    (int) $this->guards->maxDurationSeconds,
                    $chunks,
                ),
            );
        }

        return null;
    }

    private function safeStatus(ResponseInterface $response): ?int
    {
        try {
            return $response->getStatusCode();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Classify an HTTP error that surfaced while streaming, from the status
     * and the error body — the same shape `httpError()` renders on the
     * non-streamed path, so the message a person reads does not depend on
     * which transport path noticed the failure.
     */
    private function httpErrorFromException(HttpExceptionInterface $e): LlmRequestException
    {
        $status = 0;
        $detail = 'unknown error';

        try {
            $status = $e->getResponse()->getStatusCode();
        } catch (\Throwable) {
            // The status is what we were told; if even that is unavailable,
            // 0 says "an HTTP failure whose status never arrived".
        }

        try {
            $decoded = self::errorDetail(self::safeDecode($e->getResponse()->getContent(false)));
            if ('unknown error' !== $decoded) {
                $detail = $decoded;
            } else {
                // No structured error body: the exception's own message
                // names more than "unknown error" does.
                $detail = $e->getMessage();
            }
        } catch (\Throwable) {
            $detail = $e->getMessage();
        }

        return LlmRequestException::httpError($status, $detail);
    }

    /**
     * The pre-streaming parser, kept for the non-SSE fallback: an endpoint
     * that ignores `stream: true` behaves exactly as it used to.
     *
     * @param array<string, mixed> $body
     */
    private static function parseResponse(array $body, int $durationMs): LlmResponse
    {
        $choice = $body['choices'][0] ?? null;
        if (!\is_array($choice)) {
            throw new \RuntimeException('Response has no choices[0].');
        }

        $message = $choice['message'] ?? null;
        if (!\is_array($message)) {
            throw new \RuntimeException('Response has no message.');
        }

        $content = $message['content'] ?? null;
        if (null !== $content && !\is_string($content)) {
            throw new \RuntimeException('Response message.content is not a string.');
        }

        $finishReason = $choice['finish_reason'] ?? null;
        if (!\is_string($finishReason) && null !== $finishReason) {
            throw new \RuntimeException('Response finish_reason is not a string.');
        }

        $toolCalls = [];
        foreach (($message['tool_calls'] ?? []) as $call) {
            if (!\is_array($call)) {
                continue;
            }
            $toolCalls[] = self::parseToolCall($call);
        }

        $usage = $body['usage'] ?? [];
        $usageArray = \is_array($usage) ? $usage : [];

        return new LlmResponse(
            content: $content,
            finishReason: \is_string($finishReason) ? $finishReason : '',
            toolCalls: $toolCalls,
            usage: $usageArray,
            reasoningContent: \is_string($message['reasoning_content'] ?? null) ? $message['reasoning_content'] : null,
            durationMs: $durationMs,
        );
    }

    /**
     * @param array<string, mixed> $call
     *
     * @return array{id: string, name: string, arguments: array<string, mixed>}
     */
    private static function parseToolCall(array $call): array
    {
        $id = $call['id'] ?? null;
        $function = $call['function'] ?? null;

        if (!\is_string($id) || '' === $id || !\is_array($function)) {
            throw new \RuntimeException('Tool call is missing id or function.');
        }

        $name = $function['name'] ?? null;
        $arguments = $function['arguments'] ?? null;

        if (!\is_string($name) || '' === $name) {
            $name = '';
        }

        if (null === $arguments || '' === $arguments) {
            $arguments = [];
        } elseif (\is_string($arguments)) {
            $decoded = json_decode($arguments, true, 512, \JSON_THROW_ON_ERROR);
            if (!\is_array($decoded)) {
                throw new \RuntimeException('Tool call arguments did not decode to an object.');
            }
            $arguments = $decoded;
        } elseif (!\is_array($arguments)) {
            throw new \RuntimeException('Tool call arguments are neither string nor array.');
        }

        return [
            'id' => $id,
            'name' => $name,
            'arguments' => $arguments,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function safeDecode(string $body): array
    {
        try {
            $decoded = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return [];
        }

        return \is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function errorDetail(array $body): string
    {
        $message = $body['error']['message'] ?? null;

        return \is_string($message) ? $message : 'unknown error';
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return null === $value ? null : (string) $value;
    }

    /**
     * Absent/empty means off — the flag is opt-in. Anything else must be a
     * recognized boolean, so a typo fails loudly rather than leaving the
     * operator believing a dump is running when it is not.
     *
     * @throws \InvalidArgumentException when the value is not a recognized boolean
     */
    private static function resolveBool(string $var, ?string $value): bool
    {
        if (null === $value || '' === trim($value)) {
            return false;
        }

        return filter_var(trim($value), \FILTER_VALIDATE_BOOL, \FILTER_NULL_ON_FAILURE)
            ?? throw new \InvalidArgumentException(\sprintf('%s must be a boolean like 1/0, true/false, or on/off, got "%s".', $var, $value));
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Unit\Llm;

use Symfony\Component\HttpClient\Chunk\DataChunk;
use Symfony\Component\HttpClient\Chunk\ErrorChunk;
use Symfony\Component\HttpClient\Chunk\FirstChunk;
use Symfony\Component\HttpClient\Chunk\LastChunk;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpClient\Response\ResponseStream;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/**
 * A client whose stream is a scripted sequence of (sleep, chunk) steps.
 *
 * MockResponse cannot stage wall-clock behavior: it walks its iterable
 * eagerly, so every simulated pause happens before the first byte is read.
 * The guard tests need the opposite — time passing *between* reads — so
 * this fake models the socket semantics the transport probes verified: an
 * idle tick is an ErrorChunk that ends the session (the caller resumes),
 * data is data, and the script's end is a LastChunk. The response handle
 * is a real MockResponse, so cancel() and getInfo() behave as production.
 */
final class ScriptedHttpClient implements HttpClientInterface
{
    /**
     * @param list<array{int, string}> $script [microseconds to sleep, payload];
     *                                         '' means an idle tick, a non-empty string a data chunk
     */
    public function __construct(
        private readonly MockResponse $handle,
        private readonly array $script,
    ) {
    }

    /**
     * @param array<string, mixed> $options
     */
    #[\Override]
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        return $this->handle;
    }

    #[\Override]
    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        $response = $this->handle;
        $script = $this->script;

        return new ResponseStream((static function () use ($response, $script): \Generator {
            yield $response => new FirstChunk();

            $offset = 0;
            foreach ($script as [$sleepMicros, $payload]) {
                if ($sleepMicros > 0) {
                    usleep($sleepMicros);
                }

                if ('' === $payload) {
                    yield $response => new ErrorChunk($offset, 'Idle timeout reached for "https://llm.example".');

                    continue;
                }

                yield $response => new DataChunk($offset, $payload);
                $offset += \strlen($payload);
            }

            yield $response => new LastChunk($offset);
        })());
    }

    /**
     * @param array<string, mixed> $options
     */
    #[\Override]
    public function withOptions(array $options): static
    {
        return $this;
    }
}

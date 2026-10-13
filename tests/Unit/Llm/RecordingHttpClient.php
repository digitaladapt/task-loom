<?php

declare(strict_types=1);

namespace App\Tests\Unit\Llm;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/**
 * A decorator that remembers the last response it issued.
 *
 * The cancel assertion needs the actual response object the client used —
 * `getInfo('canceled')` is the client-side record of "the socket was told
 * to close", and it can only be read from the handle itself.
 */
final class RecordingHttpClient implements HttpClientInterface
{
    public ?ResponseInterface $last = null;

    public function __construct(private readonly HttpClientInterface $inner)
    {
    }

    /**
     * @param array<string, mixed> $options
     */
    #[\Override]
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        return $this->last = $this->inner->request($method, $url, $options);
    }

    #[\Override]
    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        return $this->inner->stream($responses, $timeout);
    }

    /**
     * @param array<string, mixed> $options
     */
    #[\Override]
    public function withOptions(array $options): static
    {
        return new static($this->inner->withOptions($options));
    }
}

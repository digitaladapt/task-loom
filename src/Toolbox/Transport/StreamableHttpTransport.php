<?php

declare(strict_types=1);

namespace App\Toolbox\Transport;

use Evenement\EventEmitterTrait;
use PhpMcp\Client\Contracts\TransportInterface;
use PhpMcp\Client\Exception\TransportException;
use PhpMcp\Client\JsonRpc\Message;
use PhpMcp\Client\JsonRpc\Notification;
use PhpMcp\Client\JsonRpc\Request;
use PhpMcp\Client\JsonRpc\Response;
use Psr\Http\Message\ResponseInterface as PsrResponseInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\NullLogger;
use React\EventLoop\LoopInterface;
use React\Http\Browser;
use React\Promise\PromiseInterface;
use React\Socket\Connector;

/**
 * Streamable HTTP transport for php-mcp/client (MCP spec 2025-03-26+).
 *
 * The SDK's built-in Http transport speaks the legacy HTTP+SSE protocol:
 * it GETs the endpoint and waits for an SSE stream — servers implementing
 * the current Streamable HTTP transport answer that GET with 405 and the
 * SDK treats it as a fatal connection failure. This transport implements
 * Streamable HTTP instead: every send() POSTs the JSON-RPC message to the
 * single endpoint URL and the response (application/json or
 * text/event-stream body) is emitted back as a 'message' event.
 *
 * connect() resolves immediately: Streamable HTTP has no persistent
 * connection to establish — the first POST is the handshake.
 */
class StreamableHttpTransport implements LoggerAwareInterface, TransportInterface
{
    use EventEmitterTrait;
    use LoggerAwareTrait;

    private const string ACCEPT = 'application/json, text/event-stream';

    private ?string $sessionId = null;
    private bool $closing = false;

    /**
     * @param array<string, string>|null $headers
     */
    public function __construct(
        private readonly string $url,
        private readonly LoopInterface $loop,
        private readonly ?array $headers = null,
        ?string $sessionId = null,
        private readonly ?Browser $browser = null,
    ) {
        $this->sessionId = $sessionId;
        $this->logger = new NullLogger();
    }

    #[\Override]
    public function connect(): PromiseInterface
    {
        if ($this->closing) {
            return \React\Promise\reject(new TransportException('Transport is closing.'));
        }

        // Nothing to establish: each send() is its own request/response.
        return \React\Promise\resolve(null);
    }

    #[\Override]
    public function send(Message $message): PromiseInterface
    {
        if ($this->closing) {
            return \React\Promise\reject(new TransportException('Transport is closing.'));
        }

        $isNotification = $message instanceof Notification;

        try {
            $json = json_encode($message->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (\JsonException $e) {
            return \React\Promise\reject(new TransportException("Failed to encode message to JSON: {$e->getMessage()}", 0, $e));
        }

        $headers = $this->headers ?? [];
        $headers['Content-Type'] = 'application/json';
        $headers['Accept'] = self::ACCEPT;
        if (null !== $this->sessionId) {
            $headers['Mcp-Session-Id'] = $this->sessionId;
        }

        $browser = $this->browser ?? new Browser(new Connector($this->loop), $this->loop);

        $this->logger->debug('Streamable HTTP: POST', ['url' => $this->url, 'notification' => $isNotification]);

        return $browser->post($this->url, $headers, $json)
            ->then(
                function (PsrResponseInterface $response) use ($message, $isNotification) {
                    $this->handleResponse($response, $message, $isNotification);
                },
                function (\Throwable $error) {
                    $this->logger->error('Streamable HTTP: POST failed', ['error' => $error->getMessage()]);
                    $this->emit('error', [new TransportException("Failed to send POST request: {$error->getMessage()}", 0, $error)]);
                    throw $error;
                },
            )
            ->then(null, static fn () => null); // send() resolves regardless; errors go via 'error' events
    }

    /**
     * Parse a POST response into messages and emit them.
     *
     * Per the Streamable HTTP spec the response body may be JSON (single
     * message, possibly empty for notifications) or an SSE stream (one or
     * more events). The SDK's Client routes 'message' events.
     */
    private function handleResponse(PsrResponseInterface $response, Message $sent, bool $isNotification): void
    {
        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            $body = (string) $response->getBody();
            $this->logger->warning('Streamable HTTP: POST returned non-2xx', ['status' => $status, 'body' => $body]);
            $this->emit('error', [new TransportException("Streamable HTTP request failed with status {$status}: {$body}")]);

            return;
        }

        if (null === $this->sessionId && $response->hasHeader('Mcp-Session-Id')) {
            $this->sessionId = $response->getHeaderLine('Mcp-Session-Id');
            $this->logger->info('Streamable HTTP: session ID received', ['session_id' => $this->sessionId]);
        }

        // 202 Accepted (or empty body): notifications and one-way messages.
        $contentType = strtolower($response->getHeaderLine('Content-Type'));
        $body = (string) $response->getBody();

        if (202 === $status || '' === trim($body)) {
            if (!$isNotification && $sent instanceof Request) {
                $this->logger->warning('Streamable HTTP: request got empty response body', ['status' => $status]);
            }

            return;
        }

        if (str_contains($contentType, 'text/event-stream')) {
            $this->parseSseBody($body);

            return;
        }

        // application/json: single JSON-RPC message.
        try {
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->emit('error', [new TransportException("Streamable HTTP: invalid JSON response: {$e->getMessage()}", 0, $e)]);

            return;
        }

        $parsed = $this->parseMessageData(\is_array($data) ? $data : null);
        if (null !== $parsed) {
            $this->emit('message', [$parsed]);
        }
    }

    /**
     * Parse an SSE-format response body (data: lines grouped by event).
     */
    private function parseSseBody(string $body): void
    {
        foreach (explode("\n\n", $body) as $eventBlock) {
            $data = '';
            foreach (explode("\n", $eventBlock) as $line) {
                if (str_starts_with($line, 'data:')) {
                    $data .= trim(substr($line, 5));
                }
            }

            if ('' === $data) {
                continue;
            }

            try {
                $decoded = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                $this->logger->warning('Streamable HTTP: invalid SSE data line', ['error' => $e->getMessage()]);

                continue;
            }

            $parsed = $this->parseMessageData(\is_array($decoded) ? $decoded : null);
            if (null !== $parsed) {
                $this->emit('message', [$parsed]);
            }
        }
    }

    /**
     * Decode a JSON-RPC payload into the SDK's Message objects (mirrors
     * the SDK's own Stdio/Http parsing).
     */
    /**
     * @param array<string, mixed>|null $data
     */
    private function parseMessageData(?array $data): ?Message
    {
        if (null === $data || ($data['jsonrpc'] ?? null) !== '2.0') {
            return null;
        }

        try {
            if (isset($data['method'])) {
                return isset($data['id'])
                    ? Request::fromArray($data)
                    : Notification::fromArray($data);
            }

            if (isset($data['id'])) {
                return Response::fromArray($data);
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }

    #[\Override]
    public function close(): void
    {
        if ($this->closing) {
            return;
        }

        $this->closing = true;
        $reason = 'Client initiated close.';
        $this->logger->info('Streamable HTTP: closing.', ['session_id' => $this->sessionId]);
        $this->emit('close', [$reason]);
        $this->cleanup();
    }

    private function cleanup(): void
    {
        $this->removeAllListeners();
        $this->sessionId = null;
        $this->closing = true;
    }
}

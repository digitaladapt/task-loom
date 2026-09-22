<?php

declare(strict_types=1);

namespace App\Toolbox\Transport;

use PhpMcp\Client\ClientConfig;
use PhpMcp\Client\Contracts\TransportInterface;
use PhpMcp\Client\Enum\TransportType;
use PhpMcp\Client\Factory\TransportFactory;
use PhpMcp\Client\ServerConfig;
use Psr\Log\LoggerInterface;
use React\EventLoop\LoopInterface;

/**
 * TransportFactory override: builds a StreamableHttpTransport for Http
 * servers instead of the SDK's legacy HTTP+SSE transport (which GETs the
 * endpoint and fails with 405 against modern Streamable HTTP servers).
 *
 * Stdio servers keep the SDK's built-in transport.
 *
 * The parent's loop/logger are private, so this subclass keeps its own
 * references passed to the transports it builds.
 */
class StreamableTransportFactory extends TransportFactory
{
    private readonly LoopInterface $loop;
    private readonly LoggerInterface $logger;

    public function __construct(ClientConfig $clientConfig)
    {
        parent::__construct($clientConfig);

        // Parent props are private; ClientConfig exposes the same values.
        $this->loop = $clientConfig->loop;
        $this->logger = $clientConfig->logger;
    }

    #[\Override]
    public function create(ServerConfig $config): TransportInterface
    {
        if (TransportType::Http === $config->transport) {
            $transport = new StreamableHttpTransport(
                (string) $config->url,
                $this->loop,
                $config->headers,
                $config->sessionId,
            );
            $transport->setLogger($this->logger);

            return $transport;
        }

        return parent::create($config);
    }
}

<?php

declare(strict_types=1);

namespace App\Toolbox;

use App\Entity\McpServer;

/**
 * Maps a server's protocol to the ServerReader that can talk to it.
 *
 * Registered as a service with the two v1 readers keyed by protocol value.
 */
final readonly class ServerReaderRegistry
{
    /**
     * @param array<string, ServerReader> $readers
     */
    public function __construct(private array $readers)
    {
    }

    public function forServer(McpServer $server): ?ServerReader
    {
        return $this->readers[$server->getProtocol()->value] ?? null;
    }
}

<?php

declare(strict_types=1);

namespace App\Toolbox;

use App\Entity\McpServer;
use App\Entity\ServerProtocol;
use PhpMcp\Client\ClientBuilder;
use PhpMcp\Client\Enum\TransportType;
use PhpMcp\Client\ServerConfig;

/**
 * Reads tools from an MCP server (Streamable HTTP) via the php-mcp/client SDK.
 *
 * Blocking initialize() + listTools() — the SDK owns the event loop for the
 * duration of the read; callers must not hold a loop across requests.
 */
final readonly class McpServerReader implements ServerReader
{
    public function __construct(
        private ?int $timeoutSeconds = null,
    ) {
    }

    #[\Override]
    public function read(McpServer $server): array
    {
        if (ServerProtocol::Mcp !== $server->getProtocol()) {
            throw new \LogicException(\sprintf('McpServerReader cannot read a %s server.', $server->getProtocol()->value));
        }

        $config = new ServerConfig(
            name: $server->getName(),
            transport: TransportType::Http,
            url: $server->getUrl(),
            timeout: $this->timeoutSeconds ?? 30.0,
        );

        $client = ClientBuilder::make()
            ->withServerConfig($config)
            ->build();

        $client->initialize();

        try {
            $tools = $client->listTools();
        } finally {
            $client->disconnect();
        }

        $discovered = [];
        foreach ($tools as $tool) {
            $discovered[] = new DiscoveredTool(
                name: $tool->name,
                description: $tool->description,
                schema: $tool->inputSchema,
            );
        }

        return $discovered;
    }
}

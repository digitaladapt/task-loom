<?php

declare(strict_types=1);

namespace App\Toolbox;

use App\Entity\McpServer;
use App\Entity\ServerProtocol;
use Mcp\Client;
use Mcp\Client\Transport\HttpTransport;

/**
 * Reads tools from an MCP server (Streamable HTTP) via the official PHP MCP SDK.
 *
 * Blocking — connect(), listTools() and disconnect() all run inside this call.
 * There is no event loop to own any more: the SDK's HTTP transport uses PSR-18
 * and returns, so callers may hold this across requests safely.
 *
 * This class used to install a hand-rolled Streamable HTTP transport because
 * php-mcp/client's built-in one opened a legacy HTTP+SSE stream (a GET) that
 * modern servers answer with 405. The official SDK's transport POSTs, handles
 * both JSON and SSE response framing, and manages the session header itself, so
 * that workaround — and its 250-line transport — is gone.
 * See docs/design/MCP_SDK_MIGRATION.md.
 */
final readonly class McpServerReader implements ServerReader
{
    /**
     * The client identity advertised in the MCP initialize handshake.
     *
     * Informational to the server, but required by the SDK before build() —
     * hence a named constant rather than a literal, so the identity is
     * greppable when it shows up in a server's logs.
     */
    private const CLIENT_NAME = 'task-loom';
    private const CLIENT_VERSION = '1.0.0';

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

        $timeout = $this->timeoutSeconds ?? 30;

        $client = Client::builder()
            ->setClientInfo(self::CLIENT_NAME, self::CLIENT_VERSION)
            ->setInitTimeout($timeout)
            ->setRequestTimeout($timeout)
            // A catalog sync is an explicit, operator-triggered action: retrying
            // a dead server four times only makes the failure slower to report.
            // The synchronizer already decides what a down server means
            // (SPEC §7: never wipes known tools).
            ->setMaxRetries(0)
            ->build();

        // Streamable HTTP only: no stdio, no SSE (SPEC §11). The URL is the
        // full endpoint, which is what HttpTransport expects.
        $client->connect(new HttpTransport(endpoint: $server->getUrl()));

        try {
            $tools = $client->listTools();
        } finally {
            $client->disconnect();
        }

        $discovered = [];
        foreach ($tools->tools as $tool) {
            $discovered[] = new DiscoveredTool(
                name: $tool->name,
                description: $tool->description,
                schema: $tool->inputSchema,
            );
        }

        return $discovered;
    }
}

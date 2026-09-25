<?php

declare(strict_types=1);

namespace App\Tests\Unit\Toolbox;

use App\Entity\McpServer;
use App\Entity\ServerProtocol;
use App\Toolbox\McpServerReader;
use Mcp\Exception\ConnectionException;
use PHPUnit\Framework\TestCase;

/**
 * A reader that cannot reach its server fails with a *connection* error, not a
 * *configuration* error.
 *
 * The original version of this test guarded a real crash: php-mcp/client 1.0.1
 * refused to build a client without an identity ("Name must be provided using
 * withName()"), so app:catalog:sync died before it ever tried the network. The
 * distinction still matters after the SDK migration — a misconfigured client
 * that fails identically for every server would be indistinguishable from a
 * server that happens to be down, and the synchronizer treats those very
 * differently (SPEC §7: a down server must never wipe known tools).
 *
 * The connection test targets loopback port 1 — the kernel refuses the
 * connection immediately, so no real network is touched and the outcome is
 * deterministic.
 */
final class McpServerReaderTest extends TestCase
{
    public function testReadBuildsClientPastConfiguration(): void
    {
        $reader = new McpServerReader(2);
        $server = new McpServer('dead-server', 'http://127.0.0.1:1/mcp', ServerProtocol::Mcp);

        // A ConfigurationException (or any non-connection failure) means the
        // client was misconfigured; ConnectionException means it was built and
        // genuinely could not reach the endpoint.
        $this->expectException(ConnectionException::class);

        $reader->read($server);
    }

    public function testReadRejectsNonMcpServer(): void
    {
        $reader = new McpServerReader(2);
        $server = new McpServer('openapi-server', 'http://127.0.0.1:1/openapi.json', ServerProtocol::OpenApi);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('cannot read a openapi server');

        $reader->read($server);
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Unit\Toolbox;

use App\Entity\McpServer;
use App\Entity\ServerProtocol;
use App\Toolbox\McpServerReader;
use PhpMcp\Client\Exception\ConnectionException;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for the app:catalog:sync crash where the SDK refused to
 * build a client without identity ("Name must be provided using withName().").
 *
 * The connection test targets loopback port 1 — the kernel refuses the
 * connection immediately, so no real network is touched and the outcome is
 * deterministic. Reaching a ConnectionException (rather than a
 * ConfigurationException) proves the reader built its client successfully.
 */
final class McpServerReaderTest extends TestCase
{
    public function testReadBuildsClientPastConfiguration(): void
    {
        $reader = new McpServerReader(2);
        $server = new McpServer('dead-server', 'http://127.0.0.1:1/mcp', ServerProtocol::Mcp);

        // Before the fix this threw ConfigurationException from build().
        // Now the only failure is the (unreachable) connection attempt itself.
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

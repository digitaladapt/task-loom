<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * Wire protocol of an external tool server (SPEC §7: protocol mcp|openapi).
 *
 * MCP servers speak the Model Context Protocol over Streamable HTTP —
 * discovered via the php-mcp/client SDK. OpenAPI servers are plain
 * HTTP/JSON — discovered by fetching and converting their spec.
 */
enum ServerProtocol: string
{
    case Mcp = 'mcp';
    case OpenApi = 'openapi';
}

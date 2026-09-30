<?php

declare(strict_types=1);

namespace App\Security;

/**
 * The MCP endpoint's credential (TASKLOOM_MCP_API_KEY), and the one place it
 * is compared.
 *
 * The MCP server role at `POST /mcp` serves external agents (the reviewer
 * task, Claude Code, anything that speaks Streamable HTTP). Those callers
 * need a credential that is NOT the human's admin password: an agent's key
 * must be rotatable without changing the password a person types into the
 * login form, and a key pasted into a client config must never double as
 * the interactive credential. Hence a dedicated variable, its own comparison
 * and its own authenticator.
 *
 * Fail closed: an unconfigured deployment (empty key) refuses every bearer
 * request, and `isConfigured()` lets the MCP authenticator answer with a
 * named diagnostic instead of a bare 401.
 *
 * Comparison is constant-time (`hash_equals`), like the password — a bearer
 * token is short enough that a timing oracle on byte-by-byte comparison is
 * a real concern.
 */
final readonly class McpApiKey
{
    public function __construct(
        private string $apiKey,
    ) {
    }

    /**
     * Whether a key has been configured at all. An empty value means "the
     * MCP endpoint has no credential", which is a hard refusal — never a
     * key of "".
     */
    public function isConfigured(): bool
    {
        return '' !== $this->apiKey;
    }

    /**
     * Constant-time comparison against the configured key. False when
     * nothing is configured, so an unconfigured deployment cannot be entered
     * by sending an empty bearer token.
     */
    public function matches(string $presented): bool
    {
        return $this->isConfigured() && \hash_equals($this->apiKey, $presented);
    }
}

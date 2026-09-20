<?php

declare(strict_types=1);

namespace App\Toolbox;

use App\Entity\McpServer;

/**
 * Reads the list of tools a server offers, over its protocol.
 *
 * The synchronizer is the only caller; readers are dumb transports. A reader
 * that cannot reach its server THROWS — the synchronizer decides what a down
 * server means (SPEC §7: never wipes known tools).
 *
 * Implementations must never embed credential VALUES in exceptions —
 * anything a reader throws is logged (and the run engine surfaces it in the
 * admin UI), so a thrown value is a leaked value. Credential handling is
 * owned by the caller (see CredentialResolver).
 */
interface ServerReader
{
    /**
     * @return list<DiscoveredTool>
     *
     * @throws \Throwable on any connectivity/protocol failure
     */
    public function read(McpServer $server): array;
}

/**
 * What a server told us about one of its tools at sync time.
 */
final readonly class DiscoveredTool
{
    /**
     * @param array<string, mixed> $schema JSON Schema for the tool's arguments
     */
    public function __construct(
        public string $name,
        public ?string $description,
        public array $schema,
    ) {
    }
}

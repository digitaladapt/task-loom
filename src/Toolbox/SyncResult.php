<?php

declare(strict_types=1);

namespace App\Toolbox;

use App\Entity\McpServer;

/**
 * Outcome of one server's catalog sync — what the CLI prints and the admin
 * UI badge shows.
 */
final readonly class SyncResult
{
    public function __construct(
        public McpServer $server,
        public bool $ok,
        public int $created,
        public int $updated,
        public int $drifted,
        public string $message,
    ) {
    }
}

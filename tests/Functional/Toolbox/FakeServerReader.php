<?php

declare(strict_types=1);

namespace App\Tests\Functional\Toolbox;

use App\Entity\McpServer;
use App\Toolbox\DiscoveredTool;
use App\Toolbox\ServerReader;

/**
 * Scripted fake: yields the next tool list, or throws.
 */
final class FakeServerReader implements ServerReader
{
    /** @var list<DiscoveredTool>|null */
    public ?array $next = null;
    public ?\Throwable $throw = null;

    #[\Override]
    public function read(McpServer $server): array
    {
        if (null !== $this->throw) {
            throw $this->throw;
        }

        return $this->next ?? [];
    }
}

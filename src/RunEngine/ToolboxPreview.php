<?php

declare(strict_types=1);

namespace App\RunEngine;

use App\Entity\Tool;

/**
 * The result of a ToolboxPreviewer pass: the tools a task would resolve
 * right now, plus human-readable problems explaining gaps (unknown tag,
 * missing tool, disabled server) — the admin UI's enable-time diagnostic.
 */
final class ToolboxPreview
{
    /**
     * @param list<Tool>   $resolved
     * @param list<string> $problems
     */
    public function __construct(
        public readonly array $resolved,
        public readonly array $problems,
    ) {
    }

    public function isEmpty(): bool
    {
        return [] === $this->resolved;
    }

    public function isOk(): bool
    {
        return [] === $this->problems;
    }
}

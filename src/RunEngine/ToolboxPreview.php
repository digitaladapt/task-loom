<?php

declare(strict_types=1);

namespace App\RunEngine;

use App\Entity\Tool;

/**
 * The result of a ToolboxPreviewer pass: the tools a task would resolve
 * right now, plus human-readable problems explaining gaps (unknown tag,
 * missing tool, disabled server) — the admin UI's enable-time diagnostic.
 *
 * A session's frozen toolbox has two halves (SPEC §4.1's harness-tool
 * clause): the declared catalog tools ({@see self::$resolved}) and the
 * harness's own session tools ({@see self::$harness}), which the resolver
 * adds automatically. The preview must show both — with only the declared
 * half on display, a session reads as though it had no tools at all, and
 * an empty declared half would be flagged as a dispatch failure it is not.
 */
final class ToolboxPreview
{
    /**
     * @param list<Tool>   $resolved the declared (catalog) half
     * @param list<string> $problems
     * @param list<string> $harness  the harness's own session tools added on top (names; sessions only)
     */
    public function __construct(
        public readonly array $resolved,
        public readonly array $problems,
        public readonly array $harness = [],
    ) {
    }

    public function isEmpty(): bool
    {
        return [] === $this->resolved && [] === $this->harness;
    }

    public function isOk(): bool
    {
        return [] === $this->problems;
    }
}

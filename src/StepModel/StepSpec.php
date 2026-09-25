<?php

declare(strict_types=1);

namespace App\StepModel;

use App\Entity\ToolboxMode;

/**
 * One step as parsed from the authoring/wire format (SPEC §13.2): the
 * nested-array form carries title, brief, toolbox, and a level — the
 * dependencies are the *previous level* wholesale (levels run in
 * sequence, steps within a level run in parallel).
 *
 * `level` is the 0-based level index in the authored arrays. Ids do not
 * exist yet at parse time; the persistence layer assigns positions,
 * stores the rows, and translates levels into depends_on edges.
 */
final readonly class StepSpec
{
    /**
     * @param list<string> $toolbox
     */
    public function __construct(
        public string $title,
        public string $brief,
        public ToolboxMode $toolboxMode,
        public array $toolbox,
        public int $level,
    ) {
    }
}

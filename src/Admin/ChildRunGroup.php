<?php

declare(strict_types=1);

namespace App\Admin;

use App\Entity\Run;

/**
 * One level of a parent run's step-graph children, as the run page groups
 * them (SPEC §13.6: levels for display). Children are step runs; the final
 * consumer renders separately, after every level.
 */
final readonly class ChildRunGroup
{
    /**
     * @param list<Run> $children step child runs of this level, creation order
     */
    public function __construct(
        public int $level,
        public array $children,
    ) {
    }
}

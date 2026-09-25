<?php

declare(strict_types=1);

namespace App\Admin;

use App\Entity\Step;
use App\RunEngine\ToolboxPreview;

/**
 * One step as the admin UI shows it (SPEC §13.6): the step, its derived
 * level (for the grouped timeline), and its own toolbox preview — each
 * child run resolves its own toolbox at start, so the same empty-toolbox
 * incident class (SPEC §8) applies per step and must be visible before
 * enable.
 */
final readonly class StepView
{
    public function __construct(
        public Step $step,
        public int $level,
        public ToolboxPreview $preview,
    ) {
    }
}

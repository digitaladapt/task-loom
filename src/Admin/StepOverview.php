<?php

declare(strict_types=1);

namespace App\Admin;

/**
 * A stepped task as the admin UI shows it: the steps grouped into their
 * derived levels (SPEC §13.6 — "levels for display"), in display order,
 * plus the graph-validation problems the enable/approve gate would
 * refuse on (SPEC §13.2). Empty steps + empty problems = a zero-step
 * task, which runs exactly as v1.
 */
final readonly class StepOverview
{
    /**
     * @param list<StepView> $views
     * @param list<string>   $problems
     */
    public function __construct(
        public array $views,
        public array $problems,
    ) {
    }

    public function isEmpty(): bool
    {
        return [] === $this->views;
    }

    public function isOk(): bool
    {
        return [] === $this->problems;
    }

    /**
     * How many levels the graph has (display grouping, SPEC §13.6).
     */
    public function levelCount(): int
    {
        $levels = [];
        foreach ($this->views as $view) {
            $levels[$view->level] = true;
        }

        return \count($levels);
    }
}

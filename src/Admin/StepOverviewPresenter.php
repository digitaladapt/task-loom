<?php

declare(strict_types=1);

namespace App\Admin;

use App\Entity\Task;
use App\Repository\StepRepository;
use App\RunEngine\ToolboxPreviewer;
use App\StepModel\StepGraphCodec;
use App\StepModel\StepGraphValidator;

/**
 * Builds the admin UI's step overview for a task (SPEC §13.6): steps in
 * display order with their derived levels, each with its toolbox preview,
 * plus the graph-validation problems the enable/approve gate enforces
 * (SPEC §13.2). Read-only; safe on any task state, including invalid
 * drafts (display stays total — problems are shown, not thrown).
 */
final readonly class StepOverviewPresenter
{
    public function __construct(
        private StepRepository $steps,
        private StepGraphCodec $codec,
        private StepGraphValidator $validator,
        private ToolboxPreviewer $previewer,
    ) {
    }

    public function present(Task $task): StepOverview
    {
        $steps = $this->steps->findForTask($task);
        if ([] === $steps) {
            return new StepOverview([], []);
        }

        $levels = $this->codec->levels($steps);

        $views = [];
        foreach ($steps as $step) {
            $id = $step->getId();
            $views[] = new StepView(
                $step,
                null !== $id ? ($levels[$id] ?? 0) : 0,
                $this->previewer->previewStep($step),
            );
        }

        return new StepOverview($views, $this->validator->problems($steps));
    }
}

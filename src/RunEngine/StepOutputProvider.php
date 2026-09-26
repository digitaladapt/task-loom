<?php

declare(strict_types=1);

namespace App\RunEngine;

use App\Entity\Run;
use App\Entity\RunEvent;
use App\Entity\RunEventType;
use App\Entity\RunRole;
use App\Entity\Step;
use App\Repository\RunEventRepository;
use App\Repository\RunRepository;

/**
 * Reads step outputs off the committed ledger (SPEC §13.4): a step output
 * is exactly one thing — the step run's justified completion artifact —
 * labeled with the step title, frozen at the step's terminal.
 *
 * Reads state, never writes: the graph layer calls this while freezing a
 * child's prompt head, inside the terminal commit that made the
 * dependencies readable. A dependency that "succeeded" without a readable
 * artifact is a corrupt ledger; it fails LOUDLY (StepOutputException)
 * rather than compiling a prompt head with silently missing inputs.
 */
final readonly class StepOutputProvider
{
    public function __construct(
        private RunRepository $runs,
        private RunEventRepository $events,
    ) {
    }

    /**
     * The outputs a step's run receives: its declared dependencies'
     * artifacts, in the order the edges were declared, each labeled with
     * the dependency's title.
     *
     * @return list<StepOutput>
     */
    public function forStep(Run $parent, Step $step): array
    {
        /** @var array<int, Run> $childByStepId */
        $childByStepId = [];
        foreach ($this->runs->findChildren($parent) as $child) {
            $stepId = $child->getStep()?->getId();
            if (RunRole::Step === $child->getRole() && null !== $stepId && !isset($childByStepId[$stepId])) {
                $childByStepId[$stepId] = $child;
            }
        }

        $outputs = [];
        $seen = [];
        foreach ($step->getDependsOn() as $dependency) {
            if (isset($seen[$dependency])) {
                continue;
            }
            $seen[$dependency] = true;

            $child = $childByStepId[$dependency] ?? null;
            if (null === $child) {
                throw new StepOutputException(\sprintf('Step "%s" depends on step #%d, but that step has no run in this graph (SPEC §13.4).', $step->getTitle(), $dependency));
            }

            $outputs[] = $this->outputOf($child->getStep() ?? throw new StepOutputException(\sprintf('A step child run (#%d) has no step row; its output cannot be labeled (SPEC §13.4).', $child->getId())), $child);
        }

        return $outputs;
    }

    /**
     * The outputs the final consumer receives: ALL step outputs of the
     * graph, labeled — not just the leaves' (SPEC §13.4). Predictable
     * beats minimal. Rendered in step display order (position, id), the
     * order the human sees in the task view.
     *
     * @return list<StepOutput>
     */
    public function forFinalConsumer(Run $parent): array
    {
        /** @var list<array{step: Step, child: Run}> $entries */
        $entries = [];
        foreach ($this->runs->findChildren($parent) as $child) {
            if (RunRole::Step !== $child->getRole()) {
                continue;
            }

            $step = $child->getStep();
            if (null === $step) {
                throw new StepOutputException(\sprintf('A step child run (#%d) has no step row; its output cannot be labeled (SPEC §13.4).', $child->getId()));
            }

            $entries[] = ['step' => $step, 'child' => $child];
        }

        usort($entries, static function (array $a, array $b): int {
            return [$a['step']->getPosition(), $a['step']->getId()] <=> [$b['step']->getPosition(), $b['step']->getId()];
        });

        $outputs = [];
        foreach ($entries as $entry) {
            $outputs[] = $this->outputOf($entry['step'], $entry['child']);
        }

        return $outputs;
    }

    /**
     * A run's justified completion artifact — the one string that flows
     * (SPEC §13.4). The turn cores guarantee a non-empty artifact on a
     * success; anything else is corrupt state and throws.
     */
    public function artifactOf(Run $child): string
    {
        $event = $this->events->findOneBy(
            ['run' => $child, 'type' => RunEventType::Completion],
            ['seq' => 'DESC'],
        );

        if (!$event instanceof RunEvent) {
            throw new StepOutputException(\sprintf('No completion artifact found for %s (SPEC §13.4).', $this->describe($child)));
        }

        $result = $event->getPayload()['result'] ?? null;

        if (!\is_string($result) || '' === trim($result)) {
            throw new StepOutputException(\sprintf('The completion artifact of %s is empty (SPEC §13.4).', $this->describe($child)));
        }

        return $result;
    }

    private function outputOf(Step $step, Run $child): StepOutput
    {
        return new StepOutput(
            stepId: (int) $step->getId(),
            title: $step->getTitle(),
            artifact: $this->artifactOf($child),
        );
    }

    /**
     * Identify a child run for a diagnosis: which step (or the final
     * consumer), which run id.
     */
    private function describe(Run $child): string
    {
        $step = $child->getStep();
        $what = null !== $step
            ? \sprintf('step "%s"', $step->getTitle())
            : 'the final consumer';

        return \sprintf('%s (run #%d)', $what, $child->getId());
    }
}

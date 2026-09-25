<?php

declare(strict_types=1);

namespace App\StepModel;

use App\Entity\Step;

/**
 * DAG validation for a task's step graph (SPEC §13.2): no cycles, no
 * self-dependencies, and every dependency edge referencing a step of the
 * same task. Validation runs at task create/update — and again at
 * enable/approve, where it is the enforcement gate: an invalid graph never
 * runs, and never becomes an enabled task.
 *
 * Callers pass the steps of one task, persisted (StepRepository::
 * findForTask() is the canonical producer — depends_on references ids, so
 * unpersisted steps cannot be validated). Zero steps is valid: a task
 * without steps runs exactly as v1 (SPEC §13.1).
 *
 * Duplicate edges (a step listing the same dependency twice) are
 * harmless and not reported.
 */
final class StepGraphValidator
{
    /**
     * Every violation in the graph, ready for one human-readable report;
     * empty when the graph is valid.
     *
     * @param list<Step> $steps
     *
     * @return list<string>
     */
    public function problems(array $steps): array
    {
        /** @var array<int, Step> $byId */
        $byId = [];
        foreach ($steps as $step) {
            $id = $step->getId();
            if (null !== $id) {
                $byId[$id] = $step;
            }
        }

        $problems = [];
        /** @var array<int, list<int>> $edges valid dependency edges only, by step id */
        $edges = [];

        foreach ($byId as $id => $step) {
            $edges[$id] = [];
            foreach ($step->getDependsOn() as $dep) {
                if ($dep === $id) {
                    $problems[] = \sprintf('Step "%s" depends on itself.', $step->getTitle());
                } elseif (!isset($byId[$dep])) {
                    $problems[] = \sprintf('Step "%s" depends on step %d, which is not a step of this task.', $step->getTitle(), $dep);
                } else {
                    $edges[$id][] = $dep;
                }
            }
        }

        $cycle = $this->findCycle($edges);
        if (null !== $cycle) {
            $path = [];
            foreach ($cycle as $id) {
                $path[] = \sprintf('"%s"', $byId[$id]->getTitle());
            }
            $problems[] = 'Dependency cycle: '.implode(' → ', $path).'.';
        }

        return $problems;
    }

    /**
     * @param list<Step> $steps
     *
     * @throws StepGraphException when the graph has any violation
     */
    public function assertValid(array $steps): void
    {
        $problems = $this->problems($steps);
        if ([] !== $problems) {
            throw new StepGraphException($problems);
        }
    }

    /**
     * One dependency cycle among the edges, as a closed path of step ids
     * (first id repeated at the end), or null when the graph is acyclic.
     *
     * Iterative, no recursion: peel steps whose dependencies are all
     * resolved (Kahn's algorithm); whatever cannot be peeled is on, or
     * downstream of, a cycle. Then walk dependency edges within the
     * unpeeled set — every unpeeled step has at least one unpeeled
     * dependency (otherwise peeling would have reached it), so the walk
     * must revisit a step and the segment between the two visits is a
     * genuine cycle.
     *
     * @param array<int, list<int>> $edges
     *
     * @return list<int>|null
     */
    private function findCycle(array $edges): ?array
    {
        /** @var array<int, int> $pending unresolved dependency count per step */
        $pending = [];
        /** @var array<int, list<int>> $dependents steps that depend on a given step */
        $dependents = [];

        foreach ($edges as $id => $deps) {
            $pending[$id] = \count($deps);
            foreach ($deps as $dep) {
                $dependents[$dep][] = $id;
            }
        }

        /** @var list<int> $resolved */
        $resolved = [];
        foreach ($pending as $id => $count) {
            if (0 === $count) {
                $resolved[] = $id;
            }
        }

        for ($i = 0; $i < \count($resolved); ++$i) {
            foreach ($dependents[$resolved[$i]] ?? [] as $dependent) {
                if (0 === --$pending[$dependent]) {
                    $resolved[] = $dependent;
                }
            }
        }

        if (\count($resolved) === \count($edges)) {
            return null;
        }

        // A cycle exists. Set up the walk: for each unpeeled step, the one
        // unpeeled dependency to advance to.
        $advance = [];
        foreach ($pending as $id => $count) {
            if ($count <= 0) {
                continue;
            }
            foreach ($edges[$id] as $dep) {
                if (($pending[$dep] ?? 0) > 0) {
                    $advance[$id] = $dep;
                    break;
                }
            }
        }

        $walker = array_key_first($advance);
        if (!\is_int($walker)) {
            // Unreachable: peeling fell short, and every unpeeled step has
            // an unpeeled dependency. Guard anyway — a silent wrong answer
            // here would be a validation hole.
            throw new \LogicException('Cycle detection failed to locate a start step.');
        }

        /** @var array<int, int> $seen step id → position in $walk */
        $seen = [];
        /** @var list<int> $walk */
        $walk = [];

        while (!isset($seen[$walker])) {
            $seen[$walker] = \count($walk);
            $walk[] = $walker;
            $walker = $advance[$walker];
        }

        return array_merge(\array_slice($walk, $seen[$walker]), [$walker]);
    }
}

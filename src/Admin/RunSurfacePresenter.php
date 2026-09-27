<?php

declare(strict_types=1);

namespace App\Admin;

use App\Entity\ErrorClass;
use App\Entity\Run;
use App\Entity\RunEvent;
use App\Entity\RunEventType;
use App\Entity\RunRole;
use App\Entity\RunStatus;
use App\Repository\RunEventRepository;
use App\Repository\RunRepository;
use App\Repository\StepRepository;
use App\RunEngine\RunEngine;
use App\StepModel\StepGraphCodec;

/**
 * Builds the run surface's views (SPEC §8): the scheduler view, the run
 * history list, the run detail (timeline + transcript + artifact + step
 * graph), and the attention queue grouped by error class.
 *
 * Read-only; safe on any run state, including a graph mid-flight.
 */
final readonly class RunSurfacePresenter
{
    public function __construct(
        private RunRepository $runs,
        private RunEventRepository $events,
        private StepRepository $steps,
        private StepGraphCodec $codec,
    ) {
    }

    /**
     * The scheduler view + history (SPEC §8): who holds a slot right now (a
     * live execution claim — the claim IS the wire slot, released after
     * every turn), who is queued (FIFO), which step graphs are in flight,
     * and the recent runs.
     *
     * The lane is derived from the checkpoint: a run whose pending tool
     * turn is set is executing (or owed) a tool turn — the tools lane —
     * everything else is on the LLM lane. Same derivation nextTurnMessage()
     * trusts, read-only here.
     */
    public function list(): RunListView
    {
        $now = time();

        $slotHolders = [];
        $waiting = [];
        $graphs = [];
        foreach ($this->runs->findActive() as $run) {
            if (!$run->executesTurns()) {
                $children = $this->runs->findChildren($run);
                $done = 0;
                foreach ($children as $child) {
                    if ($child->isTerminal()) {
                        ++$done;
                    }
                }

                $graphs[] = ['run' => $run, 'done' => $done, 'total' => \count($children)];
                continue;
            }

            $claimedAt = $run->getClaimedAt();
            if (null !== $claimedAt) {
                $age = $now - $claimedAt;
                $slotHolders[] = [
                    'run' => $run,
                    'lane' => self::owedLane($run),
                    'claimAge' => $age,
                    'stale' => $age > RunEngine::CLAIM_STALE_SECONDS,
                ];
                continue;
            }

            $waiting[] = [
                'run' => $run,
                'label' => RunStatus::Queued === $run->getStatus()
                    ? 'queued'
                    : 'waiting for the '.self::owedLane($run).' lane',
            ];
        }

        // Oldest claims last: the most recently held slot heads the list.
        usort($slotHolders, static fn (array $a, array $b): int => $a['claimAge'] <=> $b['claimAge']);

        return new RunListView(
            slotHolders: $slotHolders,
            waiting: $waiting,
            graphs: $graphs,
            recent: $this->runs->findRecent(),
        );
    }

    /**
     * The run detail (SPEC §8, §13.6): timeline (optionally filtered by
     * error class), full transcript, completion artifact, failure reason,
     * the frozen toolbox, claim facts, and — for a parent run — the step
     * children grouped into their graph levels with the final consumer
     * rendered separately.
     */
    public function present(Run $run, ?ErrorClass $filter = null): RunView
    {
        $allEvents = $this->events->findTimeline($run);
        $timelineEvents = null === $filter ? $allEvents : $this->events->findTimeline($run, $filter);

        $timeline = [];
        foreach ($timelineEvents as $event) {
            $timeline[] = TimelineEntry::fromEvent($event);
        }

        [$artifact, $failureReason] = self::outcome($allEvents);

        // Children: only a parent has them.
        $stepGroups = [];
        $orphanChildren = [];
        $finalConsumer = null;
        $childCount = 0;
        if (RunRole::Parent === $run->getRole()) {
            $levels = $this->codec->levels($this->steps->findForTask($run->getTask()));

            $byLevel = [];
            foreach ($this->runs->findChildren($run) as $child) {
                ++$childCount;

                if (RunRole::FinalConsumer === $child->getRole()) {
                    $finalConsumer = $child;
                    continue;
                }

                $stepId = $child->getStep()?->getId();
                if (null !== $stepId && isset($levels[$stepId])) {
                    $byLevel[$levels[$stepId]][] = $child;
                } else {
                    // A step child whose step no longer resolves (deleted
                    // after the run; SET NULL): display stays total.
                    $orphanChildren[] = $child;
                }
            }

            ksort($byLevel);
            foreach ($byLevel as $level => $children) {
                $stepGroups[] = new ChildRunGroup((int) $level, $children);
            }
        }

        $claimedAt = $run->getClaimedAt();
        $claimAge = null !== $claimedAt ? time() - $claimedAt : null;

        $frozenTools = [];
        foreach ($run->getToolboxSnapshot() as $entry) {
            $name = $entry['tool'] ?? null;
            if (\is_string($name)) {
                $frozenTools[] = $name;
            }
        }

        return new RunView(
            run: $run,
            roleLabel: self::roleLabel($run),
            timeline: $timeline,
            transcript: TranscriptEntry::build($run, $allEvents),
            artifact: $artifact,
            failureReason: $failureReason,
            errorClasses: $this->events->distinctErrorClasses($run),
            activeErrorClass: $filter,
            finalConsumer: $finalConsumer,
            stepGroups: $stepGroups,
            orphanChildren: $orphanChildren,
            childCount: $childCount,
            frozenTools: $frozenTools,
            claimAgeSeconds: $claimAge,
            claimStale: null !== $claimAge && $claimAge > RunEngine::CLAIM_STALE_SECONDS,
        );
    }

    /**
     * The attention queue (SPEC §8): needs_attention / incomplete runs,
     * grouped by error class. Runs without a class (a budget-incomplete
     * run has none) gather under an explicit "unclassified" group rather
     * than vanishing.
     *
     * @return list<AttentionGroup>
     */
    public function attention(): array
    {
        /** @var array<string, list<Run>> $byClass */
        $byClass = [];
        foreach ($this->runs->findAttention() as $run) {
            $errorClass = $run->getErrorClass();
            $key = null !== $errorClass ? $errorClass->value : 'unclassified';
            $byClass[$key][] = $run;
        }

        // Classified groups alphabetically; unclassified last.
        uksort($byClass, static function (string $a, string $b): int {
            if ('unclassified' === $a) {
                return 1;
            }
            if ('unclassified' === $b) {
                return -1;
            }

            return $a <=> $b;
        });

        $groups = [];
        foreach ($byClass as $key => $runs) {
            $enum = ErrorClass::tryFrom($key);
            $groups[] = new AttentionGroup(
                label: 'unclassified' === $key ? 'Unclassified (no error class)' : $key,
                errorClass: $enum,
                runs: $runs,
            );
        }

        return $groups;
    }

    /**
     * The run's outcome from its ledger: the completion artifact (the
     * last Completion event's result) and/or the failure reason.
     *
     * @param list<RunEvent> $events
     *
     * @return array{0: string|null, 1: string|null}
     */
    private static function outcome(array $events): array
    {
        $artifact = null;
        $failureReason = null;

        foreach ($events as $event) {
            $payload = $event->getPayload();
            if (RunEventType::Completion === $event->getType()) {
                $result = $payload['result'] ?? null;
                $artifact = \is_string($result) ? $result : null;
            } elseif (RunEventType::Failure === $event->getType()) {
                $reason = $payload['reason'] ?? null;
                $failureReason = \is_string($reason) ? $reason : null;
            }
        }

        return [$artifact, $failureReason];
    }

    private static function roleLabel(Run $run): string
    {
        return match ($run->getRole()) {
            RunRole::Standalone => 'standalone run',
            RunRole::Parent => 'parent run (step graph)',
            RunRole::Step => null !== $run->getStep()
                ? \sprintf('step run — %s', $run->getStep()->getTitle())
                : 'step run',
            RunRole::FinalConsumer => 'final consumer run',
        };
    }

    /**
     * The lane a run's owed turn belongs to: the tools lane while its
     * checkpoint holds a pending tool turn, the llm lane otherwise.
     */
    private static function owedLane(Run $run): string
    {
        $checkpoint = $run->getCheckpoint();

        return null !== ($checkpoint['pendingToolTurn'] ?? null) ? 'tools' : 'llm';
    }
}

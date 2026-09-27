<?php

declare(strict_types=1);

namespace App\Admin;

use App\Entity\ErrorClass;
use App\Entity\Run;

/**
 * One run as the run detail page shows it (SPEC §8): the timeline (possibly
 * error-class filtered), the reconstructed transcript, the completion
 * artifact or failure reason, the step-graph children for a parent run,
 * and the scheduler facts (claim age, staleness) for a live run.
 */
final readonly class RunView
{
    /**
     * @param list<TimelineEntry>   $timeline
     * @param list<TranscriptEntry> $transcript
     * @param list<ErrorClass>      $errorClasses   classes present in this run's ledger
     * @param list<ChildRunGroup>   $stepGroups
     * @param list<Run>             $orphanChildren step children with no resolvable level
     * @param list<string>          $frozenTools
     */
    public function __construct(
        public Run $run,
        public string $roleLabel,
        public array $timeline,
        public array $transcript,
        public ?string $artifact,
        public ?string $failureReason,
        public array $errorClasses,
        public ?ErrorClass $activeErrorClass,
        public ?Run $finalConsumer,
        public array $stepGroups,
        public array $orphanChildren,
        public int $childCount,
        public array $frozenTools,
        public ?int $claimAgeSeconds,
        public bool $claimStale,
    ) {
    }
}

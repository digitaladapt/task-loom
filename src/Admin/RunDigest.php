<?php

declare(strict_types=1);

namespace App\Admin;

use App\Entity\Run;
use App\Entity\RunEvent;
use App\Entity\RunEventType;
use App\Entity\RunRole;
use App\Entity\RunStatus;
use App\Entity\Task;
use App\Repository\RunEventRepository;
use App\Repository\RunRepository;
use App\Repository\StepRepository;
use App\Repository\ToolRepository;
use App\StepModel\StepGraphCodec;

/**
 * The reviewer's lens (SPEC §10): a deterministic, budget-bounded digest of
 * a run's attempt ledger, plus a paged read of the ledger's raw material.
 *
 * Both shapes are derived, never persisted. The ledger (SPEC §5.3) is the
 * only source of truth; a stored digest would be a second ledger free to
 * disagree with the first. Everything here is therefore computed on read
 * and works retroactively on runs recorded before this class existed.
 *
 * No LLM runs inside this class. Aggregating "the model dispatched
 * cal_list four times with identical arguments and byte-identical results"
 * is a GROUP BY, not a judgement — and it is exactly the signal an LLM
 * asked to summarise the same transcript would smooth away. The digest
 * states the provable facts; interpreting them is the reviewer's job.
 */
final readonly class RunDigest
{
    /**
     * The harness's own tool names — every tool this app exposes over MCP.
     * Calls to these inside a run are the harness reviewing itself, which a
     * reviewer instruction almost always wants to exclude.
     *
     * Must track TaskServerFactory's registrations; RunReviewToolsTest
     * asserts the registered set stays inside this list, so a new tool
     * cannot drift out of sync silently.
     */
    public const array HARNESS_TOOL_NAMES = [
        'task_create',
        'task_update',
        'task_list',
        'task_get',
        'run_review',
        'run_read_log',
    ];

    /** Default digest budget, in characters of serialised output. */
    public const int DEFAULT_REVIEW_BUDGET = 4000;

    /** A budget below this yields nothing useful — fail loudly instead. */
    public const int MIN_BUDGET = 500;

    /** Bucket names accepted by readLog(). */
    private const array BUCKETS = [
        'artifact',
        'errors',
        'tool_args',
        'tool_results',
        'thinking',
        'prompt',
    ];

    /**
     * Characters held back from readLog()'s entry budget for the framing
     * sections (run, include, totals, notes), which are always returned.
     *
     * Without this the entries would consume the whole budget and the
     * response would report more returned characters than its stated limit.
     */
    private const int READ_LOG_FRAMING_CHARS = 400;

    /** Digest tool rows kept before the tail is elided. */
    private const int MAX_TOOL_ROWS = 12;

    private const int BRIEF_PREVIEW_CHARS = 200;

    private const int ARTIFACT_PREVIEW_CHARS = 400;

    public function __construct(
        private RunRepository $runs,
        private RunEventRepository $events,
        private StepRepository $steps,
        private ToolRepository $tools,
        private StepGraphCodec $codec,
    ) {
    }

    /**
     * The default run_review shape: what this task's most recent settled run
     * did, and where it wasted itself.
     *
     * @return array<string, mixed>
     */
    public function review(Task $task, ?Run $run = null, int $budgetChars = self::DEFAULT_REVIEW_BUDGET, ?int $history = null): array
    {
        $notes = [];

        if (null === $run) {
            [$run, $notes] = $this->latestSettled($task);
        }

        $sections = [['task', $this->taskSection($task)]];

        if (null === $run) {
            $sections[] = ['notes', $notes];

            return $this->fit($sections, $budgetChars);
        }

        $parts = $this->parts($run);
        $events = $this->eventsFor($parts);

        $notes = [...$notes, ...$this->toolNotes($run, $events)];

        $sections[] = ['run', $this->runSection($run, $parts)];
        $sections[] = ['funnel', $this->funnelSection($run, $parts, $events)];
        $sections[] = ['tools', $this->toolSection($events)];
        $sections[] = ['errors', $this->errorSection($run, $events)];
        $sections[] = ['tokens', $this->tokenSection($events)];
        $sections[] = ['artifact', $this->artifactSection($run, $parts, $events)];

        if (null !== $history && $history > 1) {
            $sections[] = ['history', $this->historySection($task, $run, $history)];
        }

        if ([] !== $notes) {
            $sections[] = ['notes', $notes];
        }

        // The identity sections always survive: a digest that does not say
        // which task and run it describes is unusable at any budget. When
        // they alone exceed it, returned_chars honestly exceeds limit_chars
        // and the dropped sections are listed — visible, never silent.
        return $this->fit($sections, $budgetChars, alwaysKeep: ['task', 'run']);
    }

    /**
     * The raw material, on request (SPEC §10's run_read_log): ledger entries
     * selected by kind, paged by a character budget, with a manifest of
     * exactly what did not fit.
     *
     * The manifest is the point. A digest that silently omits is worse than
     * one that refuses: a reviewer cannot tell "this run had no reasoning"
     * from "I was not shown the reasoning", and will invent a finding to
     * cover the gap.
     *
     * @param list<string> $include bucket names; defaults to ['artifact']
     *
     * @return array<string, mixed>
     */
    public function readLog(
        Run $run,
        array $include = [],
        int $budgetChars = 8000,
        int $entryChars = 4000,
    ): array {
        $include = [] === $include ? ['artifact'] : array_values(array_unique($include));

        if ($budgetChars < self::MIN_BUDGET) {
            throw new \InvalidArgumentException(\sprintf('budget_chars must be at least %d (got %d) — a smaller budget returns nothing useful, which reads as "nothing happened".', self::MIN_BUDGET, $budgetChars));
        }

        foreach ($include as $bucket) {
            if (!\in_array($bucket, self::BUCKETS, true)) {
                throw new \InvalidArgumentException(\sprintf('Unknown include bucket "%s". Allowed: %s.', $bucket, implode(', ', self::BUCKETS)));
            }
        }

        $parts = $this->parts($run);
        $events = $this->eventsFor($parts);

        // Buckets are only present for the kinds actually requested, so
        // this is keyed by the caller's include list rather than the full
        // vocabulary.
        $buckets = array_fill_keys($include, []);
        foreach ($parts as $part) {
            foreach ($this->collect($part, $events[$this->key($part)] ?? [], $include, $entryChars) as $bucket => $entries) {
                $buckets[$bucket] = [...$buckets[$bucket], ...$entries];
            }
        }

        // Budget is split across requested buckets, and a bucket that does
        // not fill its share hands the remainder to the next — so the single
        // -bucket request every reviewer instruction makes gets everything.
        $remaining = max(self::MIN_BUDGET, $budgetChars - self::READ_LOG_FRAMING_CHARS);
        $left = \count($include);
        $elided = [];
        $kept = [];
        foreach ($include as $bucket) {
            $entries = $buckets[$bucket];
            $chars = 0;
            $returned = 0;
            $share = $left > 0 ? max(1, intdiv($remaining, $left)) : $remaining;

            foreach ($entries as $entry) {
                $cost = \strlen((string) $entry['text']);
                if ($chars + $cost > $share) {
                    break;
                }
                $chars += $cost;
                ++$returned;
                $kept[] = $entry;
            }

            $dropped = \count($entries) - $returned;
            if ($dropped > 0) {
                $droppedChars = 0;
                foreach (\array_slice($entries, $returned) as $entry) {
                    $droppedChars += \strlen((string) $entry['text']);
                }
                $elided[$bucket] = [
                    'entries' => \count($entries),
                    'returned' => $returned,
                    'elided_chars' => $droppedChars,
                ];
            }

            $remaining -= $chars;
            --$left;
        }

        // Merge the kept entries back into ledger order so the result reads
        // as the run's story, not as four separate extracts.
        usort($kept, static function (array $a, array $b): int {
            return [$a['run_id'], $a['seq']] <=> [$b['run_id'], $b['seq']];
        });

        $sections = [
            ['run', [
                'id' => $run->getId(),
                'task_id' => $run->getTask()->getId(),
                'role' => $run->getRole()->value,
                'status' => $run->getStatus()->value,
                'parts' => array_map($this->partRef(...), $parts),
            ]],
            ['include', $include],
            ['entries', $kept],
            ['totals', [
                'ledger_events' => \array_sum(array_map(\count(...), $events)),
                'returned_entries' => \count($kept),
            ]],
        ];

        $notes = [];
        // Buckets are only present for requested kinds, so an unrequested
        // key must not be read as "empty" (it would claim the run declared
        // no artifact when the caller simply did not ask).
        if (\in_array('artifact', $include, true) && [] === ($buckets['artifact'] ?? [])) {
            $notes[] = \sprintf(
                'No completion artifact: run %d is %s, so nothing was declared (SPEC §5.4).',
                (int) $run->getId(),
                $run->getStatus()->value,
            );
        }
        if ([] !== $elided) {
            $notes[] = 'Some entries did not fit the budget — see budget.elided. Narrow the include list, or raise budget_chars, rather than concluding from a partial view.';
        }
        if ([] !== $notes) {
            $sections[] = ['notes', $notes];
        }

        $fitted = $this->fit($sections, $budgetChars, alwaysKeep: ['run', 'include', 'entries', 'totals', 'notes']);

        // `fit()` measures the whole response against the budget, but the
        // framing sections are always kept — so the entry list is governed
        // by the budget directly, and the total can exceed it by that
        // framing overhead. Report both rather than one misleading number:
        // the old shape said "returned 1073 of a 500 limit", which reads as
        // a bug rather than as "the entries are bounded, the labels are
        // not".
        $fitted['budget'] = [
            'entry_limit_chars' => max(self::MIN_BUDGET, $budgetChars - self::READ_LOG_FRAMING_CHARS),
            'entries_chars' => array_sum(array_map(
                static fn (array $entry): int => (int) $entry['chars'],
                $kept,
            )),
            'total_chars' => \strlen((string) json_encode($fitted)),
            // The per-bucket manifest, NOT fit()'s section-level one: the
            // caller needs to know which KINDS were cut short, not merely
            // that the envelope grew.
            'elided' => $elided,
        ];

        return $fitted;
    }

    /**
     * The run as the admin UI shows it (SPEC §13.6): a standalone run is
     * itself; a stepped task's run is its parent aggregator PLUS the step
     * children and the final consumer that did the actual work.
     *
     * Without this, reviewing a stepped task would report "0 tool calls, 0
     * tokens" — a parent executes no turns of its own (SPEC §13.3) — for
     * exactly the tasks that most need reviewing.
     *
     * @return list<Run>
     */
    private function parts(Run $run): array
    {
        if (RunRole::Parent !== $run->getRole()) {
            return [$run];
        }

        return [$run, ...$this->runs->findChildren($run)];
    }

    /**
     * Every part's ledger, keyed by run id.
     *
     * @param list<Run> $parts
     *
     * @return array<int, list<RunEvent>>
     */
    private function eventsFor(array $parts): array
    {
        $byRun = [];
        foreach ($parts as $part) {
            $byRun[$this->key($part)] = $this->events->findTimeline($part);
        }

        return $byRun;
    }

    /**
     * Drivers are nullable on a transient entity; a persisted run always has
     * one. The (int) cast keeps the key type honest for phpstan and for
     * array functions that would otherwise compare null to int.
     */
    private function key(Run $run): int
    {
        return (int) $run->getId();
    }

    /**
     * The task's own record, as the thing under review: a reviewer holding
     * only the run can say "that was wasteful"; one holding the brief as
     * well can say "this brief did not tell it the list was sufficient, and
     * that is the fix" (SPEC §10).
     *
     * Briefs travel as previews plus an exact character count, so the digest
     * stays small and the caller can see how much it is not being shown;
     * task_get has the full text.
     *
     * @return array<string, mixed>
     */
    private function taskSection(Task $task): array
    {
        $ordered = $this->steps->findForTask($task);
        $levels = $this->codec->levels($ordered);

        $steps = [];
        foreach ($ordered as $step) {
            $steps[] = [
                'id' => $step->getId(),
                'level' => $levels[$step->getId()] ?? null,
                'position' => $step->getPosition(),
                'title' => $step->getTitle(),
                'brief_chars' => \strlen($step->getBrief()),
                'brief_preview' => $this->preview($step->getBrief(), self::BRIEF_PREVIEW_CHARS),
                'toolbox_mode' => $step->getToolboxMode()->value,
                'toolbox' => $step->getToolbox(),
                'depends_on' => $step->getDependsOn(),
            ];
        }

        return [
            'id' => $task->getId(),
            'title' => $task->getTitle(),
            'enabled' => $task->isEnabled(),
            'kind' => $task->getKind()->value,
            'created_by' => $task->getCreatedBy()->value,
            'schedule' => $task->getSchedule(),
            'brief_chars' => \strlen($task->getBrief()),
            'brief_preview' => $this->preview($task->getBrief(), self::BRIEF_PREVIEW_CHARS),
            'toolbox' => [
                'mode' => $task->getToolboxMode()->value,
                'declared' => $task->getToolbox(),
            ],
            'steps' => $steps,
        ];
    }

    /**
     * The run's identity and shape, plus one line per part so a stepped
     * task's graph is legible without opening each child.
     *
     * @param list<Run> $parts
     *
     * @return array<string, mixed>
     */
    private function runSection(Run $run, array $parts): array
    {
        $started = $run->getStartedAt();
        $finished = $run->getFinishedAt();
        $duration = null;
        if (null !== $started && null !== $finished) {
            $duration = ($finished->getTimestamp() - $started->getTimestamp()) * 1000;
        }

        return [
            'id' => $run->getId(),
            'task_id' => $run->getTask()->getId(),
            'role' => $run->getRole()->value,
            'status' => $run->getStatus()->value,
            'triggered_by' => $run->getTriggeredBy()->value,
            'started_at' => $started?->format(\DateTimeInterface::ATOM),
            'finished_at' => $finished?->format(\DateTimeInterface::ATOM),
            'duration_ms' => $duration,
            'error_class' => $run->getErrorClass()?->value,
            'step_count' => $run->getStepCount(),
            'parts' => array_map($this->partRef(...), $parts),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function partRef(Run $run): array
    {
        return [
            'id' => $run->getId(),
            'role' => $run->getRole()->value,
            'step_title' => $run->getStep()?->getTitle(),
            'status' => $run->getStatus()->value,
            'error_class' => $run->getErrorClass()?->value,
        ];
    }

    /**
     * The shape of the work (SPEC §10): how many exchanges it took, how many
     * tool calls it took, and how many of those were the same call again.
     * The ratio between those numbers is the whole signal.
     *
     * @param list<Run>                  $parts
     * @param array<int, list<RunEvent>> $events
     *
     * @return array<string, mixed>
     */
    private function funnelSection(Run $run, array $parts, array $events): array
    {
        $requests = 0;
        $responses = 0;
        $calls = 0;
        $retries = 0;
        $repeats = 0;
        $errorEvents = 0;
        $tools = [];
        $argSets = [];

        foreach ($events as $timeline) {
            foreach ($timeline as $event) {
                $payload = $event->getPayload();

                switch ($event->getType()) {
                    case RunEventType::LlmRequest:
                        ++$requests;
                        break;
                    case RunEventType::LlmResponse:
                        ++$responses;
                        break;
                    case RunEventType::ToolCall:
                        ++$calls;
                        $name = $this->string($payload['tool'] ?? null, '?');
                        $tools[$name] = true;
                        $attempt = \is_int($payload['attempt'] ?? null) ? $payload['attempt'] : 1;
                        if ($attempt > 1) {
                            ++$retries;
                            break;
                        }
                        $signature = $name.'|'.$this->canonicalJson($payload['arguments'] ?? []);
                        if (isset($argSets[$signature])) {
                            ++$repeats;
                        }
                        $argSets[$signature] = true;
                        break;
                    default:
                        if (null !== $event->getErrorClass()) {
                            ++$errorEvents;
                        }
                }
            }
        }

        return [
            'parts' => \count($parts),
            'llm_requests' => $requests,
            'llm_responses' => $responses,
            'tool_calls' => $calls,
            'retries' => $retries,
            'distinct_tools' => \count($tools),
            'distinct_calls' => \count($argSets),
            // Dispatches whose (tool, arguments) pair had already been seen:
            // the model asked for the same thing twice.
            'repeated_calls' => $repeats,
            'errors' => $errorEvents,
        ];
    }

    /**
     * Per tool: how often it was called, how often with arguments already
     * seen, and how often the answer came back byte-identical.
     *
     * `identical_results` is the strong claim and the one worth acting on —
     * the same question, the same answer. Repeats whose results DIFFER are
     * polling, not waste, and are deliberately not counted as redundant.
     *
     * @param array<int, list<RunEvent>> $events
     *
     * @return list<array<string, mixed>>
     */
    private function toolSection(array $events): array
    {
        /** @var array<string, array{calls: int, retries: int, repeats: int, identical: int, identical_chars: int, errors: int, signatures: array<string, true>, first: array<string, string>}> $byTool */
        $byTool = [];

        foreach ($events as $timeline) {
            // Pair each dispatch with the result that followed it, so a
            // repeat can be checked against the answer it produced. The
            // engine appends call → result in seq order (SPEC §5.3).
            $pending = null;

            foreach ($timeline as $event) {
                $payload = $event->getPayload();
                $type = $event->getType();

                if (RunEventType::ToolCall === $type) {
                    $name = $this->string($payload['tool'] ?? null, '?');
                    $byTool[$name] ??= self::emptyToolRow();
                    ++$byTool[$name]['calls'];

                    $attempt = \is_int($payload['attempt'] ?? null) ? $payload['attempt'] : 1;
                    if ($attempt > 1) {
                        ++$byTool[$name]['retries'];
                        $pending = null;
                        continue;
                    }

                    $signature = $this->canonicalJson($payload['arguments'] ?? []);
                    $pending = ['tool' => $name, 'signature' => $signature];
                    continue;
                }

                if (RunEventType::ToolResult === $type) {
                    $name = $this->string($payload['tool'] ?? null, '?');
                    $byTool[$name] ??= self::emptyToolRow();
                    if (null !== $event->getErrorClass()) {
                        ++$byTool[$name]['errors'];
                    }

                    if (null === $pending || $pending['tool'] !== $name) {
                        $pending = null;
                        continue;
                    }

                    $signature = $pending['signature'];
                    $result = $this->string($payload['content'] ?? $payload['detail'] ?? null, '');

                    if (!isset($byTool[$name]['signatures'][$signature])) {
                        $byTool[$name]['signatures'][$signature] = true;
                        // First sighting of this (tool, arguments) pair: the
                        // baseline a later identical answer is compared to.
                        $byTool[$name]['first'][$signature] ??= $result;
                        $pending = null;
                        continue;
                    }

                    ++$byTool[$name]['repeats'];
                    $byTool[$name]['first'][$signature] ??= $result;
                    if ($byTool[$name]['first'][$signature] === $result) {
                        ++$byTool[$name]['identical'];
                        $byTool[$name]['identical_chars'] += \strlen($result);
                    }
                    $pending = null;
                    continue;
                }

                if (RunEventType::ToolValidationError === $type) {
                    $name = $this->string($payload['tool'] ?? null, '?');
                    $byTool[$name] ??= self::emptyToolRow();
                    ++$byTool[$name]['errors'];
                    $pending = null;
                }
            }
        }

        $rows = [];
        foreach ($byTool as $name => $data) {
            $row = [
                'tool' => $name,
                'calls' => $data['calls'],
                'retries' => $data['retries'],
                'repeated_call' => $data['repeats'],
                'identical_results' => $data['identical'],
                'repeated_result_chars' => $data['identical_chars'],
                'errors' => $data['errors'],
            ];

            // Annotation comes from the CURRENT catalog, not the run's frozen
            // snapshot (which carries no side_effect — SPEC §4.1). It changes
            // the advice: four redundant reads is waste, two redundant writes
            // is a correctness hazard. Provenance is noted rather than hidden.
            $catalog = $this->tools->findOneBy(['name' => $name]);
            $row['side_effect'] = $catalog?->hasSideEffect();

            $rows[] = $row;
        }

        usort($rows, static fn (array $a, array $b): int => [$b['calls'], (string) $a['tool']] <=> [$a['calls'], (string) $b['tool']]);

        return \array_slice($rows, 0, self::MAX_TOOL_ROWS);
    }

    /**
     * The failure taxonomy rollup (SPEC §5.3), attributed to the tools that
     * caused it — "invalid_arguments ×1 on event_get" is actionable;
     * "1 error" is not.
     *
     * @param array<int, list<RunEvent>> $events
     *
     * @return array<string, mixed>
     */
    private function errorSection(Run $run, array $events): array
    {
        /** @var array<string, array{count: int, tools: array<string, true>}> $byClass */
        $byClass = [];

        foreach ($events as $timeline) {
            foreach ($timeline as $event) {
                $class = $event->getErrorClass();
                if (null === $class) {
                    continue;
                }

                $byClass[$class->value] ??= ['count' => 0, 'tools' => []];
                ++$byClass[$class->value]['count'];

                $tool = $event->getPayload()['tool'] ?? null;
                if (\is_string($tool) && '' !== $tool) {
                    $byClass[$class->value]['tools'][$tool] = true;
                }
            }
        }

        $rollup = [];
        foreach ($byClass as $class => $data) {
            $rollup[$class] = [
                'count' => $data['count'],
                'tools' => array_keys($data['tools']),
            ];
        }

        return [
            'terminal' => $run->getErrorClass()?->value,
            'by_class' => $rollup,
        ];
    }

    /**
     * Where the budget went. Cheap to compute from the ledger and, for a
     * slow or expensive run, often the entire finding.
     *
     * @param array<int, list<RunEvent>> $events
     *
     * @return array<string, mixed>
     */
    private function tokenSection(array $events): array
    {
        $prompt = 0;
        $completion = 0;
        $cached = 0;

        foreach ($events as $timeline) {
            foreach ($timeline as $event) {
                if (RunEventType::LlmResponse !== $event->getType()) {
                    continue;
                }

                $usage = $event->getPayload()['usage'] ?? null;
                if (!\is_array($usage)) {
                    continue;
                }

                $prompt += $this->int($usage['prompt_tokens'] ?? null);
                $completion += $this->int($usage['completion_tokens'] ?? null);

                $details = $usage['prompt_tokens_details'] ?? null;
                if (\is_array($details)) {
                    $cached += $this->int($details['cached_tokens'] ?? null);
                }
            }
        }

        return [
            'prompt_tokens' => $prompt,
            'completion_tokens' => $completion,
            'cached_tokens' => $cached,
            'total_tokens' => $prompt + $completion,
        ];
    }

    /**
     * The completion artifact — for a parent, the final consumer's declared
     * result (SPEC §13.4); otherwise the run's own. Whether it exists at all
     * is the difference between "this run finished" and "this run stopped".
     *
     * @param list<Run>                  $parts
     * @param array<int, list<RunEvent>> $events
     *
     * @return array<string, mixed>
     */
    private function artifactSection(Run $run, array $parts, array $events): array
    {
        $source = $run;
        if (RunRole::Parent === $run->getRole()) {
            foreach ($parts as $part) {
                if (RunRole::FinalConsumer === $part->getRole()) {
                    $source = $part;
                    break;
                }
            }
        }

        $text = null;
        foreach ($events[$this->key($source)] ?? [] as $event) {
            if (RunEventType::Completion !== $event->getType()) {
                continue;
            }
            $result = $event->getPayload()['result'] ?? null;
            if (\is_string($result) && '' !== trim($result)) {
                $text = $result;
            }
        }

        if (null === $text) {
            return ['present' => false];
        }

        return [
            'present' => true,
            'from_run_id' => $source->getId(),
            'chars' => \strlen($text),
            'preview' => $this->preview($text, self::ARTIFACT_PREVIEW_CHARS),
        ];
    }

    /**
     * Is this getting worse? (SPEC §10's "adjust a toolbox that repeatedly
     * validated wrong arguments".) The subject run plus the settled runs
     * before it, reduced to the few numbers a trend needs.
     *
     * @return list<array<string, mixed>>
     */
    private function historySection(Task $task, Run $run, int $limit): array
    {
        $rows = [];
        $seen = false;

        foreach ($this->runs->findForTask($task) as $candidate) {
            $isSubject = $candidate->getId() === $run->getId();
            if ($isSubject) {
                $seen = true;
            } elseif (!$seen || !$candidate->isTerminal() || RunStatus::Incomplete === $candidate->getStatus()) {
                // Before the subject: skip until we reach it. After: settled
                // runs only, and (like the default) an incomplete run is not
                // a data point about whether the task is improving.
                continue;
            }

            $parts = $this->parts($candidate);
            $events = $this->eventsFor($parts);
            $funnel = $this->funnelSection($candidate, $parts, $events);
            $tokens = $this->tokenSection($events);

            $started = $candidate->getStartedAt();
            $finished = $candidate->getFinishedAt();

            $rows[] = [
                'run_id' => $candidate->getId(),
                'status' => $candidate->getStatus()->value,
                'finished_at' => $finished?->format(\DateTimeInterface::ATOM),
                'duration_ms' => null !== $started && null !== $finished
                    ? ($finished->getTimestamp() - $started->getTimestamp()) * 1000
                    : null,
                'tool_calls' => $funnel['tool_calls'],
                'repeated_calls' => $funnel['repeated_calls'],
                'errors' => $funnel['errors'],
                'total_tokens' => $tokens['total_tokens'],
            ];

            if (\count($rows) >= $limit) {
                break;
            }
        }

        return $rows;
    }

    /**
     * One part's ledger entries, selected by bucket.
     *
     * @param list<RunEvent> $timeline
     * @param list<string>   $include
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function collect(Run $part, array $timeline, array $include, int $entryChars): array
    {
        $buckets = array_fill_keys($include, []);
        $checkpoint = $part->getCheckpoint();

        if (\in_array('prompt', $include, true) && \is_array($checkpoint['promptHead'] ?? null)) {
            $head = $checkpoint['promptHead'];
            foreach (['system', 'user'] as $role) {
                $text = $head[$role] ?? null;
                if (\is_string($text) && '' !== trim($text)) {
                    $buckets['prompt'][] = $this->entry($part, 0, 'prompt_head', $text, $entryChars, ['role' => $role]);
                }
            }
        }

        foreach ($timeline as $event) {
            $payload = $event->getPayload();
            $seq = $event->getSeq();

            switch ($event->getType()) {
                case RunEventType::Completion:
                    if (\in_array('artifact', $include, true)) {
                        $result = $payload['result'] ?? null;
                        if (\is_string($result) && '' !== trim($result)) {
                            $buckets['artifact'][] = $this->entry($part, $seq, 'artifact', $result, $entryChars);
                        }
                    }
                    break;

                case RunEventType::LlmResponse:
                    if (\in_array('thinking', $include, true)) {
                        $thought = $payload['reasoningContent'] ?? null;
                        if (\is_string($thought) && '' !== trim($thought)) {
                            $buckets['thinking'][] = $this->entry($part, $seq, 'thinking', $thought, $entryChars);
                        }
                    }
                    break;

                case RunEventType::ToolCall:
                    if (\in_array('tool_args', $include, true)) {
                        $buckets['tool_args'][] = $this->entry(
                            $part,
                            $seq,
                            'tool_args',
                            $this->canonicalJson($payload['arguments'] ?? []),
                            $entryChars,
                            ['tool' => $this->string($payload['tool'] ?? null, '?')],
                        );
                    }
                    break;

                case RunEventType::ToolResult:
                    if (\in_array('tool_results', $include, true)) {
                        $buckets['tool_results'][] = $this->entry(
                            $part,
                            $seq,
                            'tool_result',
                            $this->string($payload['content'] ?? $payload['detail'] ?? null, ''),
                            $entryChars,
                            [
                                'tool' => $this->string($payload['tool'] ?? null, '?'),
                                'is_error' => null !== $event->getErrorClass(),
                            ],
                        );
                    }
                    break;

                case RunEventType::ToolValidationError:
                case RunEventType::Failure:
                case RunEventType::CircuitBreaker:
                    if (\in_array('errors', $include, true)) {
                        $buckets['errors'][] = $this->entry(
                            $part,
                            $seq,
                            'error',
                            $this->string($payload['reason'] ?? $payload['detail'] ?? null, ''),
                            $entryChars,
                            [
                                'error_class' => $event->getErrorClass()?->value,
                                'tool' => \is_string($payload['tool'] ?? null) ? $payload['tool'] : null,
                            ],
                        );
                    }
                    break;

                default:
                    break;
            }
        }

        return $buckets;
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private function entry(Run $part, int $seq, string $type, string $text, int $entryChars, array $extra = []): array
    {
        $truncated = \strlen($text) > $entryChars;
        if ($truncated) {
            $text = substr($text, 0, $entryChars).'…[truncated]';
        }

        return [
            'run_id' => $part->getId(),
            'seq' => $seq,
            'type' => $type,
            ...$extra,
            'text' => $text,
            'chars' => \strlen($text),
            'truncated' => $truncated,
        ];
    }

    /**
     * The newest run worth reviewing, or null when there is none.
     *
     * "Settled" deliberately excludes Incomplete. isTerminal() counts an
     * incomplete run as terminal — correctly, the engine is done with it —
     * but a run that hit a budget without ever declaring a completion
     * (SPEC §5.4) is an abandoned artifact, not evidence about how the task
     * normally behaves. Defaulting to one would have the reviewer form
     * opinions about a task from the run that crashed; a caller that wants
     * exactly that run passes run_id explicitly.
     *
     * Failing and needs_attention runs ARE settled in this sense: they are
     * real terminal outcomes the task produced, and a reviewer looking at a
     * task that cannot succeed needs to see that.
     *
     * @return array{0: ?Run, 1: list<string>}
     */
    private function latestSettled(Task $task): array
    {
        $all = $this->runs->findForTask($task);
        if ([] === $all) {
            return [null, ['This task has no runs yet.']];
        }

        $skipped = [];
        foreach ($all as $candidate) {
            if ($candidate->isTerminal() && RunStatus::Incomplete !== $candidate->getStatus()) {
                if ([] !== $skipped) {
                    $notes = [\sprintf(
                        'Defaulted to the newest settled run (#%s). Skipped: %s — %s, so not evidence about this task\'s normal behaviour. Pass run_id to review one anyway.',
                        (string) $candidate->getId(),
                        implode(', ', array_map(static fn (Run $r): string => '#'.(string) $r->getId(), $skipped)),
                        implode(', ', array_unique(array_map(static fn (Run $r): string => $r->getStatus()->value, $skipped))),
                    )];

                    return [$candidate, $notes];
                }

                return [$candidate, []];
            }

            $skipped[] = $candidate;
        }

        // Nothing settled: an incomplete run is all there is, so review it
        // and say so rather than refusing outright.
        $newest = $all[0];
        if (RunStatus::Incomplete === $newest->getStatus()) {
            return [$newest, [\sprintf(
                'Run #%s is incomplete — it stopped without declaring a completion (SPEC §5.4), so it is a record of a run that did not finish rather than of how this task normally behaves.',
                (string) $newest->getId(),
            )]];
        }

        return [null, [\sprintf(
            'This task has %d run(s) but none settled (newest: %s) — there is nothing to review yet.',
            \count($all),
            $newest->getStatus()->value,
        )]];
    }

    /**
     * @param array<int, list<RunEvent>> $events
     *
     * @return list<string>
     */
    private function toolNotes(Run $run, array $events): array
    {
        $notes = [];
        $harnessCalls = 0;

        foreach ($events as $timeline) {
            foreach ($timeline as $event) {
                if (RunEventType::ToolCall !== $event->getType()) {
                    continue;
                }
                $name = $event->getPayload()['tool'] ?? null;
                if (\is_string($name) && \in_array($name, self::HARNESS_TOOL_NAMES, true)) {
                    ++$harnessCalls;
                }
            }
        }

        if ($harnessCalls > 0) {
            $notes[] = \sprintf(
                '%d call(s) in this run were to the harness\'s own tools (%s) — self-review. Exclude them when reviewing "everything", or the reviewer spends its budget on itself.',
                $harnessCalls,
                implode(', ', self::HARNESS_TOOL_NAMES),
            );
        }

        if ([] !== $run->getToolboxSnapshot()) {
            $notes[] = 'side_effect values come from the current tool catalog, not the run\'s frozen toolbox snapshot (SPEC §4.1): the snapshot records what the model could see, not whether it writes.';
        }

        return $notes;
    }

    /**
     * Serialise the sections in priority order until the budget is spent,
     * recording what was dropped. Sections listed in $alwaysKeep survive
     * regardless — the caller's framing must not be silently removed even
     * at the smallest budgets.
     *
     * @param list<array{0: string, 1: mixed}> $sections
     * @param list<string>                     $alwaysKeep
     *
     * @return array<string, mixed>
     */
    private function fit(array $sections, int $budgetChars, array $alwaysKeep = []): array
    {
        if ($budgetChars < self::MIN_BUDGET) {
            throw new \InvalidArgumentException(\sprintf('budget_chars must be at least %d (got %d) — a smaller budget returns nothing useful, which reads as "nothing happened".', self::MIN_BUDGET, $budgetChars));
        }

        $data = [];
        $elided = [];
        $used = 0;

        foreach ($sections as [$key, $value]) {
            $cost = \strlen((string) json_encode($value));

            if (!\in_array($key, $alwaysKeep, true) && $used + $cost > $budgetChars) {
                $elided[$key] = ['chars' => $cost];

                continue;
            }

            $data[$key] = $value;
            $used += $cost;
        }

        $data['budget'] = [
            'limit_chars' => $budgetChars,
            'returned_chars' => $used,
            'elided' => $elided,
        ];

        return $data;
    }

    /**
     * A stable string for any argument tree: keys sorted at every depth, so
     * two calls that differ only in key order compare equal.
     */
    private function canonicalJson(mixed $value): string
    {
        if (\is_array($value)) {
            if (!array_is_list($value)) {
                ksort($value);
            }
            $value = array_map($this->canonicalJson(...), $value);
        }

        return (string) json_encode($value, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
    }

    /**
     * The mutable accumulation shape for toolSection(), factored out so the
     * three per-event branches cannot drift apart.
     *
     * @return array{calls: int, retries: int, repeats: int, identical: int, identical_chars: int, errors: int, signatures: array<string, true>, first: array<string, string>}
     */
    private static function emptyToolRow(): array
    {
        return [
            'calls' => 0,
            'retries' => 0,
            'repeats' => 0,
            'identical' => 0,
            'identical_chars' => 0,
            'errors' => 0,
            'signatures' => [],
            'first' => [],
        ];
    }

    private function preview(string $text, int $chars): string
    {
        $flat = trim((string) preg_replace('/\s+/', ' ', $text));

        return \strlen($flat) <= $chars ? $flat : substr($flat, 0, $chars).'…';
    }

    private function string(mixed $value, string $fallback): string
    {
        return \is_scalar($value) ? (string) $value : $fallback;
    }

    private function int(mixed $value): int
    {
        return \is_int($value) ? $value : 0;
    }
}

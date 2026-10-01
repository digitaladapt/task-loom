<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Admin\RunDigest;
use App\Entity\ErrorClass;
use App\Entity\Run;
use App\Entity\RunEvent;
use App\Entity\RunEventType;
use App\Entity\RunRole;
use App\Entity\RunTrigger;
use App\Entity\Task;
use App\Entity\TaskAuthor;
use App\Entity\TaskKind;
use App\Entity\ToolboxMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The digest's arithmetic (SPEC §10) against the real schema.
 *
 * Repetition detection is the whole reason run_review exists, so it is
 * tested directly rather than only through a live run. The ledger is built
 * row by row and then read back through the same queries production uses,
 * which also keeps the DQL honest.
 */
final class RunDigestTest extends KernelTestCase
{
    private RunDigest $digest; // @phpstan-ignore property.uninitialized (assigned in setUp)
    private EntityManagerInterface $em; // @phpstan-ignore property.uninitialized (assigned in setUp)

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();

        $this->em = static::getContainer()->get('doctrine')->getManager();
        $this->em->createQuery('DELETE FROM App\Entity\ToolCall')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\RunEvent')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\Run')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\Step')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\Task')->execute();
        $this->em->flush();

        $this->digest = static::getContainer()->get(RunDigest::class);
    }

    public function testIdenticalRepeatedCallsAreCountedWithTheirWastedChars(): void
    {
        $run = $this->persistedRun();
        $this->append($run, RunEventType::LlmRequest, ['step' => 1]);

        // Four identical calendar_list calls, each with the same result.
        for ($i = 0; $i < 4; ++$i) {
            $this->append($run, RunEventType::ToolCall, ['tool' => 'calendar_list', 'arguments' => ['day' => 'today'], 'attempt' => 1]);
            $this->append($run, RunEventType::ToolResult, ['tool' => 'calendar_list', 'content' => '[]', 'isError' => false]);
        }

        $digest = $this->digest->review($run->getTask(), $run);

        self::assertSame(4, $digest['funnel']['tool_calls']);
        self::assertSame(1, $digest['funnel']['distinct_calls']);
        // Three of the four dispatches had been seen before.
        self::assertSame(3, $digest['funnel']['repeated_calls']);

        $row = $this->toolRow($digest, 'calendar_list');
        self::assertSame(4, $row['calls']);
        self::assertSame(3, $row['repeated_call']);
        self::assertSame(3, $row['identical_results'], 'identical results are pure waste');
        self::assertSame(6, $row['repeated_result_chars'], 'the 2-char result, three times');
    }

    /**
     * Repeats the engine dropped never became tool_call rows, so without
     * this count the model's self-repetition would vanish from the digest
     * the moment the dedup shipped. It must stay visible.
     */
    public function testDroppedDuplicatesAreSurfacedFromTheResponsePayload(): void
    {
        $run = $this->persistedRun();
        $this->append($run, RunEventType::LlmRequest, ['step' => 1]);
        $this->append($run, RunEventType::LlmResponse, [
            'step' => 1,
            'content' => '',
            'usage' => [],
            'toolCalls' => [
                ['id' => 'c1', 'name' => 'calendar_list', 'arguments' => ['day' => 'today']],
                ['id' => 'c2', 'name' => 'calendar_list', 'arguments' => ['day' => 'today']],
                ['id' => 'c3', 'name' => 'calendar_list', 'arguments' => ['day' => 'today']],
            ],
            'droppedDuplicates' => [
                ['id' => 'c2', 'name' => 'calendar_list', 'arguments' => ['day' => 'today']],
                ['id' => 'c3', 'name' => 'calendar_list', 'arguments' => ['day' => 'today']],
            ],
        ]);

        // Only the first survived to dispatch.
        $this->append($run, RunEventType::ToolCall, ['tool' => 'calendar_list', 'arguments' => ['day' => 'today'], 'attempt' => 1, 'toolCallId' => 'c1']);
        $this->append($run, RunEventType::ToolResult, ['tool' => 'calendar_list', 'content' => '[]', 'isError' => false, 'toolCallId' => 'c1']);

        $digest = $this->digest->review($run->getTask(), $run);

        self::assertSame(1, $digest['funnel']['tool_calls'], 'only the first call was dispatched');
        self::assertSame(0, $digest['funnel']['repeated_calls'], 'no dispatch repeated another');
        self::assertSame(2, $digest['funnel']['dropped_duplicates'], 'the two the model repeated are still counted');
    }

    /**
     * Two ledger rows sharing one tool_call id would be the harness
     * double-firing; two rows with different ids is the model repeating
     * itself. run_read_log must expose the id so the two are tellable apart.
     */
    public function testToolArgsEntriesCarryTheToolCallId(): void
    {
        $run = $this->persistedRun();
        $this->append($run, RunEventType::ToolCall, ['tool' => 'fetch', 'arguments' => ['a' => 1], 'attempt' => 1, 'toolCallId' => 'call_xyz']);

        $log = $this->digest->readLog($run, ['tool_args']);
        $entry = $log['entries'][0] ?? null;

        self::assertIsArray($entry);
        self::assertSame('call_xyz', $entry['tool_call_id']);
    }

    /**
     * The distinction the digest exists to make: a repeat whose answer
     * CHANGED is polling, not waste. Counting it as redundancy would send a
     * reviewer to "fix" a task that is behaving correctly.
     */
    public function testRepeatedCallsWithChangingResultsAreNotCountedAsRedundant(): void
    {
        $run = $this->persistedRun();
        $this->append($run, RunEventType::LlmRequest, ['step' => 1]);

        $this->append($run, RunEventType::ToolCall, ['tool' => 'status', 'arguments' => [], 'attempt' => 1]);
        $this->append($run, RunEventType::ToolResult, ['tool' => 'status', 'content' => 'running', 'isError' => false]);
        $this->append($run, RunEventType::ToolCall, ['tool' => 'status', 'arguments' => [], 'attempt' => 1]);
        $this->append($run, RunEventType::ToolResult, ['tool' => 'status', 'content' => 'succeeded', 'isError' => false]);

        $digest = $this->digest->review($run->getTask(), $run);
        $row = $this->toolRow($digest, 'status');

        self::assertSame(1, $row['repeated_call'], 'the same question was asked twice');
        self::assertSame(0, $row['identical_results'], 'but the answer differed — polling, not waste');
        self::assertSame(0, $row['repeated_result_chars']);
    }

    public function testArgumentKeyOrderDoesNotDefeatTheComparison(): void
    {
        $run = $this->persistedRun();
        $this->append($run, RunEventType::LlmRequest, ['step' => 1]);

        $this->append($run, RunEventType::ToolCall, ['tool' => 'fetch', 'arguments' => ['a' => 1, 'b' => 2], 'attempt' => 1]);
        $this->append($run, RunEventType::ToolResult, ['tool' => 'fetch', 'content' => 'same', 'isError' => false]);
        $this->append($run, RunEventType::ToolCall, ['tool' => 'fetch', 'arguments' => ['b' => 2, 'a' => 1], 'attempt' => 1]);
        $this->append($run, RunEventType::ToolResult, ['tool' => 'fetch', 'content' => 'same', 'isError' => false]);

        $digest = $this->digest->review($run->getTask(), $run);

        self::assertSame(1, $digest['funnel']['distinct_calls'], 'key order is not semantic difference');
        self::assertSame(1, $digest['funnel']['repeated_calls']);
    }

    public function testRetriesAndErrorsAreAttributedToTheirTools(): void
    {
        $run = $this->persistedRun();
        $this->append($run, RunEventType::LlmRequest, ['step' => 1]);
        $this->append(
            $run,
            RunEventType::ToolValidationError,
            ['tool' => 'event_get', 'detail' => 'missing id', 'attempt' => 1],
            ErrorClass::InvalidArguments,
        );
        $this->append($run, RunEventType::ToolCall, ['tool' => 'event_get', 'arguments' => ['id' => 'a'], 'attempt' => 2]);
        $this->append($run, RunEventType::ToolResult, ['tool' => 'event_get', 'content' => '{}', 'isError' => false], null, 2);

        $digest = $this->digest->review($run->getTask(), $run);

        self::assertSame(1, $digest['funnel']['retries'], 'attempt 2 is a retry');
        self::assertSame(1, $digest['funnel']['errors']);
        self::assertSame(['invalid_arguments' => ['count' => 1, 'tools' => ['event_get']]], $digest['errors']['by_class']);
    }

    public function testTokensAndArtifactAreAggregatedAcrossTheLedger(): void
    {
        $run = $this->persistedRun();
        $this->append($run, RunEventType::LlmResponse, [
            'step' => 1,
            'content' => '',
            'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 20, 'prompt_tokens_details' => ['cached_tokens' => 80]],
        ]);
        $this->append($run, RunEventType::LlmResponse, [
            'step' => 2,
            'content' => 'done',
            'usage' => ['prompt_tokens' => 200, 'completion_tokens' => 30],
        ]);
        $this->append($run, RunEventType::Completion, ['result' => 'the briefing']);

        $digest = $this->digest->review($run->getTask(), $run);

        self::assertSame(300, $digest['tokens']['prompt_tokens']);
        self::assertSame(50, $digest['tokens']['completion_tokens']);
        self::assertSame(80, $digest['tokens']['cached_tokens']);
        self::assertTrue($digest['artifact']['present']);
        self::assertSame('the briefing', $digest['artifact']['preview']);
    }

    public function testAnIncompleteRunReportsNoArtifactRatherThanAnEmptyOne(): void
    {
        $run = $this->persistedRun();
        $run->markIncomplete();
        $this->em->flush();
        $this->append($run, RunEventType::LlmResponse, ['step' => 1, 'content' => '', 'usage' => []]);

        $digest = $this->digest->review($run->getTask(), $run);

        self::assertFalse($digest['artifact']['present'], 'a run with no declaration has no artifact (SPEC §5.4)');
    }

    public function testSectionsThatDoNotFitAreReportedAtTheSmallestBudget(): void
    {
        $run = $this->persistedRun();
        for ($i = 0; $i < 30; ++$i) {
            $this->append($run, RunEventType::ToolCall, ['tool' => 'tool_'.$i, 'arguments' => ['i' => $i], 'attempt' => 1]);
            $this->append($run, RunEventType::ToolResult, ['tool' => 'tool_'.$i, 'content' => str_repeat('x', 200), 'isError' => false]);
        }

        $digest = $this->digest->review($run->getTask(), $run, RunDigest::MIN_BUDGET);

        // The identity sections survive, so the returned total may sit just
        // above the limit — the point is that the overflow is visible in
        // `elided` rather than silently presented as the whole story.
        self::assertArrayHasKey('run', $digest);
        self::assertArrayHasKey('task', $digest);
        self::assertNotSame([], $digest['budget']['elided'], 'a truncated digest must say what it dropped');
        foreach (['funnel', 'tools', 'tokens', 'artifact'] as $dropped) {
            self::assertArrayNotHasKey($dropped, $digest, $dropped.' must not survive a budget that cannot hold it');
            self::assertArrayHasKey($dropped, $digest['budget']['elided'], $dropped.' must be recorded as dropped');
        }
        self::assertLessThanOrEqual(
            RunDigest::MIN_BUDGET + 200,
            $digest['budget']['returned_chars'],
            'the overshoot must be bounded by the framing sections, not unbounded',
        );
    }

    public function testABudgetBelowTheFloorIsRefusedRatherThanReturningNothing(): void
    {
        $run = $this->persistedRun();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/at least 500/');

        $this->digest->review($run->getTask(), $run, 10);
    }

    /**
     * A stepped task's parent executes no turns of its own (SPEC §13.3), so
     * the digest must roll the graph up — otherwise the tasks most in need
     * of review would report "0 tool calls, 0 tokens".
     */
    public function testAParentRunDigestsItsStepChildrenAndSeesTheirWork(): void
    {
        $task = $this->persistedTask('Stepped task');
        $parent = new Run($task);
        $parent->setRole(RunRole::Parent);
        $parent->setTriggeredBy(RunTrigger::Scheduled);
        $parent->markStarted();
        $parent->markSucceeded();
        $this->em->persist($parent);
        $this->em->flush();

        // The parent's own ledger holds no work at all.
        $this->append($parent, RunEventType::Completion, ['result' => 'aggregated']);

        $child = new Run($task);
        $child->setRole(RunRole::Step);
        $child->setParent($parent);
        $child->setTriggeredBy(RunTrigger::Scheduled);
        $child->markStarted();
        $child->markSucceeded();
        $this->em->persist($child);
        $this->em->flush();

        for ($i = 0; $i < 3; ++$i) {
            $this->append($child, RunEventType::ToolCall, ['tool' => 'weather', 'arguments' => ['city' => 'berlin'], 'attempt' => 1]);
            $this->append($child, RunEventType::ToolResult, ['tool' => 'weather', 'content' => 'sunny', 'isError' => false]);
        }
        $this->append($child, RunEventType::LlmResponse, ['step' => 1, 'content' => 'ok', 'usage' => ['prompt_tokens' => 50, 'completion_tokens' => 5]]);

        $digest = $this->digest->review($task, $parent);

        self::assertSame(2, $digest['funnel']['parts'], 'parent plus its step child');
        self::assertSame(3, $digest['funnel']['tool_calls'], "the child's work must appear in the parent's digest");
        self::assertSame(2, $digest['funnel']['repeated_calls']);
        self::assertSame(55, $digest['tokens']['total_tokens']);
        self::assertCount(2, $digest['run']['parts']);
    }

    /**
     * Reviewing a task with a crashed newest run must not default to the
     * crash: an abandoned run is not evidence about normal behaviour.
     */
    public function testTheDefaultRunIsTheNewestSettledOneNotMerelyTheNewest(): void
    {
        $task = $this->persistedTask('Has a crashed run');

        $succeeded = new Run($task);
        $succeeded->setRole(RunRole::Standalone);
        $succeeded->setTriggeredBy(RunTrigger::Scheduled);
        $succeeded->markStarted();
        $succeeded->markSucceeded();
        $this->em->persist($succeeded);
        $this->em->flush();

        $crashed = new Run($task);
        $crashed->setRole(RunRole::Standalone);
        $crashed->setTriggeredBy(RunTrigger::Scheduled);
        $crashed->markStarted();
        $crashed->markIncomplete();
        $this->em->persist($crashed);
        $this->em->flush();

        $digest = $this->digest->review($task);

        self::assertSame($succeeded->getId(), $digest['run']['id'], 'the settled run is the default, not the crashed one');
        self::assertNotSame([], $digest['notes'], 'the skipped newer run must be explained');
        self::assertStringContainsString((string) $crashed->getId(), $digest['notes'][0]);
    }

    public function testReadLogReportsBothTheRequestedBucketAndWhatWasElided(): void
    {
        $run = $this->persistedRun();
        $this->append($run, RunEventType::LlmResponse, [
            'step' => 1,
            'content' => '',
            'reasoningContent' => 'thinking hard',
            'usage' => [],
        ]);
        $this->append($run, RunEventType::ToolCall, ['tool' => 'fetch', 'arguments' => ['q' => 'x'], 'attempt' => 1]);
        $this->append($run, RunEventType::Completion, ['result' => 'done']);

        $log = $this->digest->readLog($run, ['thinking']);

        self::assertSame(['thinking'], $log['include']);
        self::assertCount(1, $log['entries']);
        self::assertSame('thinking hard', $log['entries'][0]['text']);
        self::assertSame('thinking', $log['entries'][0]['type']);

        // Only the requested kind is present — no artifact entry crept in.
        foreach ($log['entries'] as $entry) {
            self::assertSame('thinking', $entry['type']);
        }

        // The manifest is always present, and the entry budget is reported
        // separately from the whole-response size: the framing sections are
        // never elided, so a single "returned of limit" number would read as
        // a bug whenever the labels alone exceeded a small limit.
        self::assertArrayHasKey('entry_limit_chars', $log['budget']);
        self::assertArrayHasKey('entries_chars', $log['budget']);
        self::assertArrayHasKey('elided', $log['budget']);
        self::assertSame([], $log['budget']['elided']);
    }

    public function testReadLogNamesWhatItsBudgetLeftOut(): void
    {
        $run = $this->persistedRun();
        $run->setCheckpoint(['promptHead' => ['system' => str_repeat('SYS ', 200)]]);
        $this->em->flush();

        $this->append($run, RunEventType::LlmResponse, [
            'step' => 1,
            'content' => '',
            'reasoningContent' => str_repeat('THINK ', 100),
            'usage' => [],
        ]);
        $this->append($run, RunEventType::LlmResponse, [
            'step' => 2,
            'content' => '',
            'reasoningContent' => str_repeat('MORE ', 100),
            'usage' => [],
        ]);

        $log = $this->digest->readLog($run, ['prompt', 'thinking'], RunDigest::MIN_BUDGET);

        self::assertNotSame([], $log['budget']['elided'], 'a partial read must say it is partial');
        self::assertArrayHasKey('thinking', $log['budget']['elided']);
        $manifest = $log['budget']['elided']['thinking'];
        self::assertSame(2, $manifest['entries'], 'both thought blocks existed');
        self::assertLessThan(2, $manifest['returned'], 'and not all of them were returned');
        self::assertGreaterThan(0, $manifest['elided_chars']);
        self::assertNotSame([], $log['notes'], 'the caller must be told not to conclude from a partial view');
    }

    public function testReadLogDefaultsToTheArtifactAndExplainsItsAbsence(): void
    {
        $run = $this->persistedRun();
        $run->markIncomplete();
        $this->em->flush();

        $log = $this->digest->readLog($run);

        self::assertSame(['artifact'], $log['include']);
        self::assertSame([], $log['entries']);
        self::assertNotSame([], $log['notes']);
        self::assertStringContainsString('No completion artifact', $log['notes'][0]);
    }

    public function testReadLogRejectsAnUnknownBucketByName(): void
    {
        $run = $this->persistedRun();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Unknown include bucket "nope"/');

        $this->digest->readLog($run, ['nope']);
    }

    private function persistedTask(string $title): Task
    {
        $task = new Task($title, 'Brief.', TaskKind::Run, ToolboxMode::Explicit, ['echo'], TaskAuthor::User);
        $this->em->persist($task);
        $this->em->flush();

        return $task;
    }

    private function persistedRun(): Run
    {
        $run = new Run($this->persistedTask('Digest subject'));
        $run->setRole(RunRole::Standalone);
        $run->setTriggeredBy(RunTrigger::Manual);
        $run->markStarted();
        $run->markSucceeded();
        $this->em->persist($run);
        $this->em->flush();

        return $run;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function append(
        Run $run,
        RunEventType $type,
        array $payload,
        ?ErrorClass $errorClass = null,
        ?int $attemptNo = null,
    ): void {
        $event = new RunEvent($type);
        $event->setPayload($payload);
        if (null !== $errorClass) {
            $event->setErrorClass($errorClass);
        }
        if (null !== $attemptNo) {
            $event->setAttemptNo($attemptNo);
        }

        $run->appendEvent($event);
        $this->em->persist($event);
        $this->em->flush();
    }

    /**
     * @param array<string, mixed> $digest
     *
     * @return array<string, mixed>
     */
    private function toolRow(array $digest, string $tool): array
    {
        foreach ($digest['tools'] as $row) {
            if ($row['tool'] === $tool) {
                return $row;
            }
        }

        self::fail(\sprintf('No digest row for tool "%s" (rows: %s)', $tool, json_encode($digest['tools'])));
    }
}

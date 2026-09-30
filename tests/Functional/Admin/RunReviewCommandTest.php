<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

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
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The reviewer's digest at a terminal (SPEC §10) — the same RunDigest the
 * MCP tools call, so this also guards the CLI/MCP contract staying aligned.
 *
 * The command reports, it does not judge: a run that FAILED still produces
 * a digest and exit code 0. Only "there is nothing to review" is a failure,
 * because that is a different kind of answer from "here is a bad run".
 */
final class RunReviewCommandTest extends KernelTestCase
{
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
    }

    public function testItDigestsTheNewestSettledRunAsJson(): void
    {
        [$task, $run] = $this->taskWithRun();
        $this->append($run, RunEventType::Completion, ['result' => 'the briefing']);

        $tester = $this->executeReview(['task-id' => (string) $task->getId()]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $digest = json_decode($tester->getDisplay(), true);
        self::assertIsArray($digest);
        self::assertSame($run->getId(), $digest['run']['id']);
        self::assertTrue($digest['artifact']['present']);
    }

    public function testAFailedRunStillReportsRatherThanErroring(): void
    {
        [$task] = $this->taskWithRun();

        $bad = new Run($task);
        $bad->setRole(RunRole::Standalone);
        $bad->setTriggeredBy(RunTrigger::Manual);
        $bad->markStarted();
        $bad->markFailed(\App\Entity\ErrorClass::ServerError);
        $this->em->persist($bad);
        $this->em->flush();

        $tester = $this->executeReview(['task-id' => (string) $task->getId()]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), 'a failed run is still a reviewable outcome');
        $digest = json_decode($tester->getDisplay(), true);
        self::assertSame($bad->getId(), $digest['run']['id']);
    }

    public function testAnUnknownTaskIsAFailureWithAClearMessage(): void
    {
        $tester = $this->executeReview(['task-id' => '999999']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('No task with id 999999', $tester->getDisplay());
    }

    public function testARunFromAnotherTaskIsRefused(): void
    {
        [, $run] = $this->taskWithRun();

        $other = new Task('Other', 'Brief.', TaskKind::Run, ToolboxMode::Explicit, ['echo'], TaskAuthor::User);
        $this->em->persist($other);
        $this->em->flush();

        $tester = $this->executeReview(['task-id' => (string) $other->getId(), '--run-id' => (string) $run->getId()]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('belongs to task', $tester->getDisplay());
    }

    public function testReadLogPrintsOnlyTheRequestedBuckets(): void
    {
        [$task, $run] = $this->taskWithRun();
        $this->append($run, RunEventType::LlmResponse, [
            'step' => 1,
            'content' => '',
            'reasoningContent' => 'thinking',
            'usage' => [],
        ]);
        $this->append($run, RunEventType::Completion, ['result' => 'artifact']);

        $tester = $this->executeReview(['task-id' => (string) $task->getId(), '--read-log' => 'thinking']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $log = json_decode($tester->getDisplay(), true);
        self::assertSame(['thinking'], $log['include']);
        self::assertCount(1, $log['entries']);
        self::assertSame('thinking', $log['entries'][0]['text']);
    }

    public function testAPrettyPrintedDigestIsStillValidJson(): void
    {
        [$task] = $this->taskWithRun();

        $tester = $this->executeReview(['task-id' => (string) $task->getId(), '--pretty' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertIsArray(json_decode($tester->getDisplay(), true));
    }

    /**
     * @return array{0: Task, 1: Run}
     */
    private function taskWithRun(): array
    {
        $task = new Task('Review subject', 'Brief.', TaskKind::Run, ToolboxMode::Explicit, ['echo'], TaskAuthor::User);
        $this->em->persist($task);
        $this->em->flush();

        $run = new Run($task);
        $run->setRole(RunRole::Standalone);
        $run->setTriggeredBy(RunTrigger::Manual);
        $run->markStarted();
        $run->markSucceeded();
        $this->em->persist($run);
        $this->em->flush();

        return [$task, $run];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function append(Run $run, RunEventType $type, array $payload): void
    {
        $event = new RunEvent($type);
        $event->setPayload($payload);
        $run->appendEvent($event);
        $this->em->persist($event);
        $this->em->flush();
    }

    /**
     * @param array<string, mixed> $input
     */
    private function executeReview(array $input): CommandTester
    {
        $application = new Application(self::$kernel);
        $tester = new CommandTester($application->find('app:run:review'));
        $tester->execute($input);

        return $tester;
    }
}

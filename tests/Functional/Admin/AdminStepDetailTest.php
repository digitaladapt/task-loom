<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\Run;
use App\Entity\RunRole;
use App\Entity\Step;
use App\Entity\Task;
use App\Entity\TaskAuthor;
use App\Entity\TaskKind;
use App\Entity\ToolboxMode;
use App\Repository\StepRepository;
use App\Repository\TaskRepository;
use App\Security\AdminUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The task detail page's step surface over real HTTP (SPEC §13.6): the
 * approval gate's human needs to see the graph — levels, per-step
 * declarations, empty-toolbox warnings — before enabling.
 */
final class AdminStepDetailTest extends WebTestCase
{
    private KernelBrowser $client; // @phpstan-ignore property.uninitialized (assigned in setUp)

    #[\Override]
    protected function setUp(): void
    {
        static::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->client->loginUser(new AdminUser());

        $em = $this->em();
        $em->createQuery('DELETE FROM App\Entity\Step')->execute();
        $em->createQuery('DELETE FROM App\Entity\ToolCall')->execute();
        $em->createQuery('DELETE FROM App\Entity\RunEvent')->execute();
        $em->createQuery('DELETE FROM App\Entity\Run')->execute();
        $em->createQuery('DELETE FROM App\Entity\Task')->execute();
        $em->createQuery('DELETE FROM App\Entity\Tool')->execute();
        $em->createQuery('DELETE FROM App\Entity\McpServer')->execute();
        $em->flush();
        $em->clear();
    }

    public function testZeroStepTaskShowsSingleUnitNote(): void
    {
        $task = $this->draftTask('No steps here');

        $this->client->request('GET', '/tasks/'.$task->getId());

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Steps', $content);
        self::assertStringContainsString('No steps — this task runs as a single unit', $content);
    }

    public function testSteppedTaskRendersLevelsAndStepCards(): void
    {
        $task = $this->draftTask('Stepped briefing');
        $weather = $this->step($task, 1, 'Weather', ToolboxMode::Explicit, ['get_weather']);
        $this->step($task, 2, 'Summary', ToolboxMode::Explicit, ['get_weather'], [$weather->getId()]);

        $this->client->request('GET', '/tasks/'.$task->getId());

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Level 1', $content);
        self::assertStringContainsString('Level 2', $content);
        self::assertStringContainsString('Weather', $content);
        self::assertStringContainsString('Summary', $content);
        self::assertStringContainsString('2 step(s) in 2 level(s)', $content);
        // get_weather is not in the catalog: every step warns.
        self::assertStringContainsString('Resolves to an empty toolbox', $content);
        self::assertStringContainsString('is not in the catalog', $content);
    }

    public function testInvalidGraphRendersProblemsOnDetailPage(): void
    {
        $task = $this->draftTask('Cyclic');
        $a = $this->step($task, 1, 'A', ToolboxMode::Tags, ['x']);
        $b = $this->step($task, 2, 'B', ToolboxMode::Tags, ['x'], [$a->getId()]);
        $a->setDependsOn([$b->getId()]);
        $this->steps()->save($a);

        $this->client->request('GET', '/tasks/'.$task->getId());

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Dependency cycle', $content);
        self::assertStringContainsString('enable/approve will refuse until it is fixed', $content);
    }

    public function testRunHistoryGroupsChildRunsUnderTheirParent(): void
    {
        // SPEC §13.6: the run surface groups by parent run — child runs of
        // one task run render under the parent, where steps become visible;
        // a zero-step task's standalone run renders alone.
        $stepped = $this->draftTask('Stepped with runs');
        $weather = $this->step($stepped, 1, 'Weather', ToolboxMode::Tags, ['weather']);
        $this->step($stepped, 2, 'Summary', ToolboxMode::Tags, ['weather'], [$weather->getId()]);

        $parent = new Run($stepped);
        $parent->setRole(RunRole::Parent);
        $parent->markStarted();
        $parent->markSucceeded();
        $this->em()->persist($parent);
        $this->em()->flush();

        $stepChild = new Run($stepped);
        $stepChild->setRole(RunRole::Step);
        $stepChild->setParent($parent);
        $stepChild->setStep($weather);
        $stepChild->markStarted();
        $stepChild->markSucceeded();
        $this->em()->persist($stepChild);
        $this->em()->flush();

        $finalChild = new Run($stepped);
        $finalChild->setRole(RunRole::FinalConsumer);
        $finalChild->setParent($parent);
        $finalChild->markStarted();
        $finalChild->markSucceeded();
        $this->em()->persist($finalChild);
        $this->em()->flush();

        $this->client->request('GET', '/tasks/'.$stepped->getId());

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();

        // The parent heads the group, labeled with its child count; the
        // children render nested with step titles and the final consumer.
        self::assertStringContainsString('Run #'.$parent->getId(), $content);
        self::assertStringContainsString('2 child run(s)', $content);
        self::assertStringContainsString('Weather', $content);
        self::assertStringContainsString('final consumer', $content);
        self::assertStringContainsString('run #'.$finalChild->getId(), $content);
    }

    public function testZeroStepRunRendersWithoutChildGrouping(): void
    {
        $task = $this->draftTask('Standalone run');

        $run = new Run($task);
        $run->markStarted();
        $run->markSucceeded();
        $this->em()->persist($run);
        $this->em()->flush();

        $this->client->request('GET', '/tasks/'.$task->getId());

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Run #'.$run->getId(), $content);
        self::assertStringNotContainsString('child run(s)', $content);
    }

    // --------------------------------------------------------------- helpers

    private function draftTask(string $title): Task
    {
        $task = new Task($title, 'Compose.', TaskKind::Run, ToolboxMode::Tags, ['weather'], TaskAuthor::User);
        $this->tasks()->save($task);

        return $task;
    }

    /**
     * @param list<string> $toolbox
     * @param list<int>    $dependsOn
     */
    private function step(Task $task, int $position, string $title, ToolboxMode $mode, array $toolbox, array $dependsOn = []): Step
    {
        $step = new Step($task, $position, $title, "Do {$title}.", $mode, $toolbox, $dependsOn);
        $this->steps()->save($step);

        return $step;
    }

    private function tasks(): TaskRepository
    {
        $tasks = static::getContainer()->get(TaskRepository::class);
        \assert($tasks instanceof TaskRepository);

        return $tasks;
    }

    private function steps(): StepRepository
    {
        $steps = static::getContainer()->get(StepRepository::class);
        \assert($steps instanceof StepRepository);

        return $steps;
    }

    private function em(): EntityManagerInterface
    {
        $em = static::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }
}

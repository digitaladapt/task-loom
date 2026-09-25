<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\Step;
use App\Entity\Task;
use App\Entity\TaskAuthor;
use App\Entity\TaskKind;
use App\Entity\ToolboxMode;
use App\Repository\StepRepository;
use App\Repository\TaskRepository;
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
        $this->client->setServerParameter('PHP_AUTH_USER', 'admin');
        $this->client->setServerParameter('PHP_AUTH_PW', 'test-admin-password');

        $em = $this->em();
        $em->createQuery('DELETE FROM App\Entity\Step')->execute();
        $em->createQuery('DELETE FROM App\Entity\ToolCall')->execute();
        $em->createQuery('DELETE FROM App\Entity\RunEvent')->execute();
        $em->createQuery('DELETE FROM App\Entity\Run')->execute();
        $em->createQuery('DELETE FROM App\Entity\Task')->execute();
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

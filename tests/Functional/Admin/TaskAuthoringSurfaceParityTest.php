<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Admin\TaskEditorService;
use App\Admin\TaskEditorSubmission;
use App\Entity\Task;
use App\Entity\TaskAuthor;
use App\Entity\TaskKind;
use App\Entity\ToolboxMode;
use App\Mcp\Server\TaskCrud;
use App\Mcp\Server\TaskTools;
use App\Repository\StepRepository;
use App\Repository\TaskRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * One write path, two front doors (SPEC §4.3).
 *
 * The admin editor and the task MCP tools must not drift: the gate that keeps
 * every authored task disabled, the SPEC §4.4 replacement semantics, and the
 * step-graph transaction are the same code for both. This asserts that
 * directly — the same service the browser drives, alongside the service the
 * MCP tools drive, with the same guarantees on each.
 *
 * The author recorded on the row is what distinguishes them, and it is what
 * the approval queue shows the human: "who proposed this?".
 */
final class TaskAuthoringSurfaceParityTest extends KernelTestCase
{
    private EntityManagerInterface $em; // @phpstan-ignore property.uninitialized (assigned in setUp)
    private TaskEditorService $editor; // @phpstan-ignore property.uninitialized (assigned in setUp)
    private TaskCrud $crud; // @phpstan-ignore property.uninitialized (assigned in setUp)
    private TaskTools $tools; // @phpstan-ignore property.uninitialized (assigned in setUp)
    private TaskRepository $tasks; // @phpstan-ignore property.uninitialized (assigned in setUp)
    private StepRepository $steps; // @phpstan-ignore property.uninitialized (assigned in setUp)

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $this->em = $container->get('doctrine')->getManager();
        $this->em->createQuery('DELETE FROM App\Entity\Step')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\ToolCall')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\RunEvent')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\Run')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\Task')->execute();
        $this->em->flush();
        $this->em->clear();

        $this->editor = $container->get(TaskEditorService::class);
        $this->crud = $container->get(TaskCrud::class);
        $this->tools = $container->get(TaskTools::class);
        $this->tasks = $container->get(TaskRepository::class);
        $this->steps = $container->get(StepRepository::class);
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function submission(array $fields): TaskEditorSubmission
    {
        return $this->editor->parse(array_replace([
            'title' => 'A task',
            'brief' => 'Do the thing.',
            'kind' => 'run',
            'toolbox_mode' => 'tags',
            'toolbox_tags' => ['weather'],
            'schedule_mode' => 'none',
        ], $fields));
    }

    public function testTheBrowserPathAuthorsAsTheUserAndStillLandsDisabled(): void
    {
        $task = $this->editor->save(null, $this->submission(['title' => 'From the UI']));

        self::assertFalse($task->isEnabled(), 'the human path is gated too (SPEC §4.3)');
        self::assertSame(TaskAuthor::User, $task->getCreatedBy());
    }

    public function testTheAgentPathAuthorsAsTheAgentAndStillLandsDisabled(): void
    {
        $result = $this->tools->create('From an agent', 'Do it.', TaskKind::Run, ToolboxMode::Tags, ['weather'], null);

        $task = $this->tasks->find($result['id']);
        self::assertInstanceOf(Task::class, $task);
        self::assertFalse($task->isEnabled());
        self::assertSame(TaskAuthor::Agent, $task->getCreatedBy());
    }

    /**
     * SPEC §4.4 applies identically to both: editing an enabled task is never
     * a mutation, it is a replacement draft.
     */
    public function testBothFrontDoorsReplaceRatherThanMutateAnEnabledTask(): void
    {
        $original = $this->editor->save(null, $this->submission(['title' => 'Enabled original']));
        $original->enable();
        $this->em->flush();
        $originalId = $original->getId();

        // The human edits it…
        $fromUi = $this->editor->save($this->reload($originalId), $this->submission(['title' => 'Edited in the UI']));
        self::assertNotSame($originalId, $fromUi->getId());
        self::assertFalse($fromUi->isEnabled());
        self::assertSame(TaskAuthor::User, $fromUi->getCreatedBy());
        self::assertSame($originalId, $fromUi->getReplacementFor()?->getId());

        // …and the agent edits it.
        $fromAgent = $this->crud->update($originalId, ['title' => 'Edited by an agent']);
        self::assertNotSame($originalId, $fromAgent->getId());
        self::assertFalse($fromAgent->isEnabled());
        self::assertSame(TaskAuthor::Agent, $fromAgent->getCreatedBy());
        self::assertSame($originalId, $fromAgent->getReplacementFor()?->getId());

        // Through all of it, the enabled original is untouched.
        $fresh = $this->reload($originalId);
        self::assertTrue($fresh->isEnabled());
        self::assertSame('Enabled original', $fresh->getTitle());
        self::assertFalse($fresh->isArchived());
    }

    /**
     * The two front doors share one graph format: what the browser's editor
     * produces IS valid authoring/wire format, so it can be handed straight to
     * the MCP path (and vice versa). That is the property that keeps the paths
     * from drifting — there is no second graph dialect to keep in sync.
     *
     * The form POST here carries the HTML shape (checkboxes plus free-text
     * companions, `toolbox_tags[]`); what comes out of the parser is
     * `title`/`brief`/`toolbox_mode`/`toolbox` — the same four fields the codec
     * accepts.
     */
    public function testTheEditorsOutputIsValidWireFormatForTheAgentPath(): void
    {
        $fromUiForm = $this->submission([
            'title' => 'UI graph',
            'steps' => [
                0 => [
                    0 => ['title' => 'First', 'brief' => 'A.', 'toolbox_mode' => 'tags', 'toolbox_tags' => ['weather']],
                    1 => ['title' => 'Second', 'brief' => 'B.', 'toolbox_mode' => 'tags', 'toolbox_tags' => ['weather']],
                ],
                1 => [
                    0 => ['title' => 'Third', 'brief' => 'C.', 'toolbox_mode' => 'tags', 'toolbox_tags' => ['weather']],
                ],
            ],
        ]);
        self::assertFalse($fromUiForm->hasErrors());

        // The editor writes it…
        $fromUi = $this->editor->save(null, $fromUiForm);

        // …and the very same array goes to the MCP tools, which accept the
        // wire format verbatim. (A shape mismatch here would throw.)
        $agentResult = $this->tools->create(
            'Agent graph',
            'Do it.',
            TaskKind::Run,
            ToolboxMode::Tags,
            ['weather'],
            null,
            $fromUiForm->steps(),
        );
        $fromAgent = $this->tasks->find($agentResult['id']);
        self::assertInstanceOf(Task::class, $fromAgent);

        $uiEdges = $this->describeGraph($fromUi);
        $agentEdges = $this->describeGraph($fromAgent);

        self::assertSame(['First' => [], 'Second' => [], 'Third' => ['First', 'Second']], $uiEdges);
        self::assertSame($uiEdges, $agentEdges, 'both front doors must produce the same graph shape');
    }

    /**
     * A draft is editable in place by both paths — replacement semantics only
     * kick in once a task is enabled.
     */
    public function testBothFrontDoorsEditADraftInPlace(): void
    {
        $fromUi = $this->editor->save(null, $this->submission(['title' => 'UI draft']));
        $edited = $this->editor->save($fromUi, $this->submission(['title' => 'UI draft, edited']));
        self::assertSame($fromUi->getId(), $edited->getId(), 'a draft is edited, not replaced');

        $fromAgent = $this->crud->update($fromUi->getId(), ['title' => 'Edited again']);
        self::assertSame($fromUi->getId(), $fromAgent->getId());
        self::assertSame('Edited again', $this->reload($fromUi->getId())->getTitle());
    }

    /**
     * The schedule is validated by the same authority on both paths
     * (SPEC §14.5): an invalid expression never persists.
     */
    public function testBothFrontDoorsRefuseAnInvalidSchedule(): void
    {
        $submission = $this->submission([
            'title' => 'Bad from the UI',
            'schedule_mode' => 'custom',
            'schedule_custom' => 'every morning',
        ]);
        self::assertTrue($submission->hasErrors(), 'the editor reports it before the write');

        $this->expectException(\App\Scheduler\ScheduleFormatException::class);
        $this->crud->update(
            $this->editor->save(null, $this->submission(['title' => 'A draft']))->getId() ?? 0,
            ['schedule' => 'every morning'],
        );
    }

    public function testTheEditorOpensAnAgentsTaskWithItsGraphIntact(): void
    {
        // Written by the agent, in the wire format the MCP tools take.
        $wireGraph = [
            0 => [0 => ['title' => 'From the agent', 'brief' => 'A.', 'toolbox_mode' => 'tags', 'toolbox' => ['weather']]],
            1 => [0 => ['title' => 'Then this', 'brief' => 'B.', 'toolbox_mode' => 'tags', 'toolbox' => ['weather']]],
        ];
        $result = $this->tools->create('Agent authored', 'Do it.', TaskKind::Run, ToolboxMode::Tags, ['weather'], null, $wireGraph);
        $task = $this->tasks->find($result['id']);
        self::assertInstanceOf(Task::class, $task);

        // What the browser's editor would render, no JavaScript involved.
        $values = $this->editor->valuesFor($task);

        self::assertCount(2, $values['steps']);
        self::assertSame('From the agent', $values['steps'][0][0]['title']);
        self::assertSame('Then this', $values['steps'][1][0]['title']);
        // …and it round-trips: re-saving what the editor rendered produces the
        // same graph, so opening an agent's task and pressing save cannot
        // quietly reshape it.
        $resaved = $this->editor->save(null, $this->submission([
            'title' => 'Round tripped',
            'steps' => $values['steps'],
        ]));

        self::assertSame($this->describeGraph($task), $this->describeGraph($resaved));
    }

    /**
     * @return array<string, list<string>>
     */
    private function describeGraph(Task $task): array
    {
        $steps = $this->steps->findForTask($task);
        $titles = [];
        foreach ($steps as $step) {
            $titles[(int) $step->getId()] = $step->getTitle();
        }

        $described = [];
        foreach ($steps as $step) {
            $described[$step->getTitle()] = array_map(
                static fn (int $id): string => $titles[$id] ?? (string) $id,
                $step->getDependsOn(),
            );
        }

        return array_map(
            static function (array $deps): array {
                sort($deps);

                return $deps;
            },
            $described,
        );
    }

    private function reload(?int $id): Task
    {
        $this->em->clear();
        $task = $this->tasks->find($id);
        self::assertInstanceOf(Task::class, $task);

        return $task;
    }
}

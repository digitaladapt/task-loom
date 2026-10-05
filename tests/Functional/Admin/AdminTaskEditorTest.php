<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\McpServer;
use App\Entity\Run;
use App\Entity\RunRole;
use App\Entity\RunStatus;
use App\Entity\ServerProtocol;
use App\Entity\Step;
use App\Entity\Task;
use App\Entity\TaskAuthor;
use App\Entity\TaskKind;
use App\Entity\Tool;
use App\Entity\ToolboxMode;
use App\Repository\StepRepository;
use App\Repository\TaskRepository;
use App\Security\AdminUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The admin task editor over real HTTP (SPEC §8, ROADMAP v1.x): create and
 * edit a task — fields, toolbox, step graph, schedule — from the browser.
 *
 * These go through the real form: a GET to read the page (and mint a real CSRF
 * token), a POST of what a browser would submit. That is the contract the
 * feature exists for, and it is where the gate and the replacement semantics
 * have to hold for the human exactly as they do for an agent.
 */
final class AdminTaskEditorTest extends WebTestCase
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

    /**
     * Regression (live incident, browser end): a task that had run, been
     * disabled, and then been edited from the editor in place — which deleted
     * the step row a run of the task pointed at, leaving the task's own page
     * unloadable ("Entity of type 'App\\Entity\\Step' for IDs id(71) was not
     * found").
     *
     * The editor is the surface that has to hold the line for a human the way
     * TaskCrud holds it for an agent, and the run-bearing half of the rule is
     * the surprising half: nothing about this task says "enabled".
     */
    public function testEditingADisabledTaskThatHasRunCreatesAReplacementAndTheTaskPageStillLoads(): void
    {
        // The graph is authored on the draft, as it would be in real use.
        $task = $this->makeDraft('Paused record');
        $step = new Step($task, 1, 'Fetch', 'Fetch it.', ToolboxMode::Tags, ['weather']);
        $this->em()->persist($step);
        $this->em()->flush();

        $task->enable();
        $this->tasks()->save($task);

        // A step child under its parent aggregator: the child is the row that
        // carries run.step_id, and the one a graph replacement would have
        // deleted out from under the ledger.
        $parent = new Run($task);
        $parent->setRole(RunRole::Parent);
        $parent->setStatus(RunStatus::Succeeded);
        $this->em()->persist($parent);

        $child = new Run($task);
        $child->setRole(RunRole::Step);
        $child->setStep($step);
        $child->setParent($parent);
        $child->setStatus(RunStatus::Succeeded);
        $this->em()->persist($child);
        $this->em()->flush();

        $task->disable();
        $this->tasks()->save($task);
        $taskId = $task->getId();
        $stepId = $step->getId();

        // The editor says why before anything is saved.
        $crawler = $this->client->request('GET', '/tasks/'.$taskId.'/edit');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('This task has run', (string) $this->client->getResponse()->getContent());

        // A real browser save: every field the form renders, including the
        // step graph it seeded from the stored one.
        $this->client->submit($crawler->selectButton('Save changes')->form(['title' => 'Edited record']));
        self::assertResponseRedirects();

        $this->em()->clear();
        $fresh = $this->tasks()->find($taskId);
        self::assertInstanceOf(Task::class, $fresh);
        self::assertSame('Paused record', $fresh->getTitle(), 'the record is never mutated in place');

        $drafts = $this->tasks()->findReplacementDraftsFor($fresh);
        self::assertCount(1, $drafts, 'the edit landed as a replacement draft');
        self::assertSame('Edited record', $drafts[0]->getTitle());

        // The step the run recorded is still there, and the task page — the
        // page that throws on a dangling step_id — still renders.
        self::assertNotNull($this->steps()->find($stepId));
        $this->client->request('GET', '/tasks/'.$taskId);
        self::assertResponseIsSuccessful();
    }

    public function testTheNewTaskFormRenders(): void
    {
        $this->client->request('GET', '/tasks/new');

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('New task', $content);
        self::assertStringContainsString('name="title"', $content);
        self::assertStringContainsString('name="brief"', $content);
        self::assertStringContainsString('name="toolbox_mode"', $content);
        self::assertStringContainsString('data-schedule', $content);
    }

    public function testTheEditorRequiresAuthentication(): void
    {
        static::ensureKernelShutdown();
        $client = static::createClient();

        $client->request('GET', '/tasks/new');

        self::assertTrue($client->getResponse()->isRedirect('/login'));
    }

    /**
     * SPEC §4.3: the human's own writes land disabled too. The editor is not
     * a back door around the approval queue — authoring and approving are
     * separate acts even for the person who authored the task.
     */
    public function testCreatingATaskLandsADisabledDraftAuthoredByTheUser(): void
    {
        $crawler = $this->client->request('GET', '/tasks/new');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Create draft')->form([
            'title' => 'From the browser',
            'brief' => 'Do the thing.',
            'kind' => 'run',
            'toolbox_mode' => 'tags',
        ]);

        $this->client->submit($form);

        self::assertResponseRedirects();

        $task = $this->onlyTask();
        self::assertSame('From the browser', $task->getTitle());
        self::assertFalse($task->isEnabled(), 'a created task must land disabled (SPEC §4.3)');
        self::assertSame(TaskAuthor::User, $task->getCreatedBy(), 'the browser path authors as the user');
        self::assertSame(['tags'], array_column([$task->getToolboxMode()], 'value'));

        // And it is in the approval queue, where a human decides.
        self::assertContains($task->getId(), array_map(
            static fn (Task $t): ?int => $t->getId(),
            $this->tasks()->findApprovalQueue(),
        ));
    }

    public function testCreatingASteppedTaskPersistsTheGraphAsAuthored(): void
    {
        $crawler = $this->client->request('GET', '/tasks/new');
        $form = $crawler->selectButton('Create draft')->form([
            'title' => 'Stepped briefing',
            'brief' => 'Compose it.',
            'kind' => 'run',
            'toolbox_mode' => 'explicit',
            'toolbox_tools_extra' => 'get_weather',
        ]);
        $this->client->submit($form);
        self::assertResponseRedirects();

        // Now edit in the graph, exactly as the builder submits it: two
        // levels, two steps in the first.
        $task = $this->onlyTask();
        $crawler = $this->client->request('GET', '/tasks/'.$task->getId().'/edit');
        self::assertResponseIsSuccessful();

        $this->postEditor($task, [
            'title' => 'Stepped briefing',
            'brief' => 'Compose it.',
            'steps' => [
                0 => [
                    0 => ['title' => 'Weather', 'brief' => 'Fetch it.', 'toolbox_mode' => 'explicit', 'toolbox_tools_extra' => 'get_weather'],
                    1 => ['title' => 'Calendar', 'brief' => 'Fetch it.', 'toolbox_mode' => 'explicit', 'toolbox_tools_extra' => 'get_events'],
                ],
                1 => [
                    0 => ['title' => 'Summary', 'brief' => 'Summarize.', 'toolbox_mode' => 'explicit', 'toolbox_tools_extra' => 'get_weather'],
                ],
            ],
        ]);

        self::assertResponseRedirects();

        $steps = $this->steps()->findForTask($this->refetch($task));
        self::assertCount(3, $steps);
        self::assertSame(['Weather', 'Calendar', 'Summary'], array_map(
            static fn ($s): string => $s->getTitle(),
            $steps,
        ));

        // The edges the wire format implies: both level-1 steps depend on the
        // whole of level 0, and Summary depends on Weather and Calendar.
        $byTitle = [];
        foreach ($steps as $step) {
            $byTitle[$step->getTitle()] = $step;
        }
        self::assertSame([], $byTitle['Weather']->getDependsOn());
        self::assertSame([], $byTitle['Calendar']->getDependsOn());
        self::assertEqualsCanonicalizing(
            [$byTitle['Weather']->getId(), $byTitle['Calendar']->getId()],
            $byTitle['Summary']->getDependsOn(),
        );

        // Each step carries its own toolbox declaration.
        self::assertSame(['get_events'], $byTitle['Calendar']->getToolbox());
        self::assertSame(ToolboxMode::Explicit, $byTitle['Calendar']->getToolboxMode());
    }

    public function testASavedScheduleReopensAsThePresetThatComposedIt(): void
    {
        $task = $this->makeDraft('Scheduled');
        $task->setSchedule('30 6 * * 1-5');
        $this->tasks()->save($task);

        $this->client->request('GET', '/tasks/'.$task->getId().'/edit');

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        // The picker is on the preset that produced the expression, and the
        // time field carries the recovered wall clock — not "custom cron".
        self::assertMatchesRegularExpression('/value="preset"[^>]*checked/', $content);
        self::assertMatchesRegularExpression('/value="weekdays"[^>]*selected/', $content);
        self::assertMatchesRegularExpression('/name="schedule_time" value="06:30"/', $content);
    }

    /**
     * An expression no preset composed must reopen as custom, so re-saving
     * cannot silently rewrite it into a preset shape.
     */
    public function testAnUnrecognisedScheduleReopensAsCustom(): void
    {
        $task = $this->makeDraft('Exotic');
        $task->setSchedule('0 8,12 * * *');
        $this->tasks()->save($task);

        $this->client->request('GET', '/tasks/'.$task->getId().'/edit');

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertMatchesRegularExpression('/value="custom"[^>]*checked/', $content);
        self::assertStringContainsString('value="0 8,12 * * *"', $content);
    }

    public function testSavingAnInvalidScheduleReRendersWithTheProblem(): void
    {
        $task = $this->makeDraft('Bad schedule');

        $crawler = $this->client->request('GET', '/tasks/'.$task->getId().'/edit');
        $form = $crawler->selectButton('Save changes')->form();
        $this->client->submit($form, [
            'schedule_mode' => 'custom',
            'schedule_custom' => 'every morning',
        ]);

        // A re-render, not a redirect: the human's work is not thrown away.
        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Nothing was saved', $content);
        self::assertStringContainsString('not a valid cron expression', $content);

        self::assertNull($this->refetch($task)->getSchedule(), 'an invalid schedule must not persist');
    }

    public function testSavingWithAMissingTitleReRendersWithTheSubmittedValues(): void
    {
        $task = $this->makeDraft('Keep my work');

        $crawler = $this->client->request('GET', '/tasks/'.$task->getId().'/edit');
        $form = $crawler->selectButton('Save changes')->form();
        $this->client->submit($form, [
            'title' => '',
            'brief' => 'This brief must survive the round trip.',
        ]);

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('This brief must survive the round trip.', $content);
        self::assertStringContainsString('A title is required.', $content);
        self::assertSame('Keep my work', $this->refetch($task)->getTitle(), 'the stored task is untouched');
    }

    /**
     * SPEC §4.4 — the rule the editor has to honour above all others: an
     * enabled task is immutable for everyone. Saving produces a disabled
     * replacement draft; the running task keeps running, untouched.
     */
    public function testEditingAnEnabledTaskCreatesAReplacementDraftAndLeavesTheOriginalAlone(): void
    {
        $original = $this->makeDraft('Original brief');
        $original->enable();
        $this->tasks()->save($original);
        $originalId = $original->getId();

        $crawler = $this->client->request('GET', '/tasks/'.$originalId.'/edit');
        self::assertResponseIsSuccessful();
        // The page says so before anything is saved.
        self::assertStringContainsString('This task is enabled', (string) $this->client->getResponse()->getContent());

        $form = $crawler->selectButton('Save changes')->form(['title' => 'Replacement brief']);
        $this->client->submit($form);
        self::assertResponseRedirects();

        $this->em()->clear();

        $fresh = $this->tasks()->find($originalId);
        self::assertNotNull($fresh);
        self::assertTrue($fresh->isEnabled(), 'the original keeps running');
        self::assertSame('Original brief', $fresh->getTitle(), 'the original is never mutated');
        self::assertFalse($fresh->isArchived(), 'the original is not archived until the replacement is approved');

        $drafts = $this->tasks()->findReplacementDraftsFor($fresh);
        self::assertCount(1, $drafts);
        $draft = $drafts[0];
        self::assertSame('Replacement brief', $draft->getTitle());
        self::assertFalse($draft->isEnabled(), 'a replacement draft is disabled until approved');
        self::assertSame(TaskAuthor::User, $draft->getCreatedBy());
        self::assertSame($originalId, $draft->getReplacementFor()?->getId());

        // The message names both ids, so the human is not left thinking the
        // running task changed.
        $this->client->followRedirect();
        $flash = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString((string) $draft->getId(), $flash);
    }

    public function testEditingAReplacementDraftClonesTheEnabledTasksStepGraphOntoIt(): void
    {
        // The original is enabled and stepped; the editor opens the resulting
        // draft with that graph rendered, and a save replaces it.
        $original = $this->makeDraft('Stepped original');
        $this->postEditor($original, [
            'title' => 'Stepped original',
            'brief' => 'A brief.',
            'steps' => [0 => [0 => ['title' => 'Only step', 'brief' => 'Do it.', 'toolbox_mode' => 'tags', 'toolbox_tags_extra' => 'weather']]],
        ]);
        self::assertResponseRedirects();

        $original = $this->refetch($original);
        $original->enable();
        $this->tasks()->save($original);

        $this->postEditor($original, [
            'title' => 'Edited',
            'brief' => 'A brief.',
            'steps' => [0 => [0 => ['title' => 'Renamed step', 'brief' => 'Do it differently.', 'toolbox_mode' => 'tags', 'toolbox_tags_extra' => 'weather']]],
        ]);
        self::assertResponseRedirects();

        $drafts = $this->tasks()->findReplacementDraftsFor($this->refetch($original));
        self::assertCount(1, $drafts);

        $steps = $this->steps()->findForTask($drafts[0]);
        self::assertCount(1, $steps);
        self::assertSame('Renamed step', $steps[0]->getTitle());
    }

    public function testPostingWithoutACsrfTokenIsRefused(): void
    {
        $this->client->request('POST', '/tasks/new', [
            'title' => 'Sneaky',
            'brief' => 'No token.',
        ]);

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        self::assertCount(0, $this->tasks()->findAll(), 'nothing may be written without a valid token');
    }

    public function testPostingAnEditWithoutACsrfTokenIsRefused(): void
    {
        $task = $this->makeDraft('Untouched');

        $this->client->request('POST', '/tasks/'.$task->getId().'/edit', [
            'title' => 'Changed without a token',
            'brief' => 'nope',
        ]);

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        self::assertSame('Untouched', $this->refetch($task)->getTitle());
    }

    public function testEditingAMissingTaskIs404(): void
    {
        $this->client->request('GET', '/tasks/999999/edit');

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    /**
     * The toolbox picker must offer what is actually in the catalog, so a
     * human can choose tools and tags by hand instead of typing them from
     * memory — that is most of the point of the feature.
     */
    public function testTheEditorOffersTheCatalogInBothPickers(): void
    {
        $this->catalogTool('get_weather', ['weather', 'core']);

        $this->client->request('GET', '/tasks/new');

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('name="toolbox_tags[]" value="weather"', $content);
        self::assertStringContainsString('name="toolbox_tags[]" value="core"', $content);
        self::assertStringContainsString('name="toolbox_tools[]" value="get_weather"', $content);
    }

    /**
     * The picker lists tools in the catalog's canonical order — by server,
     * then tool name — because the order is what makes the list scannable:
     * the operator reads down one server's tools, not through every server's
     * alphabetized together.
     *
     * The task's own picker and each step's render from the same list, so
     * asserting it once at task level pins both (the step card is rendered
     * from the same `catalog_tools` template variable).
     */
    public function testTheExplicitToolPickerOrdersByServerThenName(): void
    {
        // Inserted out of order and interleaved: name-only order would be
        // alpha, get_events, zeta.
        $this->catalogTool('zeta', ['core'], 'weather-srv');
        $this->catalogTool('get_events', ['core'], 'calendar-srv');
        $this->catalogTool('alpha', ['core'], 'weather-srv');

        $this->client->request('GET', '/tasks/new');

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();

        $positions = [];
        foreach (['get_events', 'alpha', 'zeta'] as $name) {
            $position = strpos($content, 'name="toolbox_tools[]" value="'.$name.'"');
            self::assertNotFalse($position, \sprintf('%s is offered as a checkbox', $name));
            $positions[$name] = $position;
        }

        self::assertLessThan(
            $positions['alpha'],
            $positions['get_events'],
            'calendar-srv sorts before weather-srv',
        );
        self::assertLessThan(
            $positions['zeta'],
            $positions['alpha'],
            'within one server, alpha sorts before zeta',
        );
    }

    /**
     * SPEC §4.1 — a step's toolbox checkboxes carry the step's field scope,
     * and the empty brackets go AFTER the prefix's closing bracket. The
     * malformed spelling (`steps[0][0][toolbox_tags[]]`) is not a PHP array at
     * all: the form parser keeps `toolbox_tags[` as a literal key, so every
     * tick is silently dropped and only the free-text companion is saved.
     *
     * The test submits the browser's own shape: it reads the *rendered* form,
     * serializes it the way a browser does, ticks one more catalog box, and
     * runs the body through PHP's form parser before it reaches the
     * controller. A name that renders but does not parse cannot pass.
     */
    public function testTickedStepTagCheckboxesSurviveTheBrowserShape(): void
    {
        $this->catalogTool('get_weather', ['weather', 'core']);

        // A stepped task with a tags toolbox, saved through the editor once so
        // the step card is in the server-rendered HTML with `weather` ticked.
        $task = $this->makeDraft('Stepped');
        $this->postEditor($task, [
            'title' => 'Stepped',
            'brief' => 'A brief.',
            'steps' => [0 => [0 => ['title' => 'Only step', 'brief' => 'Do it.', 'toolbox_mode' => 'tags', 'toolbox_tags' => ['weather']]]],
        ]);
        self::assertResponseRedirects();
        $id = $this->refetch($task)->getId();

        // Tick the *other* catalog tag in the step, as a human would, and save
        // the whole form as the browser submits it.
        $body = $this->browserFormBody('/tasks/'.$id.'/edit', [['steps[0][0][toolbox_tags][]', 'core']]);

        $this->client->request('POST', '/tasks/'.$id.'/edit', $body);
        self::assertResponseRedirects();

        $steps = $this->steps()->findForTask($this->refetch($task));
        self::assertCount(1, $steps);
        self::assertSame(ToolboxMode::Tags, $steps[0]->getToolboxMode());
        self::assertEqualsCanonicalizing(
            ['weather', 'core'],
            $steps[0]->getToolbox(),
            'both the previously ticked and the newly ticked step checkbox must persist',
        );
    }

    /**
     * The other half of the same bug: a step's explicit-tools panel must also
     * save ticked catalog tools, not just its free-text companion.
     */
    public function testTickedStepToolCheckboxesSurviveTheBrowserShape(): void
    {
        $this->catalogTool('get_weather', ['weather']);
        $this->catalogTool('get_events', ['calendar']);

        $task = $this->makeDraft('Stepped tools');
        $this->postEditor($task, [
            'title' => 'Stepped tools',
            'brief' => 'A brief.',
            'steps' => [0 => [0 => ['title' => 'Only step', 'brief' => 'Do it.', 'toolbox_mode' => 'explicit', 'toolbox_tools' => ['get_weather']]]],
        ]);
        self::assertResponseRedirects();
        $id = $this->refetch($task)->getId();

        $body = $this->browserFormBody('/tasks/'.$id.'/edit', [['steps[0][0][toolbox_tools][]', 'get_events']]);

        $this->client->request('POST', '/tasks/'.$id.'/edit', $body);
        self::assertResponseRedirects();

        $steps = $this->steps()->findForTask($this->refetch($task));
        self::assertCount(1, $steps);
        self::assertSame(ToolboxMode::Explicit, $steps[0]->getToolboxMode());
        self::assertEqualsCanonicalizing(['get_weather', 'get_events'], $steps[0]->getToolbox());
    }

    /**
     * The mode switcher keeps both panels in the DOM, so each panel's free-text
     * companion is seeded only from the declaration that belongs to **its own**
     * mode. Seeding both from the same stored list is how a tags task used to
     * arrive with its tags pasted into the tool-name field — switch to
     * "Explicit tools" and save, and tags became (unresolvable) tool names.
     * The reverse holds too.
     */
    public function testEachToolboxPanelIsSeededOnlyFromItsOwnMode(): void
    {
        // A tags task whose declaration includes an off-catalog tag: the tag
        // belongs in "More tags" and nowhere else.
        $tagsTask = $this->makeDraft('Tags task');
        $tagsTask->setToolbox(['weather', 'a tag nobody carries']);
        $this->tasks()->save($tagsTask);

        $this->client->request('GET', '/tasks/'.$tagsTask->getId().'/edit');
        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertMatchesRegularExpression('/name="toolbox_tags_extra" value="[^"]*a tag nobody carries[^"]*"/', $content,
            'off-catalog tags stay in More tags');
        self::assertDoesNotMatchRegularExpression('/name="toolbox_tools_extra" value="[^"]*a tag nobody carries[^"]*"/', $content,
            'a tags declaration must not seed the tool-name field');

        // An explicit task with an off-catalog tool: mirrored.
        $toolsTask = $this->makeDraft('Tools task');
        $toolsTask->setToolboxMode(ToolboxMode::Explicit);
        $toolsTask->setToolbox(['get_weather', 'a tool nobody registered']);
        $this->tasks()->save($toolsTask);

        $this->client->request('GET', '/tasks/'.$toolsTask->getId().'/edit');
        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertMatchesRegularExpression('/name="toolbox_tools_extra" value="[^"]*a tool nobody registered[^"]*"/', $content);
        self::assertDoesNotMatchRegularExpression('/name="toolbox_tags_extra" value="[^"]*a tool nobody registered[^"]*"/', $content,
            'an explicit declaration must not seed the tag field');
    }

    /**
     * The same seeding rule inside a step card: a step whose toolbox is tags
     * must not carry those tags in its tool-name field either. This is the
     * shape that was wrong when the inspector switched modes.
     */
    public function testEachStepToolboxPanelIsSeededOnlyFromItsOwnMode(): void
    {
        $task = $this->makeDraft('Stepped seeding');
        $this->postEditor($task, [
            'title' => 'Stepped seeding',
            'brief' => 'A brief.',
            'steps' => [0 => [0 => ['title' => 'Only step', 'brief' => 'Do it.', 'toolbox_mode' => 'tags', 'toolbox_tags' => ['weather', 'a tag nobody carries']]]],
        ]);
        self::assertResponseRedirects();

        $this->client->request('GET', '/tasks/'.$this->refetch($task)->getId().'/edit');
        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();

        // The off-catalog tag lives in the step's tags field...
        self::assertMatchesRegularExpression('/name="steps\[\d+\]\[\d+\]\[toolbox_tags_extra\]" value="[^"]*a tag nobody carries[^"]*"/', $content);
        // ...and never in its tool-name field.
        self::assertDoesNotMatchRegularExpression('/name="steps\[\d+\]\[\d+\]\[toolbox_tools_extra\]" value="[^"]*a tag nobody carries[^"]*"/', $content);
    }

    /**
     * A declaration the catalog does not carry must survive a round trip: it
     * is re-offered in the free-text field, not silently dropped by the next
     * save. Nothing carries that tag in this test, so the preview reports the
     * problem too — which is the diagnostic the human needs.
     */
    public function testAToolboxEntryOutsideTheCatalogSurvivesAnEdit(): void
    {
        $task = $this->makeDraft('Off-catalog tools');
        $task->setToolbox(['a tool nobody registered', 'weather']);
        $task->setToolboxMode(ToolboxMode::Explicit);
        $this->tasks()->save($task);

        $crawler = $this->client->request('GET', '/tasks/'.$task->getId().'/edit');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString(
            'value="a tool nobody registered, weather"',
            (string) $this->client->getResponse()->getContent(),
            'off-catalog entries must come back in the free-text field',
        );

        // Save with no changes at all, as a human would.
        $this->client->submit($crawler->selectButton('Save changes')->form());
        self::assertResponseRedirects();

        self::assertSame(['a tool nobody registered', 'weather'], $this->refetch($task)->getToolbox());
    }

    public function testTheEditLinkIsOfferedOnTheTaskDetailPage(): void
    {
        $task = $this->makeDraft('Linkable');

        $this->client->request('GET', '/tasks/'.$task->getId());

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('/tasks/'.$task->getId().'/edit', (string) $this->client->getResponse()->getContent());
    }

    public function testTheNewTaskLinkIsOfferedOnTheTaskList(): void
    {
        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('/tasks/new', (string) $this->client->getResponse()->getContent());
    }

    // --------------------------------------------------------------- helpers

    /**
     * POST the editor exactly as a browser would: read the page first for a
     * real CSRF token, then submit the fields — including the nested step
     * graph, which DomCrawler's form-object API refuses (its fields do not
     * exist until the builder's JavaScript creates them, and these tests
     * exercise the server contract those fields carry).
     *
     * @param array<string, mixed> $fields
     */
    private function postEditor(Task $task, array $fields): void
    {
        $crawler = $this->client->request('GET', '/tasks/'.$task->getId().'/edit');
        self::assertResponseIsSuccessful();

        $this->client->request('POST', '/tasks/'.$task->getId().'/edit', ['_token' => $this->tokenFrom($crawler)] + $fields);
    }

    /**
     * The CSRF token off a rendered editor page, so a POST is what a browser
     * would send.
     */
    private function tokenFrom(Crawler $crawler): string
    {
        $token = $crawler->filter('input[name="_token"]')->attr('value');
        self::assertNotNull($token);

        return $token;
    }

    /**
     * The editor form serialized the way a browser submits it: every enabled,
     * non-template field (text, hidden, the checked radio/checkbox of each
     * group, selects, textareas), plus the extra picks the caller asks for.
     * The pairs are encoded and run through PHP's form parser, so this returns
     * exactly what `$request->request->all()` would hold -- malformed names
     * included, which is the point: a name that renders but does not parse
     * cannot sneak through.
     *
     * @param list<array{string, string}> $tick name/value pairs to tick (a
     *                                          checkbox that is currently
     *                                          unticked)
     *
     * @return array<array-key, mixed>
     */
    private function browserFormBody(string $url, array $tick = []): array
    {
        $crawler = $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();

        $pairs = [];
        $nodes = $crawler->filter('form[data-task-editor] input, form[data-task-editor] textarea, form[data-task-editor] select');
        foreach ($nodes as $node) {
            if (!$node instanceof \DOMElement || $node->hasAttribute('disabled') || $this->insideTemplate($node)) {
                continue;
            }

            $name = $node->getAttribute('name');
            if ('' === $name) {
                continue;
            }

            if ('textarea' === $node->nodeName) {
                $pairs[] = [$name, $node->textContent];
                continue;
            }

            if ('select' === $node->nodeName) {
                foreach ($node->getElementsByTagName('option') as $option) {
                    if ($option->hasAttribute('selected')) {
                        $pairs[] = [$name, $option->getAttribute('value')];
                        break;
                    }
                }
                continue;
            }

            $type = strtolower($node->getAttribute('type'));
            $value = $node->getAttribute('value');

            if (\in_array($type, ['radio', 'checkbox'], true)) {
                $wanted = \in_array([$name, $value], $tick, true);
                if (!$node->hasAttribute('checked') && !$wanted) {
                    continue;
                }
                $pairs[] = [$name, $value];
                continue;
            }

            if (\in_array($type, ['submit', 'button', 'image', 'file', 'reset'], true)) {
                continue;
            }

            $pairs[] = [$name, $value];
        }

        $body = [];
        parse_str(implode('&', array_map(
            static fn (array $pair): string => urlencode($pair[0]).'='.urlencode($pair[1]),
            $pairs,
        )), $body);

        return $body;
    }

    private function insideTemplate(\DOMElement $node): bool
    {
        for ($parent = $node->parentNode; $parent instanceof \DOMElement; $parent = $parent->parentNode) {
            if ('template' === $parent->nodeName) {
                return true;
            }
        }

        return false;
    }

    private function onlyTask(): Task
    {
        $all = $this->tasks()->findAll();
        self::assertCount(1, $all);

        return $all[0];
    }

    private function makeDraft(string $title): Task
    {
        $task = new Task($title, 'A brief.', TaskKind::Run, ToolboxMode::Tags, ['weather'], TaskAuthor::User);
        $this->tasks()->save($task);

        return $task;
    }

    private function refetch(Task $task): Task
    {
        $this->em()->clear();
        $fresh = $this->tasks()->find($task->getId());
        self::assertInstanceOf(Task::class, $fresh);

        return $fresh;
    }

    /**
     * @param list<string> $tags
     */
    private function catalogTool(string $name, array $tags, ?string $serverName = null): void
    {
        $em = $this->em();
        $em->persist(new Tool(
            $this->catalogServer($em, $serverName ?? 'catalog-'.uniqid()),
            $name,
            'test tool',
            [],
            $tags,
        ));
        $em->flush();
    }

    /**
     * One server per name, reused across tools: the picker's ordering test
     * needs two tools on the same server, and a unique constraint stops it
     * from creating the same server twice.
     */
    private function catalogServer(EntityManagerInterface $em, string $name): McpServer
    {
        $server = $em->getRepository(McpServer::class)->findOneBy(['name' => $name]);
        if ($server instanceof McpServer) {
            return $server;
        }

        $server = new McpServer($name, 'https://server.example/mcp', ServerProtocol::Mcp);
        $em->persist($server);

        return $server;
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

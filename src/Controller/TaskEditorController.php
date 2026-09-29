<?php

declare(strict_types=1);

namespace App\Controller;

use App\Admin\TaskEditorException;
use App\Admin\TaskEditorService;
use App\Entity\Task;
use App\Repository\TaskRepository;
use App\Scheduler\ScheduleExpression;
use App\Scheduler\ScheduleNarrator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The admin UI's task editor (SPEC §8, ROADMAP v1.x): create a task and edit
 * one — fields, toolbox, step graph, schedule — without going through MCP or
 * the console.
 *
 * Authoring happens here; approval does not. A save lands a disabled draft
 * (SPEC §4.3, for humans as much as agents), and the human enables it from
 * the queue — the same two-step the MCP path has, so the gate means
 * something. Editing an enabled task opens a replacement draft (SPEC §4.4).
 *
 * A failed save never throws the human's work away: the submission is
 * re-rendered with every problem anchored to its field, which is why the
 * controller parses the request itself and hands values (not entities) to
 * the template.
 *
 * Every write is POST + CSRF-protected, checked explicitly so a bad token is
 * a 403 (not a Basic-auth challenge) — the same pattern as the lifecycle
 * actions in TaskAdminController.
 */
#[IsGranted('ROLE_ADMIN')]
final class TaskEditorController extends AbstractController
{
    public function __construct(
        private readonly TaskEditorService $editor,
        private readonly TaskRepository $tasks,
        private readonly ScheduleNarrator $narrator,
        #[Autowire('%env(TASKLOOM_TIMEZONE)%')]
        private readonly string $timezone,
    ) {
    }

    #[Route('/tasks/new', name: 'app_task_new', methods: ['GET'])]
    public function new(): Response
    {
        return $this->renderEditor(null, $this->editor->blankValues());
    }

    #[Route('/tasks/new', name: 'app_task_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $this->assertCsrf('task-create', $request);

        $submission = $this->editor->parse($request->request->all());

        if ($submission->hasErrors()) {
            return $this->renderEditor(null, $submission->values, $submission->errors);
        }

        try {
            $task = $this->editor->save(null, $submission);
        } catch (TaskEditorException $e) {
            return $this->renderEditor(null, $submission->values, ['_form' => $e->getMessage()]);
        }

        $this->addFlash('ok', \sprintf(
            'Task #%d created as a draft — review it, then enable it (SPEC §4.3: nothing runs until a human enables it).',
            $task->getId(),
        ));

        return $this->redirectToRoute('app_task_detail', ['id' => $task->getId()]);
    }

    #[Route('/tasks/{id}/edit', name: 'app_task_edit', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function edit(int $id): Response
    {
        $task = $this->findTaskOr404($id);

        return $this->renderEditor($task, $this->editor->valuesFor($task));
    }

    #[Route('/tasks/{id}/edit', name: 'app_task_update', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function update(Request $request, int $id): Response
    {
        $this->assertCsrf('task-update', $request);

        $task = $this->findTaskOr404($id);
        $submission = $this->editor->parse($request->request->all());

        if ($submission->hasErrors()) {
            return $this->renderEditor($task, $submission->values, $submission->errors);
        }

        try {
            $saved = $this->editor->save($task, $submission);
        } catch (TaskEditorException $e) {
            return $this->renderEditor($task, $submission->values, ['_form' => $e->getMessage()]);
        }

        // SPEC §4.4: the original kept running; the edit is a replacement
        // draft awaiting approval. Say so plainly — otherwise the human
        // saves, sees their text, and assumes the running task changed.
        if ($saved->getId() !== $task->getId()) {
            $this->addFlash('ok', \sprintf(
                'Task #%d is enabled and immutable — your edit was saved as replacement draft #%d. Approve it to swap.',
                $task->getId(),
                $saved->getId(),
            ));

            return $this->redirectToRoute('app_task_detail', ['id' => $saved->getId()]);
        }

        $this->addFlash('ok', \sprintf('Draft #%d updated.', $saved->getId()));

        return $this->redirectToRoute('app_task_detail', ['id' => $saved->getId()]);
    }

    /**
     * The live schedule preview (SPEC §14): the cron the current fields
     * compose, its English reading, and the next few times it would actually
     * fire in the deployment timezone.
     *
     * Deliberately server-side. Cron composition and occurrence computation
     * already exist here, correctly (SchedulePreset, ScheduleExpression); a
     * JavaScript re-implementation would be a second opinion about *when the
     * task runs*, and the two would eventually disagree. The browser only
     * asks; the composition stays in one place.
     *
     * A GET, deliberately: this is a pure function of the schedule fields in
     * the query string — nothing is read from or written to the database, so
     * there is no state to protect and no CSRF token to spend. (The editor
     * posts once, on save, and that carries a token.)
     */
    #[Route('/tasks/schedule/preview', name: 'app_task_schedule_preview', methods: ['GET'])]
    public function schedulePreview(Request $request): JsonResponse
    {
        $submission = $this->editor->parse($request->query->all());
        $problems = $submission->scheduleProblems();

        if ([] !== $problems) {
            return new JsonResponse([
                'ok' => false,
                'problems' => $problems,
            ]);
        }

        $schedule = $submission->schedule();
        if (null === $schedule) {
            return new JsonResponse([
                'ok' => true,
                'expression' => null,
                'description' => null,
                'upcoming' => [],
            ]);
        }

        return new JsonResponse([
            'ok' => true,
            'expression' => $schedule,
            'description' => $this->narrator->describe($schedule),
            'upcoming' => array_map(
                static fn (\DateTimeImmutable $at): string => $at->format('D, M j, Y \a\t H:i'),
                $this->narrator->upcoming($schedule, new \DateTimeImmutable()),
            ),
        ]);
    }

    /**
     * @param array<string, mixed>  $values
     * @param array<string, string> $errors
     */
    private function renderEditor(?Task $task, array $values, array $errors = []): Response
    {
        $catalog = $this->editor->catalog();

        return $this->render('task/edit.html.twig', [
            'task' => $task,
            'values' => $values,
            'errors' => $errors,
            'known_tags' => $catalog['tags'],
            'catalog_tools' => $catalog['tools'],
            'presets' => $this->editor->schedulePresets(),
            'timezone' => $this->timezone,
            'schedule_preview_url' => $this->generateUrl('app_task_schedule_preview'),
            'submit_label' => null === $task ? 'Create draft' : 'Save changes',
        ]);
    }

    /**
     * Explicit CSRF check: a bad or missing token is an access violation
     * (403), not an authentication failure — the credentials were fine, the
     * form submission was not.
     */
    private function assertCsrf(string $intent, Request $request): void
    {
        if (!$this->isCsrfTokenValid($intent, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }

    private function findTaskOr404(int $id): Task
    {
        $task = $this->tasks->find($id);
        if (!$task instanceof Task) {
            throw new NotFoundHttpException(\sprintf('No task with id %d.', $id));
        }

        return $task;
    }
}

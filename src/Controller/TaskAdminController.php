<?php

declare(strict_types=1);

namespace App\Controller;

use App\Admin\StepOverviewPresenter;
use App\Admin\TaskAdminService;
use App\Admin\TaskLifecycleException;
use App\Entity\Run;
use App\Entity\RunRole;
use App\Entity\Task;
use App\Repository\RunRepository;
use App\Repository\TaskRepository;
use App\RunEngine\RunLauncher;
use App\RunEngine\RunLaunchException;
use App\RunEngine\ToolboxPreviewer;
use App\RunEngine\ToolboxResolutionException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The admin UI's task surface (SPEC §8): task list, task detail with the
 * toolbox preview, the approval-queue lifecycle actions, and Run now —
 * the only run trigger in v1.
 *
 * Every write action is POST + CSRF-protected (checked explicitly in
 * each action, so a bad token yields 403 — not a Basic-auth challenge)
 * and requires an authenticated admin session (SPEC §4.3: approval is
 * "restricted to user-authenticated sessions. Agent identities cannot
 * reach it.") — enforced by #[IsGranted] here and by the firewall.
 */
#[IsGranted('ROLE_ADMIN')]
final class TaskAdminController extends AbstractController
{
    public function __construct(
        private readonly TaskRepository $tasks,
        private readonly RunRepository $runs,
        private readonly TaskAdminService $admin,
        private readonly ToolboxPreviewer $previewer,
        private readonly StepOverviewPresenter $stepOverview,
        private readonly RunLauncher $launcher,
    ) {
    }

    #[Route('/', name: 'app_task_list', methods: ['GET'])]
    public function list(): Response
    {
        $approvalQueue = $this->tasks->findApprovalQueue();
        $enabled = $this->tasks->findRunnable();
        $archived = $this->tasks->findArchived();

        $taskIds = [];
        foreach ([...$approvalQueue, ...$enabled, ...$archived] as $task) {
            $id = $task->getId();
            if (null !== $id) {
                $taskIds[] = $id;
            }
        }

        return $this->render('task/list.html.twig', [
            'approval_queue' => $approvalQueue,
            'enabled' => $enabled,
            'archived' => $archived,
            'latest_runs' => $this->runs->findLatestForTasks($taskIds),
        ]);
    }

    #[Route('/tasks/{id}', name: 'app_task_detail', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function detail(int $id): Response
    {
        $task = $this->findTaskOr404($id);

        return $this->render('task/detail.html.twig', [
            'task' => $task,
            'preview' => $this->previewer->preview($task),
            'step_overview' => $this->stepOverview->present($task),
            'run_groups' => $this->runGroups($task),
            'replacement_drafts' => $this->tasks->findReplacementDraftsFor($task),
        ]);
    }

    /**
     * The run history grouped by parent run (SPEC §13.6): a stepped task's
     * run is its parent aggregator with the step and final-consumer child
     * runs nested under it; a zero-step task's run is the standalone run
     * itself, in a group of one. This is where steps become visible in the
     * run surface.
     *
     * @return list<array{run: Run, children: list<Run>}>
     */
    private function runGroups(Task $task): array
    {
        $groups = [];
        foreach ($this->runs->findForTask($task) as $run) {
            $groups[] = [
                'run' => $run,
                'children' => RunRole::Standalone === $run->getRole() ? [] : $this->runs->findChildren($run),
            ];
        }

        return $groups;
    }

    #[Route('/tasks/{id}/enable', name: 'app_task_enable', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function enable(Request $request, int $id): Response
    {
        $this->assertCsrf('task-enable', $request);

        return $this->lifecycle('enable', $id);
    }

    #[Route('/tasks/{id}/approve', name: 'app_task_approve', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function approve(Request $request, int $id): Response
    {
        $this->assertCsrf('task-approve', $request);

        return $this->lifecycle('approve', $id);
    }

    #[Route('/tasks/{id}/reject', name: 'app_task_reject', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function reject(Request $request, int $id): Response
    {
        $this->assertCsrf('task-reject', $request);

        return $this->lifecycle('reject', $id);
    }

    #[Route('/tasks/{id}/archive', name: 'app_task_archive', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function archive(Request $request, int $id): Response
    {
        $this->assertCsrf('task-archive', $request);

        return $this->lifecycle('archive', $id);
    }

    #[Route('/tasks/{id}/run', name: 'app_task_run', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function run(Request $request, int $id): Response
    {
        $this->assertCsrf('task-run', $request);

        $task = $this->findTaskOr404($id);

        if (!$task->isEnabled()) {
            $this->addFlash('error', \sprintf('Task %d is not enabled — only enabled tasks run (SPEC §4.2).', $id));

            return $this->redirectToRoute('app_task_detail', ['id' => $id]);
        }

        try {
            // The queue path, never the synchronous one: a request must not
            // hold an LLM turn on the wire. The run's progress is followed
            // in the run surface; workers drive it from here.
            $launch = $this->launcher->launch($task);
        } catch (ToolboxResolutionException $e) {
            $this->addFlash('error', \sprintf('Run refused at dispatch — %s', $e->getMessage()));

            return $this->redirectToRoute('app_task_detail', ['id' => $id]);
        } catch (RunLaunchException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('app_task_detail', ['id' => $id]);
        }

        $run = $launch->run;
        if (RunRole::Parent === $run->getRole()) {
            $children = $this->runs->findChildren($run);
            $this->addFlash('ok', \sprintf('Run #%d queued — %d step run(s) on the "llm" lane.', $run->getId(), \count($children)));
        } else {
            $this->addFlash('ok', \sprintf('Run #%d queued — dispatched to the "%s" lane.', $run->getId(), $launch->lane()));
        }

        return $this->redirectToRoute('app_run_detail', ['id' => $run->getId()]);
    }

    /**
     * Explicit CSRF check: a bad or missing token is an access violation
     * (403), not an authentication failure — the credentials were fine,
     * the form submission was not.
     */
    private function assertCsrf(string $intent, Request $request): void
    {
        if (!$this->isCsrfTokenValid($intent, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }

    private function lifecycle(string $action, int $id): Response
    {
        try {
            $task = match ($action) {
                'enable' => $this->admin->enableTask($id),
                'approve' => $this->admin->approveTask($id),
                'reject' => $this->admin->rejectTask($id),
                'archive' => $this->admin->archiveTask($id),
                default => throw new \InvalidArgumentException(\sprintf('Unknown action "%s".', $action)),
            };
        } catch (TaskLifecycleException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('app_task_detail', ['id' => $id]);
        }

        $this->addFlash('ok', \sprintf('%s — done.', \ucfirst($action)));

        // The archived draft itself is no longer listable; the human came
        // from the queue, so the list (queue) is where to land.
        if ('archive' === $action || 'reject' === $action) {
            return $this->redirectToRoute('app_task_list');
        }

        return $this->redirectToRoute('app_task_detail', ['id' => $task->getId()]);
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

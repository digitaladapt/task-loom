<?php

declare(strict_types=1);

namespace App\Controller;

use App\Admin\StepOverviewPresenter;
use App\Admin\TaskAdminService;
use App\Admin\TaskLifecycleException;
use App\Entity\Task;
use App\Repository\RunRepository;
use App\Repository\TaskRepository;
use App\RunEngine\ToolboxPreviewer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The admin UI's task surface (SPEC §8): task list, task detail with the
 * toolbox preview, and the approval-queue lifecycle actions.
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
    ) {
    }

    #[Route('/', name: 'app_task_list', methods: ['GET'])]
    public function list(): Response
    {
        return $this->render('task/list.html.twig', [
            'approval_queue' => $this->tasks->findApprovalQueue(),
            'enabled' => $this->tasks->findRunnable(),
            'archived' => $this->tasks->findArchived(),
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
            'runs' => $this->runs->findForTask($task),
            'replacement_drafts' => $this->tasks->findReplacementDraftsFor($task),
        ]);
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

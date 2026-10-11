<?php

declare(strict_types=1);

namespace App\Controller;

use App\Admin\SessionPresenter;
use App\Admin\TaskAdminService;
use App\Admin\TaskLifecycleException;
use App\Entity\SessionMemory;
use App\Entity\SessionMemorySource;
use App\Entity\SessionMemoryTier;
use App\Entity\Task;
use App\Entity\TaskKind;
use App\Repository\SessionMemoryRepository;
use App\Repository\TaskRepository;
use App\RunEngine\RunLauncher;
use App\RunEngine\RunLaunchException;
use App\RunEngine\ToolboxResolutionException;
use App\Session\SessionConstraintException;
use App\Session\SessionMemoryStore;
use App\Session\SessionMemoryWriteException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The session page's write surface (docs/design/SESSION_TASKS.md §6, build
 * order step 6): the operator's steering — start/resume, pause, and the
 * memory edits (objective, notes, pin, tier, revert).
 *
 * Every action is POST + CSRF-protected and admin-only, the same discipline
 * as TaskAdminController. Every memory write goes through
 * {@see SessionMemoryStore} — the single writer — so the UI cannot drift
 * from the tools' rules: the caps, the revision history and the
 * provenance typing all apply identically.
 *
 * Memory writes are **not** task edits (§6.2): the task stays the immutable
 * constitution (SPEC §4.4); this changes runtime state, live, with no
 * approval gate. Edits take effect on the session's next request; nothing
 * mid-flight is rewritten.
 */
#[IsGranted('ROLE_ADMIN')]
final class SessionController extends AbstractController
{
    public function __construct(
        private readonly TaskRepository $tasks,
        private readonly SessionMemoryRepository $memories,
        private readonly SessionMemoryStore $store,
        private readonly SessionPresenter $presenter,
        private readonly TaskAdminService $admin,
        private readonly RunLauncher $launcher,
    ) {
    }

    #[Route('/tasks/{id}/session', name: 'app_session_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id): Response
    {
        $task = $this->sessionOr404($id);

        return $this->render('session/show.html.twig', [
            'task' => $task,
            'session' => $this->presenter->present($task),
        ]);
    }

    /**
     * Start or resume (§8): one control for both — enabling when paused,
     * then launching the slice the derived state owes. The launch goes
     * through RunLauncher, so it takes the exact queue path Run now does.
     */
    #[Route('/tasks/{id}/session/start', name: 'app_session_start', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function start(Request $request, int $id): Response
    {
        $this->assertCsrf('session-start', $request);
        $task = $this->sessionOr404($id);

        try {
            if (!$task->isEnabled()) {
                $task = $this->admin->enableTask($id);
            }

            $launch = $this->launcher->launch($task);
            $this->addFlash('ok', \sprintf('Slice queued — run #%d on the "%s" lane.', $launch->run->getId(), $launch->lane()));
        } catch (TaskLifecycleException|SessionConstraintException|ToolboxResolutionException|RunLaunchException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_session_show', ['id' => $id]);
    }

    /**
     * Pause (§8): the operator's switch off. The current slice finishes
     * quietly; no next slice launches. The same action the plain task
     * Disable is — sessions pause, they do not die mid-slice.
     */
    #[Route('/tasks/{id}/session/pause', name: 'app_session_pause', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function pause(Request $request, int $id): Response
    {
        $this->assertCsrf('session-pause', $request);

        try {
            $this->admin->disableTask($id);
            $this->addFlash('ok', 'Session paused — the current slice finishes on its own; no next slice will launch until you resume.');
        } catch (TaskLifecycleException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_session_show', ['id' => $id]);
    }

    /**
     * Enable a paused session without launching (§6.1's pause is reversible
     * mid-slice): the in-flight slice continues; the next one launches at
     * its milestone as usual.
     */
    #[Route('/tasks/{id}/session/enable', name: 'app_session_enable', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function enable(Request $request, int $id): Response
    {
        $this->assertCsrf('session-enable', $request);

        try {
            $this->admin->enableTask($id);
            $this->addFlash('ok', 'Session enabled.');
        } catch (TaskLifecycleException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_session_show', ['id' => $id]);
    }

    #[Route('/tasks/{id}/session/objective', name: 'app_session_objective', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function setObjective(Request $request, int $id): Response
    {
        $this->assertCsrf('session-objective', $request);
        $task = $this->sessionOr404($id);

        // The objective edit — the cleanest steer there is (§6.1). The store
        // keeps the superseded text, so it is visible and reversible.
        $this->writeMemory($request->request->getString('text'), fn (string $text) => $this->store->setObjective($task, $text, SessionMemorySource::Operator));

        return $this->redirectToRoute('app_session_show', ['id' => $id]);
    }

    #[Route('/tasks/{id}/session/notes', name: 'app_session_add_note', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function addNote(Request $request, int $id): Response
    {
        $this->assertCsrf('session-note-add', $request);
        $task = $this->sessionOr404($id);

        $this->writeMemory($request->request->getString('text'), fn (string $text) => $this->store->addNote($task, $text, SessionMemorySource::Operator));

        return $this->redirectToRoute('app_session_show', ['id' => $id]);
    }

    #[Route('/tasks/{id}/session/memory/{memoryId}/edit', name: 'app_session_memory_edit', methods: ['POST'], requirements: ['id' => '\d+', 'memoryId' => '\d+'])]
    public function editMemory(Request $request, int $id, int $memoryId): Response
    {
        $this->assertCsrf('session-memory-edit-'.$memoryId, $request);
        $task = $this->sessionOr404($id);
        $memory = $this->memoryOr404($task, $memoryId);

        $this->writeMemory($request->request->getString('text'), fn (string $text) => $this->store->editMemory($memory, $text, SessionMemorySource::Operator));

        return $this->redirectToRoute('app_session_show', ['id' => $id]);
    }

    #[Route('/tasks/{id}/session/memory/{memoryId}/delete', name: 'app_session_memory_delete', methods: ['POST'], requirements: ['id' => '\d+', 'memoryId' => '\d+'])]
    public function deleteMemory(Request $request, int $id, int $memoryId): Response
    {
        $this->assertCsrf('session-memory-delete-'.$memoryId, $request);
        $task = $this->sessionOr404($id);
        $memory = $this->memoryOr404($task, $memoryId);

        try {
            $this->store->deleteNote($memory);
            $this->addFlash('ok', 'Note deleted.');
        } catch (\LogicException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_session_show', ['id' => $id]);
    }

    #[Route('/tasks/{id}/session/memory/{memoryId}/pin', name: 'app_session_memory_pin', methods: ['POST'], requirements: ['id' => '\d+', 'memoryId' => '\d+'])]
    public function pinMemory(Request $request, int $id, int $memoryId): Response
    {
        $this->assertCsrf('session-memory-pin-'.$memoryId, $request);
        $task = $this->sessionOr404($id);
        $memory = $this->memoryOr404($task, $memoryId);

        $pinned = $request->request->getBoolean('pinned');

        try {
            $this->store->setPinned($memory, $pinned);
            $this->addFlash('ok', $pinned ? 'Note pinned hot — it is no longer demoted by ageing.' : 'Note unpinned — the next ageing pass may demote it.');
        } catch (\LogicException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_session_show', ['id' => $id]);
    }

    #[Route('/tasks/{id}/session/memory/{memoryId}/tier', name: 'app_session_memory_tier', methods: ['POST'], requirements: ['id' => '\d+', 'memoryId' => '\d+'])]
    public function moveTier(Request $request, int $id, int $memoryId): Response
    {
        $this->assertCsrf('session-memory-tier-'.$memoryId, $request);
        $task = $this->sessionOr404($id);
        $memory = $this->memoryOr404($task, $memoryId);

        $tier = SessionMemoryTier::tryFrom($request->request->getString('tier'));
        if (null === $tier) {
            $this->addFlash('error', 'Unknown tier — expected "hot" or "cold".');

            return $this->redirectToRoute('app_session_show', ['id' => $id]);
        }

        try {
            $this->store->setTier($memory, $tier);
            $this->addFlash('ok', SessionMemoryTier::Hot === $tier ? 'Note promoted hot — it is injected again.' : 'Note demoted cold — kept, not injected.');
        } catch (\LogicException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_session_show', ['id' => $id]);
    }

    #[Route('/tasks/{id}/session/memory/{memoryId}/revert/{revisionId}', name: 'app_session_memory_revert', methods: ['POST'], requirements: ['id' => '\d+', 'memoryId' => '\d+', 'revisionId' => '\d+'])]
    public function revertMemory(Request $request, int $id, int $memoryId, int $revisionId): Response
    {
        $this->assertCsrf('session-memory-revert-'.$memoryId.'-'.$revisionId, $request);
        $task = $this->sessionOr404($id);
        $memory = $this->memoryOr404($task, $memoryId);

        $revision = $this->memories->findRevisionForTask((int) $task->getId(), $memoryId, $revisionId);

        if (null === $revision) {
            $this->addFlash('error', \sprintf('No revision %d for that memory entry.', $revisionId));

            return $this->redirectToRoute('app_session_show', ['id' => $id]);
        }

        try {
            $this->store->revertToRevision($memory, $revision);
            $this->addFlash('ok', \sprintf('Reverted to revision %d — the replaced text is kept in the history.', $revision->getRevision()));
        } catch (\LogicException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_session_show', ['id' => $id]);
    }

    /**
     * Run one store write, translating the store's refusal into a flash —
     * the same refusal the tools feed back to the model (§3.3), rendered
     * here in the operator's vocabulary.
     */
    private function writeMemory(string $text, callable $write): void
    {
        try {
            $write($text);
            $this->addFlash('ok', 'Saved — it will ride the session\'s next request.');
        } catch (SessionMemoryWriteException $e) {
            $this->addFlash('error', $e->getMessage());
        }
    }

    private function sessionOr404(int $id): Task
    {
        $task = $this->tasks->find($id);
        if (!$task instanceof Task || TaskKind::Session !== $task->getKind()) {
            throw new NotFoundHttpException(\sprintf('No session with id %d.', $id));
        }

        return $task;
    }

    private function memoryOr404(Task $task, int $memoryId): SessionMemory
    {
        $memory = $this->memories->findOneForTask((int) $task->getId(), $memoryId);
        if (!$memory instanceof SessionMemory) {
            throw new NotFoundHttpException(\sprintf('No memory entry %d in session %d.', $memoryId, $task->getId()));
        }

        return $memory;
    }

    private function assertCsrf(string $intent, Request $request): void
    {
        if (!$this->isCsrfTokenValid($intent, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }
}

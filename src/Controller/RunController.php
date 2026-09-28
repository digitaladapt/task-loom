<?php

declare(strict_types=1);

namespace App\Controller;

use App\Admin\RunSurfacePresenter;
use App\Entity\ErrorClass;
use App\Entity\Run;
use App\Repository\RunRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The admin UI's run surface (SPEC §8): the run history with the per-run
 * attempt-ledger timeline (filterable by error class), the full transcript,
 * the completion artifact, the scheduler view (who holds the LLM slot,
 * who's queued), and the attention queue grouped by error class.
 *
 * Read-only — run triggers live elsewhere: the manual Run now action is a
 * task action (SPEC §8) on the task controller, and scheduled tasks fire
 * through the scheduler tick (SPEC §14).
 * Graph children are reached through their parent's run page (SPEC §13.6).
 */
#[IsGranted('ROLE_ADMIN')]
final class RunController extends AbstractController
{
    public function __construct(
        private readonly RunRepository $runs,
        private readonly RunSurfacePresenter $surface,
    ) {
    }

    #[Route('/runs', name: 'app_run_list', methods: ['GET'])]
    public function list(): Response
    {
        return $this->render('run/list.html.twig', [
            'view' => $this->surface->list(),
        ]);
    }

    #[Route('/runs/{id}', name: 'app_run_detail', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function detail(Request $request, int $id): Response
    {
        $run = $this->runs->find($id);
        if (!$run instanceof Run) {
            throw new NotFoundHttpException(\sprintf('No run with id %d.', $id));
        }

        // The error-class filter is a plain query parameter: an unknown
        // value is a 404 (the URL names a filter that does not exist),
        // not a silently-ignored typo.
        $filter = null;
        $raw = $request->query->getString('error_class');
        if ('' !== $raw) {
            $filter = ErrorClass::tryFrom($raw);
            if (null === $filter) {
                throw new NotFoundHttpException(\sprintf('No error class "%s".', $raw));
            }
        }

        return $this->render('run/detail.html.twig', [
            'view' => $this->surface->present($run, $filter),
        ]);
    }

    #[Route('/attention', name: 'app_attention', methods: ['GET'])]
    public function attention(): Response
    {
        return $this->render('run/attention.html.twig', [
            'groups' => $this->surface->attention(),
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Controller;

use App\Admin\ToolAdminService;
use App\Admin\ToolLifecycleException;
use App\Entity\Tool;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The tool-catalog admin surface (SPEC §8): the catalog view and per-tool
 * tag management. Tags are the human's routing surface — the only field
 * on a discovered tool a human should touch.
 */
#[IsGranted('ROLE_ADMIN')]
final class ToolAdminController extends AbstractController
{
    public function __construct(
        private readonly ToolAdminService $admin,
    ) {
    }

    #[Route('/tools', name: 'app_tool_list', methods: ['GET'])]
    public function list(): Response
    {
        return $this->render('tool/list.html.twig', [
            'tools' => $this->admin->listTools(),
            'known_tags' => $this->admin->listKnownTags(),
        ]);
    }

    #[Route('/tools/{id}/tags', name: 'app_tool_tags', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function setTags(Request $request, int $id): Response
    {
        // Existence before CSRF: a missing tool is a 404 regardless of the
        // form token — the token only vouches for an existing tool's form.
        if (null === $this->admin->findTool($id)) {
            throw $this->createNotFoundException(\sprintf('No tool with id %d.', $id));
        }

        if (!$this->isCsrfTokenValid('tool-tags', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $raw = $request->request->getString('tags', '');
        $tags = \array_filter(\array_map('trim', \explode(',', $raw)));

        try {
            $this->admin->setToolTags($id, $tags);
        } catch (ToolLifecycleException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('app_tool_list');
        }

        $this->addFlash('ok', \sprintf('Tags saved for tool %d.', $id));

        return $this->redirectToRoute('app_tool_list');
    }
}

<?php

declare(strict_types=1);

namespace App\Controller;

use App\Chat\ChatEngine;
use App\Entity\Chat;
use App\Entity\ChatExchangeStatus;
use App\Entity\ChatOrigin;
use App\Repository\ChatExchangeEventRepository;
use App\Repository\ChatExchangeRepository;
use App\Repository\ChatRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The chat surface (SPEC §15, §8): the conversation list, one conversation,
 * and saying something in it.
 *
 * ## Why a web page, and not ntfy or a bridge
 *
 * The surface decision came first in the design, ahead of any lane work,
 * because it decides what a "turn" is and where it comes from. A minimal web
 * chat wins on three counts that the alternatives lose on: it reuses the
 * stack already in the tree (Twig + FrankenPHP, one controller per area with
 * a `templates/<area>/` directory, the existing sign-in), the phone is where
 * this is used, and — the load-bearing one — **attribution stays ours**.
 * Adopting Matrix/Signal/IRC would import a homeserver's opinion about who
 * said what into the one place the safety invariant lives.
 *
 * ntfy is deliberately *not* the chat: it is a push-shaped notification
 * transport with no notion of a transcript, which is exactly what alerts
 * need and exactly what a conversation does not (SPEC §15).
 *
 * ## The read path, and the honest gap
 *
 * The page is a plain server-rendered transcript with a form. It is not
 * streaming: the reply arrives by the worker writing it and the page being
 * reloaded, which is the "deliberately ugly, just enough to have a real
 * conversation through" milestone the phasing calls for. Nothing here
 * forecloses the streaming reader — the transcript is a query, so a
 * streamed or polled read lands behind the same route without moving the
 * record.
 *
 * The one thing this surface must do that a run page does not is *confess*:
 * a run that fails is visible in the run history, and a chat that fails is a
 * person watching a blank space. So a failed exchange renders as a failure,
 * out loud.
 */
#[IsGranted('ROLE_ADMIN')]
final class ChatController extends AbstractController
{
    public function __construct(
        private readonly ChatEngine $engine,
        private readonly ChatRepository $chats,
        private readonly ChatExchangeRepository $exchanges,
        private readonly ChatExchangeEventRepository $events,
    ) {
    }

    #[Route('/chat', name: 'app_chat_list', methods: ['GET'])]
    public function list(): Response
    {
        return $this->render('chat/list.html.twig', [
            'chats' => $this->chats->findRecent(),
        ]);
    }

    #[Route('/chat/new', name: 'app_chat_new', methods: ['POST'])]
    public function create(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('chat-new', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $message = $request->request->getString('message');

        if ('' === trim($message)) {
            $this->addFlash('error', 'A first message needs some content.');

            return $this->redirectToRoute('app_chat_list');
        }

        $chat = $this->engine->start($message, ChatOrigin::Web);

        return $this->redirectToRoute('app_chat_show', ['id' => $chat->getId()]);
    }

    #[Route('/chat/{id}', name: 'app_chat_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id): Response
    {
        $chat = $this->requireChat($id);

        $current = $this->exchanges->findLatestForChat($chat);

        return $this->render('chat/show.html.twig', [
            'chat' => $chat,
            'transcript' => $this->events->findTranscript($chat),
            'pending' => null !== $current && !$current->isTerminal(),
            'lastError' => $this->lastFailure($chat),
        ]);
    }

    #[Route('/chat/{id}/say', name: 'app_chat_say', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function say(Request $request, int $id): Response
    {
        $chat = $this->requireChat($id);

        if (!$this->isCsrfTokenValid('chat-say-'.$id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $message = $request->request->getString('message');

        if ('' === trim($message)) {
            $this->addFlash('error', 'An empty message is not a turn.');

            return $this->redirectToRoute('app_chat_show', ['id' => $id]);
        }

        $this->engine->ask($chat, $message, ChatOrigin::Web);

        return $this->redirectToRoute('app_chat_show', ['id' => $id]);
    }

    private function requireChat(int $id): Chat
    {
        $chat = $this->chats->find($id);
        if (!$chat instanceof Chat) {
            throw new NotFoundHttpException(\sprintf('No conversation with id %d.', $id));
        }

        return $chat;
    }

    /**
     * The most recent failure in a conversation, if the latest exchange is
     * the one that failed.
     *
     * Surfaced rather than logged away: a chat turn that dies silently is the
     * one failure the run lanes have no precedent for, because the run lanes
     * always have somebody looking at a page. Here, the page *is* the only
     * thing that can tell the human.
     */
    private function lastFailure(Chat $chat): ?string
    {
        $latest = $this->exchanges->findLatestForChat($chat);

        if (null === $latest || ChatExchangeStatus::Failed !== $latest->getStatus()) {
            return null;
        }

        foreach ($this->events->findForExchange($latest) as $event) {
            if (null !== $event->getErrorClass()) {
                return \sprintf('%s — %s', $event->getErrorClass()->value, (string) ($event->getPayload()['reason'] ?? 'no reason recorded'));
            }
        }

        return 'the reply could not be produced';
    }
}

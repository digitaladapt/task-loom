<?php

declare(strict_types=1);

namespace App\Controller;

use App\Admin\ToolboxSelection;
use App\Chat\ChatEngine;
use App\Chat\ChatToolbox;
use App\Entity\Chat;
use App\Entity\ChatExchangeStatus;
use App\Entity\ChatOrigin;
use App\Entity\Tool;
use App\Entity\ToolboxMode;
use App\Repository\ChatExchangeEventRepository;
use App\Repository\ChatExchangeRepository;
use App\Repository\ChatRepository;
use App\RunEngine\ToolboxResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The chat surface (SPEC §15, §8): the conversation list, one conversation, and
 * saying something in it.
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
 *
 * ## The toolbox, and why the form is inert while a reply is pending
 *
 * Before each message the human picks the tools for the exchange that message
 * starts (`CHAT_TOOLS.md` §2). The picker is disabled while one is pending
 * rather than accepting a change it would have to ignore: the toolbox freezes
 * when the exchange starts, so a change mid-turn could not take effect, and a
 * control that silently does nothing is worse than one that is visibly off.
 */
#[IsGranted('ROLE_ADMIN')]
final class ChatController extends AbstractController
{
    public function __construct(
        private readonly ChatEngine $engine,
        private readonly ChatRepository $chats,
        private readonly ChatExchangeRepository $exchanges,
        private readonly ChatExchangeEventRepository $events,
        private readonly ToolboxResolver $toolboxes,
    ) {
    }

    #[Route('/chat', name: 'app_chat_list', methods: ['GET'])]
    public function list(): Response
    {
        return $this->render('chat/list.html.twig', [
            'chats' => $this->chats->findRecent(),
            'known_tags' => $this->knownTags(),
            'catalog_tools' => $this->catalogTools(),
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

        $selection = ToolboxSelection::parse($request->request->all());

        if (null !== $selection->error) {
            // Refused rather than defaulted: the toolbox is a permission
            // decision, and a submission nobody can parse is not one to guess
            // at. (The editor re-renders with the problem anchored; here the
            // safe reading is "you did not make a valid choice".)
            $this->addFlash('error', \sprintf('Toolbox: %s', $selection->error));

            return $this->redirectToRoute('app_chat_list');
        }

        $chat = $this->engine->start($message, ChatOrigin::Web, $this->resolve($selection, $request));

        return $this->redirectToRoute('app_chat_show', ['id' => $chat->getId()]);
    }

    #[Route('/chat/{id}', name: 'app_chat_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id): Response
    {
        $chat = $this->requireChat($id);

        $current = $this->exchanges->findLatestForChat($chat);
        $pending = null !== $current && !$current->isTerminal();

        // The picker reopens on the last exchange's declaration, always — so
        // what you see ticked is what you chose last time, and the answer to
        // "what can she do right now?" stays a thing on screen rather than a
        // thing to remember.
        //
        // Two cases fold into one line, and it is worth naming why. While a
        // reply is pending this is the *running* exchange, so the ticks show
        // the frozen set she is actually using. When nothing is pending it is
        // the last answered one, which pre-fills the next message.
        //
        // **Turning everything off is a choice, and it carries like any
        // other.** If the previous exchange ran with no tools, this renders
        // nothing ticked — which is the correct pre-fill, and is only
        // possible because the engine freezes an empty declaration rather
        // than leaving the column NULL (`ChatEngine::ask()`). A `null` here
        // would mean "no preference recorded", and the picker would fall back
        // to the template default rather than to your last answer.
        $toolboxValues = null !== $current
            ? ChatToolbox::fromExchange($current)->forPicker()
            : ChatToolbox::none(ToolboxMode::Tags)->forPicker();

        return $this->render('chat/show.html.twig', [
            'chat' => $chat,
            'transcript' => $this->events->findTranscript($chat),
            'pending' => $pending,
            'lastError' => $this->lastFailure($chat),
            'window_notice' => $this->windowNotice($chat),
            'used_tools' => null !== $current ? ChatToolbox::fromExchange($current)->toolNames() : [],
            'known_tags' => $this->knownTags(),
            'catalog_tools' => $this->catalogTools(),
            'toolbox_values' => $toolboxValues,
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

        $selection = ToolboxSelection::parse($request->request->all());

        if (null !== $selection->error) {
            $this->addFlash('error', \sprintf('Toolbox: %s', $selection->error));

            return $this->redirectToRoute('app_chat_show', ['id' => $id]);
        }

        $pending = $this->exchanges->findLatestForChat($chat);
        if (null !== $pending && !$pending->isTerminal()) {
            // The picker is disabled in this state, but a hand-rolled POST can
            // still arrive. Refusing is the honest answer: the toolbox froze
            // when the pending exchange started, so accepting a new one here
            // would be accepting a change that cannot take effect.
            $this->addFlash('error', 'She is still answering — the toolbox is fixed until this reply lands.');

            return $this->redirectToRoute('app_chat_show', ['id' => $id]);
        }

        $this->engine->ask($chat, $message, ChatOrigin::Web, $this->resolve($selection, $request));

        return $this->redirectToRoute('app_chat_show', ['id' => $id]);
    }

    /**
     * Turn a parsed selection into a frozen toolbox.
     *
     * Resolution reuses the run engine's resolver, so a chat's tags resolve
     * against the same catalog the same way a task's do — and, importantly, a
     * declaration that resolves to nothing is *not* an error here the way it is
     * for a task. A task with no tools cannot do the thing it exists for; a
     * conversation with no tools is the ordinary case, and one whose tags
     * match nothing today is most likely a tag about to be applied.
     *
     * A name the catalog does not carry is reported to the human rather than
     * silently dropped, because "I enabled the task tools" and "I typed
     * 'tsk-tools' and got nothing" should not look the same.
     */
    private function resolve(ToolboxSelection $selection, Request $request): ChatToolbox
    {
        if ([] === $selection->declared) {
            // Nothing ticked, which is an answer — and the *mode* is part of
            // it, so the picker reopens on the panel you were using rather
            // than on whichever one this class happens to default to.
            return ChatToolbox::none($selection->mode);
        }

        $resolved = $this->toolboxes->resolveChat($selection->mode, $selection->declared);

        if ([] === $resolved) {
            $this->addFlash(
                'error',
                \sprintf(
                    'Nothing in the catalog matches %s — this exchange has no tools.',
                    $this->describe($selection),
                ),
            );

            // The declaration is kept even though it resolved to nothing.
            // "I chose no tools" and "I chose these and they matched nothing"
            // are different states, and the second one is why the declaration
            // is stored at all: the picker reopens showing what was typed
            // rather than quietly forgetting it.
            return ChatToolbox::of($selection->mode, $selection->declared, []);
        }

        return ChatToolbox::of(
            $selection->mode,
            $selection->declared,
            \App\RunEngine\ToolboxSnapshot::fromDefinitions($resolved),
        );
    }

    private function describe(ToolboxSelection $selection): string
    {
        $quoted = array_map(static fn (string $entry): string => \sprintf('"%s"', $entry), $selection->declared);

        return \sprintf('%s %s', $selection->mode->value, implode(', ', $quoted));
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

    /**
     * A plain-words account of the model's window, when part of the
     * conversation is no longer in it (SPEC §15.9).
     *
     * The ledger records every trim, but the chat page has no timeline to
     * render it into — so the fact that matters most to a person is said
     * here, once, at the top: **the assistant can no longer see the start of
     * this conversation.** That is a different sentence from "the window was
     * trimmed", and it is the one a human needs, because it changes what it
     * makes sense to ask for without restating context.
     *
     * Null when the window has never trimmed — the ordinary case, and one
     * that must not be announced as if it were a problem.
     */
    private function windowNotice(Chat $chat): ?string
    {
        $trim = $this->events->findLatestContextTrim($chat);

        if (null === $trim) {
            return null;
        }

        $payload = $trim->getPayload();

        $droppedTurns = (int) ($payload['droppedTurns'] ?? 0);
        $droppedRounds = (int) ($payload['droppedRounds'] ?? 0);

        return \sprintf(
            'This conversation has grown past the model\'s window: it can no longer see the first %s of it. Only the most recent exchanges are in view, so restate anything it seems to have forgotten.',
            self::describeShed($droppedTurns, $droppedRounds),
        );
    }

    /** "N turn(s) and M tool round(s)", whichever parts are non-zero. */
    private static function describeShed(int $turns, int $rounds): string
    {
        $parts = [];

        if ($turns > 0) {
            $parts[] = \sprintf('%d turn%s', $turns, 1 === $turns ? '' : 's');
        }

        if ($rounds > 0) {
            $parts[] = \sprintf('%d tool round%s', $rounds, 1 === $rounds ? '' : 's');
        }

        return [] === $parts ? 'part' : implode(' and ', $parts);
    }

    /**
     * Tags the catalog carries, alphabetically — the picker's tag list. The
     * same source the task editor uses, so both offer the same vocabulary.
     *
     * @return list<string>
     */
    private function knownTags(): array
    {
        $tags = [];
        foreach ($this->catalogTools() as $tool) {
            foreach ($tool->getTags() as $tag) {
                $tags[$tag] = true;
            }
        }

        $tags = array_keys($tags);
        sort($tags);

        return $tags;
    }

    /**
     * Every tool in the catalog, in the canonical order the catalog page uses.
     *
     * @return list<Tool>
     */
    private function catalogTools(): array
    {
        // Via the resolver's repository rather than a second dependency: the
        // catalog has one definition (enabled tools on enabled servers, in
        // canonical order), and a picker that read it differently from a
        // resolution would offer choices that resolve to something else.
        return $this->toolboxes->catalog();
    }
}

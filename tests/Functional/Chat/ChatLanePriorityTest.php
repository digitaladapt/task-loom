<?php

declare(strict_types=1);

namespace App\Tests\Functional\Chat;

use App\Message\ChatReplyMessage;
use App\Message\LlmTurnMessage;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\EventListener\StopWorkerOnMessageLimitListener;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Worker;

/**
 * The chat lane as priority head (SPEC §15, §4.1) — the capacity half of the
 * design, and the one claim in it that is about someone else's code.
 *
 * ## The mechanism
 *
 * Chat is a lane on the same `messenger_messages` table, the LLM workers
 * consume `chat llm`, and `messenger:consume` is **strict-priority across its
 * receivers**: `Worker::run()` iterates the receivers in the order given and
 * `break`s on the first one that handled an envelope, every iteration. So
 * every existing LLM worker becomes chat-aware with no new process, no lock,
 * and no async runtime — *receiver order is consume order*.
 *
 * ## Why this test drives `Worker` directly
 *
 * Because `Worker` is where the priority lives, so the test has to exercise
 * `Worker` — not the config, and not a side effect. (The console command is a
 * thin wrapper around it, and driving *that* from a test is actively
 * misleading: the command's termination triggers Symfony's service reset, which
 * clears every `InMemoryTransport` — so a test that inspects the lanes after
 * the command returns sees them empty whatever the worker did.)
 *
 * The assertion is the **order the handlers were invoked**, which is the
 * property itself rather than a proxy for it: with `chat` listed first the
 * chat turn is handled first, and reversing the list reverses that. If
 * receiver order ever stopped being priority, a person waiting in a
 * conversation would silently go back to queuing behind every task turn, and
 * nothing else in the suite would notice.
 */
final class ChatLanePriorityTest extends TestCase
{
    public function testTheWorkerTakesTheChatTurnBeforeTheTaskTurn(): void
    {
        $handled = $this->consume(['chat', 'llm']);

        // One message per lane and a limit of one, so the worker handled
        // exactly one turn — and which one it was is the whole assertion.
        self::assertSame(
            [ChatReplyMessage::class],
            $handled,
            'the chat lane is the priority head: a waiting human is served before the next task turn',
        );
    }

    /**
     * And the ordering is the mechanism, not an accident of this test: the same
     * two messages with the lanes reversed produce the opposite result. Without
     * this, the test above would pass for a worker that happened to prefer
     * `chat` for some other reason.
     */
    public function testTheOrderOfTheLanesIsWhatDecides(): void
    {
        $handled = $this->consume(['llm', 'chat']);

        self::assertSame(
            [LlmTurnMessage::class],
            $handled,
            'with the run lane listed first the task turn goes first, leaving the chat turn waiting',
        );
    }

    /**
     * The fleet's own lane list, pinned end to end: the entrypoint starts its
     * LLM workers on `chat llm`, which is what makes every existing worker
     * chat-aware. This is the wiring half — the two tests above prove what the
     * order *does*, and this one proves the fleet actually passes that order.
     *
     * (It reads the entrypoint rather than running the fleet because the fleet
     * test belongs to the container suite, which drives the real script against
     * stub binaries — `EntrypointSupervisorTest` asserts the same string from
     * the run side.)
     */
    public function testTheEntrypointsLlmWorkersConsumeTheChatLaneFirst(): void
    {
        $entrypoint = (string) file_get_contents(\dirname(__DIR__, 3).'/docker/entrypoint.sh');

        self::assertMatchesRegularExpression(
            '/messenger:consume chat llm/u',
            $entrypoint,
            'the LLM workers must drain the chat lane first, or chat has no priority at all',
        );
    }

    /**
     * The lane exists and shares the run lanes' options.
     *
     * `redeliver_timeout` has to match for the same reason it does on the run
     * lanes: a redelivery must be able to take over a dead worker's claim, which
     * requires the claim to have gone stale first.
     */
    public function testTheChatLaneIsConfiguredLikeTheRunLanes(): void
    {
        $config = (string) file_get_contents(\dirname(__DIR__, 3).'/config/packages/messenger.yaml');

        // The chat transport's own block, rather than a count of strings
        // anywhere in the file — the file's comments talk about these options
        // too, so counting would pass or fail on prose.
        self::assertMatchesRegularExpression(
            '/^            chat:\n(?:                .*\n)*?                retry_strategy:\n                    max_retries: 0$/mu',
            $config,
            'the chat lane is a Doctrine transport with no Messenger retry: the engine owns failure semantics, and a chat that cannot be answered must fail loudly in front of a human',
        );

        self::assertMatchesRegularExpression(
            '/^            chat:$.*?redeliver_timeout: 7200/msu',
            $config,
            'the chat lane needs the same redelivery window as the run lanes, so a redelivery can take over a dead worker\'s claim',
        );

        self::assertMatchesRegularExpression(
            '/routing:\n(?:.*\n)*?            App\\\\Message\\\\ChatReplyMessage: chat$/mu',
            $config,
            'the reply message has to be routed to the chat lane for any of this to reach a conversation',
        );
    }

    /**
     * Drive Symfony's worker over the given lanes — in the given order, one
     * message in each — and report which turn it handled.
     *
     * @param list<string> $lanes lane names, highest priority first
     *
     * @return list<class-string>
     */
    private function consume(array $lanes): array
    {
        $handled = [];
        $receivers = [];

        foreach ($lanes as $name) {
            $transport = new InMemoryTransport();
            $message = 'chat' === $name
                ? new ChatReplyMessage(999_999, 999_999)
                : new LlmTurnMessage(999_999, 1);

            $transport->send(new Envelope($message));

            $receivers[$name] = $transport;
        }

        // The handlers are recorders, not the real ones: this test is about
        // which lane the worker reaches for first, not about what a handler
        // then does with a delivery.
        $bus = new MessageBus([
            new HandleMessageMiddleware(new HandlersLocator([
                ChatReplyMessage::class => [static function (ChatReplyMessage $m) use (&$handled): void {
                    $handled[] = $m::class;
                }],
                LlmTurnMessage::class => [static function (LlmTurnMessage $m) use (&$handled): void {
                    $handled[] = $m::class;
                }],
            ])),
        ]);

        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new StopWorkerOnMessageLimitListener(1, new NullLogger()));

        (new Worker($receivers, $bus, $dispatcher))->run(['sleep' => 1000]);

        return $handled;
    }
}

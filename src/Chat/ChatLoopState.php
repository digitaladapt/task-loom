<?php

declare(strict_types=1);

namespace App\Chat;

use App\RunEngine\PendingToolTurn;

/**
 * Mutable loop state for one exchange: how many tool rounds it has used, and
 * the tool work currently in flight.
 *
 * The run engine's `LoopState` counterpart, and deliberately not the same
 * class. A run's state carries budgets, a context window, and circuit-breaker
 * streaks; a conversation has none of that — what it needs is a *ceiling* on
 * the tool loop, because a person is waiting.
 *
 * Persisted as JSON in `chat_exchange.checkpoint`, so a fresh worker picks up
 * where the last one left off (SPEC §6: the next worker is a stranger, so
 * nothing here may require live objects).
 *
 * @internal
 */
final class ChatLoopState
{
    /** The env var the container reads; see defaultRoundLimit(). */
    public const string ROUND_LIMIT_ENV = 'TASKLOOM_CHAT_TOOL_ROUNDS';

    /** @param array<string, mixed> $data */
    public static function fromArray(?array $data): self
    {
        if (null === $data) {
            return new self(roundLimit: self::defaultRoundLimit());
        }

        $pending = $data['pendingToolTurn'] ?? null;

        return new self(
            rounds: (int) ($data['rounds'] ?? 0),
            roundLimit: max(0, (int) ($data['roundLimit'] ?? self::defaultRoundLimit())),
            pendingToolTurn: \is_array($pending) ? PendingToolTurn::fromArray($pending) : null,
        );
    }

    public function __construct(
        /** Assistant turns of this exchange that requested tools. */
        public int $rounds = 0,
        /** The ceiling on those, `TASKLOOM_CHAT_TOOL_ROUNDS` at exchange start. */
        public int $roundLimit = 6,
        public ?PendingToolTurn $pendingToolTurn = null,
    ) {
    }

    /**
     * The ceiling when nobody configured one.
     *
     * Six, and the number is a judgement about the *person*, not the model:
     * each round is a fresh generation, and chat is the priority head — so a
     * long loop is minutes of somebody watching a spinner while every task
     * waits behind them (SPEC §15.3). A reply needing more than six round
     * trips is usually a task wearing a conversation's clothes, which is
     * something to be told rather than waited out.
     */
    public static function defaultRoundLimit(): int
    {
        $value = getenv(self::ROUND_LIMIT_ENV);

        if (false === $value || '' === trim($value)) {
            return 6;
        }

        return max(0, (int) trim($value));
    }

    /**
     * Whether the loop has room for another tool round.
     *
     * A limit of 0 means "no tools at all": the ceiling is enforced before the
     * *first* round, so the setting is a way to switch chat tools off entirely
     * rather than a way to allow exactly zero rounds and then behave oddly.
     */
    public function canRunAnotherToolRound(): bool
    {
        return $this->rounds < $this->roundLimit;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'rounds' => $this->rounds,
            'roundLimit' => $this->roundLimit,
            'pendingToolTurn' => $this->pendingToolTurn?->toArray(),
        ];
    }
}

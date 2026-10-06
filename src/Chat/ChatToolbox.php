<?php

declare(strict_types=1);

namespace App\Chat;

use App\Entity\ChatExchange;
use App\Entity\Tool;
use App\Entity\ToolboxMode;

/**
 * The toolbox an exchange ran with (SPEC §15, `docs/design/CHAT_TOOLS.md` §2).
 *
 * A chat's toolbox is chosen by the human before each message and frozen for
 * that exchange — the same freeze a run's toolbox gets, scoped to the thing
 * that actually starts once. The value lives on the exchange as two JSON
 * columns:
 *
 * - **`toolbox_declaration`** — what the human chose (mode + the tag or tool
 *   names they picked). Kept because the editor must reopen showing *what was
 *   chosen*, including entries the catalog no longer carries; a resolution-on-
 *   read would silently rewrite the human's selection to whatever resolves
 *   today, which is how a declaration quietly loses a tool.
 * - **`toolbox_snapshot`** — the resolved tools, the run engine's snapshot
 *   shape verbatim (`App\RunEngine\ToolboxSnapshot`), so prompt compilation,
 *   tool descriptors and dispatch all read the same frozen thing a run does.
 *
 * **Why the snapshot is per exchange and not carried forward.** If enabling a
 * tool in one exchange silently applied to the next, the answer to "what can
 * she do right now?" would be a thing you have to remember rather than a thing
 * on screen. Carrying forward is the cheaper implementation and the worse
 * permission surface; §2.1 of the design note is the argument.
 */
final readonly class ChatToolbox
{
    /**
     * Public because a default parameter value has to be constructible from
     * outside: `ask(..., ChatToolbox $toolbox = new ChatToolbox())` is what
     * makes "no tools" the default without every caller passing one.
     *
     * @param list<string>               $declared
     * @param list<array<string, mixed>> $snapshot
     */
    public function __construct(
        public ToolboxMode $mode = ToolboxMode::Explicit,
        public array $declared = [],
        public array $snapshot = [],
    ) {
    }

    /**
     * A toolbox declaration with no tools: what an exchange gets when the
     * human picks nothing.
     *
     * Deliberately *not* an error, unlike a task's empty toolbox (which fails
     * at dispatch, because a task exists to do something and one with no tools
     * cannot). A conversation with no tools is the ordinary case — most of
     * them, probably — and it is exactly what `chat` was before this existed.
     */
    public static function none(): self
    {
        return new self();
    }

    /**
     * @param list<string>               $declared
     * @param list<array<string, mixed>> $snapshot
     */
    public static function of(ToolboxMode $mode, array $declared, array $snapshot): self
    {
        return new self($mode, $declared, $snapshot);
    }

    /**
     * What an exchange ran with, rebuilt from its stored columns.
     *
     * A null declaration reads as "no tools", which is also what a v1 exchange
     * (written before chat had a toolbox) means — so old exchanges keep
     * rendering and behaving exactly as they did.
     */
    public static function fromExchange(ChatExchange $exchange): self
    {
        $declaration = $exchange->getToolboxDeclaration();

        if (null === $declaration) {
            return self::none();
        }

        $declaration += ['mode' => '', 'declared' => []];

        return new self(
            ToolboxMode::tryFrom($declaration['mode']) ?? ToolboxMode::Explicit,
            array_values(array_filter($declaration['declared'], \is_string(...))),
            $exchange->getToolboxSnapshot() ?? [],
        );
    }

    /**
     * The declared entries, shaped for the picker: the same keys a task's
     * values carry, so the shared macro renders a chat's selection with no
     * chat-specific branch in it.
     *
     * @return array{toolbox_mode: string, toolbox: list<string>}
     */
    public function forPicker(): array
    {
        return [
            'toolbox_mode' => $this->mode->value,
            'toolbox' => $this->declared,
        ];
    }

    /**
     * The stored declaration.
     *
     * @return array{mode: string, declared: list<string>}
     */
    public function toDeclaration(): array
    {
        return ['mode' => $this->mode->value, 'declared' => $this->declared];
    }

    /**
     * The frozen tools, rebuilt for prompt compilation, tool descriptors and
     * dispatch — the run engine's snapshot reader, so a chat turn and a run
     * turn build the identical tool set from identical stored bytes.
     *
     * @return list<Tool>
     */
    public function tools(): array
    {
        return \App\RunEngine\ToolboxSnapshot::toTools($this->snapshot);
    }

    /**
     * Whether this toolbox gives the model no tools at all.
     *
     * Note this is about the *resolution*, not the declaration: a declaration
     * that resolved to nothing is empty by this test and still worth
     * remembering (`ChatEngine::ask()`). The two are deliberately separate
     * questions.
     */
    public function isEmpty(): bool
    {
        return [] === $this->snapshot;
    }

    /**
     * The tool names this exchange could call, for the record and the page.
     *
     * @return list<string>
     */
    public function toolNames(): array
    {
        return array_map(
            static fn (Tool $tool): string => $tool->getName(),
            $this->tools(),
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Admin;

use App\Entity\ToolboxMode;

/**
 * A toolbox declaration as a human submitted it (SPEC §4.1): which mode they
 * chose, and the list that mode declares.
 *
 * This is the one implementation of "how the picker's form fields become a
 * declaration", shared by the task editor and the chat surface — the same
 * widget (`templates/_toolbox.html.twig`) posts the same field names in both,
 * so parsing them in two places would be two chances to disagree about what a
 * checked box means.
 *
 * The two rules it exists to keep, both learned the hard way on the task side:
 *
 * - **Only the selected mode's list is honoured.** The inactive picker is
 *   hidden in the browser but stays in the DOM (so a mode switch never loses
 *   what was typed), which means the *other* list is still submitted. Reading
 *   only the selected one is what stops a stale checkbox leaking into the
 *   saved declaration.
 * - **Each list has a free-text companion.** The catalog is discovered, not
 *   authoritative, so a human must be able to declare a tag or tool it does
 *   not carry yet. Merging the checkbox list with the typed list, deduped and
 *   order-preserving, is that.
 */
final readonly class ToolboxSelection
{
    /**
     * @param list<string> $declared
     */
    private function __construct(
        public ToolboxMode $mode,
        public array $declared,
        public ?string $error = null,
    ) {
    }

    /**
     * Parse a submitted toolbox.
     *
     * Never throws and never silently repairs: an unparseable mode yields the
     * default *plus* an error, so the caller decides whether to refuse (the
     * chat surface does) or re-render with the problem anchored (the editor
     * does) — but neither can end up with a declaration the human did not
     * make.
     *
     * @param array<string, mixed> $input the submitted form fields
     * @param string               $key   the error key to report under ('toolbox' for a task, 'chat_toolbox' for a chat)
     */
    public static function parse(array $input, string $key = 'toolbox'): self
    {
        $raw = self::text($input['toolbox_mode'] ?? ToolboxMode::Tags->value);
        $mode = ToolboxMode::tryFrom($raw);

        if (null === $mode) {
            // The mode is not a value the picker can produce, so the safest
            // reading is "no declaration": default to tags and say so. An
            // attacker-chosen mode value must never widen what resolves —
            // and since only the *selected* mode's list is read, an unknown
            // mode reads neither list.
            return new self(
                ToolboxMode::Tags,
                [],
                \sprintf('Unknown toolbox mode "%s".', $raw),
            );
        }

        $declared = ToolboxMode::Explicit === $mode
            ? self::mergeEntries($input['toolbox_tools'] ?? [], $input['toolbox_tools_extra'] ?? '')
            : self::mergeEntries($input['toolbox_tags'] ?? [], $input['toolbox_tags_extra'] ?? '');

        return new self($mode, $declared, null);
    }

    /**
     * The submitted checkbox list merged with the comma-separated free-text
     * companion: trimmed, empties dropped, duplicates collapsed, order
     * preserved.
     *
     * @return list<string>
     */
    public static function mergeEntries(mixed $checked, mixed $typed): array
    {
        $list = [];
        foreach (self::stringList($checked) as $item) {
            $list[$item] = true;
        }

        if (\is_scalar($typed)) {
            foreach (explode(',', (string) $typed) as $item) {
                $item = trim($item);
                if ('' !== $item) {
                    $list[$item] = true;
                }
            }
        }

        return array_keys($list);
    }

    private static function text(mixed $value): string
    {
        return \is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * A submitted checkbox list: scalars only, trimmed, empties dropped,
     * duplicates collapsed, order preserved.
     *
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }

        $list = [];
        foreach ($value as $item) {
            if (!\is_scalar($item)) {
                continue;
            }
            $item = trim((string) $item);
            if ('' !== $item) {
                $list[] = $item;
            }
        }

        return $list;
    }
}

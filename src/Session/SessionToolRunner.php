<?php

declare(strict_types=1);

namespace App\Session;

use App\Entity\SessionMemorySource;
use App\Entity\Task;

/**
 * Runs the harness's session tools in-process (docs/design/SESSION_TASKS.md
 * §5, build order step 3) — the write half of the pair whose read half is the
 * `## Memories` block.
 *
 * Every call lands in {@see SessionMemoryStore} — the single writer — so the
 * tools inherit the store's caps, ageing, provenance and history without
 * knowing any of them: the enum is the model's *vocabulary*, the store is the
 * *rules*. A refused write (over the per-write cap, blank) surfaces as
 * {@see SessionMemoryWriteException}; the engine turns that into ordinary
 * error feedback, exactly as it does a refused MCP call, because a note too
 * long to store is the model's to fix — not a server fault to retry.
 *
 * Everything written here is `SessionMemorySource::Session` — a recollection,
 * rendered `[you]` and explicitly not-to-be-followed in the block (§6.3).
 * Operator entries come from the UI (step 6) and never from these tools.
 */
final readonly class SessionToolRunner
{
    public function __construct(
        private SessionMemoryStore $store,
    ) {
    }

    /**
     * Run one harness tool call and return the result content the model
     * receives — a small JSON object, the same kind of thing an MCP server's
     * result is, so the tool turn treats both identically.
     *
     * @param array<string, mixed> $arguments schema-validated by the caller
     *
     * @throws SessionMemoryWriteException when the store refuses the write —
     *                                     the engine feeds it back, no retry
     */
    public function call(SessionTool $tool, array $arguments, Task $task): string
    {
        // The frozen schema guarantees a string; the defensive read keeps a
        // hand-built call (a test, a repaired snapshot) from crashing on
        // something the store would refuse anyway — it gets the store's
        // blank-write refusal, naming the actual problem.
        $text = \is_string($arguments['text'] ?? null) ? $arguments['text'] : '';
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

        if (SessionTool::Note === $tool) {
            $this->store->addNote($task, $text, SessionMemorySource::Session);

            return (string) json_encode(['saved' => true, 'kind' => 'note'], $flags);
        }

        $objective = $this->store->setObjective($task, $text, SessionMemorySource::Session);

        return (string) json_encode(['saved' => true, 'kind' => 'objective', 'revision' => $objective->getRevision()], $flags);
    }
}

<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `stream_event`: the being's append-only stream
 * (docs/design/THE_STREAM.md §2.1 — build step 1).
 *
 * ## Why this table exists at all
 *
 * Continuity currently has no home. A session's slices each build a fresh
 * `LoopState` with an empty `exchanges` array, so nothing carries across them
 * except the `## Memories` block — which existed *because* a context could not
 * persist. And a run's checkpoint stores `exchanges` as JSON, so the transcript
 * dies with the run that wrote it.
 *
 * This is the table that replaces both. A turn is a row. The window is a view
 * onto the tail rather than a copy in a checkpoint, and a run boundary stops
 * being a memory boundary. Nothing here is per-surface: chat is another writer,
 * not another stream.
 *
 * ## The shape, and why it is flat
 *
 * `author`/`role`/`content` are columns, not a payload. The wire protocol is
 * the reason the table exists, and the protocol's message *is*
 * `{role, content, tool_calls, tool_call_id}` — putting that behind a JSON blob
 * makes every read a decode and every ordering question a guess. What is nested
 * in the protocol stays nested here: `tool_calls` is the assistant turn's calls
 * and `tool_results` answers them, both verbatim.
 *
 * `content` is `CLOB` and deliberately uncapped. The cap on what the model sees
 * has moved to render time; that move is only honest while the stored row keeps
 * the whole result, and phase 2's compaction has nothing to summarise without
 * it.
 *
 * ## `seq`, and why it is unique
 *
 * Ordering is a promise about causality — a tool result never precedes its
 * call — and `created_at` cannot keep it: timestamps collide, and clocks move.
 * `seq` is assigned on append from `MAX(seq)+1`, which is safe because SQLite
 * serialises writers. `UNIQUE` is what makes a second writer's mistake an
 * error rather than a silently reordered history.
 *
 * ## Nullable provenance, `SET NULL` on both
 *
 * `run_id` and `exchange_id` say which surface wrote a row. They are not
 * ownership: the stream outlives any run, and deleting a task or a conversation
 * must not quietly rewrite her memory. `SET NULL` keeps the row and drops the
 * attribution — the honest version of "that came from something that is gone".
 *
 * The `chat` rebuild Doctrine emits alongside this is omitted deliberately: it
 * recreates the table with the same definition and exists only because SQLite's
 * introspection does not round-trip the `ON DELETE` clause. It would rewrite a
 * live table to change nothing.
 */
final class Version20261023180416 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'stream_event: the being\'s append-only stream — turns that outlive a run (docs/design/THE_STREAM.md §2.1).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE stream_event (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, seq INTEGER NOT NULL, author VARCHAR(16) NOT NULL, role VARCHAR(16) NOT NULL, content CLOB NOT NULL, tool_calls CLOB NOT NULL, tool_results CLOB NOT NULL, created_at DATETIME NOT NULL, run_id INTEGER DEFAULT NULL, exchange_id INTEGER DEFAULT NULL, CONSTRAINT FK_74C6EFCC84E3FEC4 FOREIGN KEY (run_id) REFERENCES run (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_74C6EFCC68AFD1A0 FOREIGN KEY (exchange_id) REFERENCES chat_exchange (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_STREAM_EVENT_RUN ON stream_event (run_id)');
        $this->addSql('CREATE INDEX IDX_STREAM_EVENT_EXCHANGE ON stream_event (exchange_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_STREAM_EVENT_SEQ ON stream_event (seq)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE stream_event');
    }
}

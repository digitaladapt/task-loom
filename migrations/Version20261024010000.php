<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `being`: the stream gets an owner, and her heads and watermark get a home
 * (docs/design/THE_STREAM.md §2.2 — build step 3).
 *
 * ## What was wrong before this
 *
 * Step 1 gave `stream_event` no owner, which was defensible while there was one
 * agent and the table was global: `seq` was globally unique and every read was
 * "the newest N rows". It stops being defensible at exactly the point this
 * migration addresses — the moment something has to persist *about her* rather
 * than about what she said. Two such facts exist:
 *
 * - `contextBase`, the trim watermark. It lived in `Run::$checkpoint`, so it
 *   died with the run. Every new run started with no watermark, re-derived one,
 *   and re-prefilled the context it had already paid for. Persisting it is what
 *   finally makes the batch shedding amortise; before this, "sticky" meant
 *   sticky for one run, which is not the timeline anyone cares about.
 * - `promptHead`, the compiled head. Recompiled identically on every run —
 *   same title, same brief, same toolbox, same completion text — so the
 *   server's prefix cache had nothing stable to key on across run boundaries.
 *
 * ## Why `seq` uniqueness moves to `(being_id, seq)`
 *
 * One number line per being, not one for the deployment. `seq` is contiguous
 * from 1 inside a stream, and that property is what makes "how many rows" and
 * "what was the last seq" one question — it cannot survive two independent
 * streams sharing a counter. The old `UNIQ_STREAM_EVENT_SEQ` is dropped rather
 * than kept alongside: a global unique index would force a second being's first
 * row to continue the first being's numbering, leaving her stream full of holes.
 *
 * ## The backfill, and why it is safe here
 *
 * `stream_event.being_id` is not nullable in the entity, so existing rows need a
 * being before the column can be declared `NOT NULL`. That is a plain backfill
 * to the single being this migration creates — every row currently in the table
 * was written by her, because there was nobody else to write it.
 *
 * The table is created with the column nullable, the rows are pointed at the new
 * being, and only then is a second `CREATE TABLE` issued for the non-null
 * definition. SQLite has no `ALTER COLUMN`, so a rebuild is the only way to
 * tighten a nullability that was just widened — the same reason the previous
 * migration omitted Doctrine's spurious `chat` rebuild while keeping the ones
 * that change something. The rebuild preserves ids, so provenance and `seq`
 * survive it untouched.
 *
 * ## A correction carried in this migration's docblock
 *
 * The previous migration's comment argued that `MAX(seq)+1` was safe "because
 * SQLite serialises writers". That is false, and it has now been verified false
 * (THE_STREAM.md §2.7): two connections read the same maximum, both insert it,
 * and the second dies on the unique index. The claim is being corrected at the
 * source rather than quietly inherited, because a docblock that reassures the
 * next reader is worse than no docblock.
 */
final class Version20261024010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'being: the stream gets an owner; her frozen heads and trim watermark get a home (docs/design/THE_STREAM.md §2.2).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE being (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, grounding_at DATETIME DEFAULT NULL, context_base INTEGER DEFAULT NULL, context_batch BOOLEAN NOT NULL, heads CLOB NOT NULL, created_at DATETIME NOT NULL)');

        // The one being. Existing stream rows were all hers; there was nobody
        // else who could have written them.
        $this->addSql("INSERT INTO being (grounding_at, context_base, context_batch, heads, created_at) VALUES (NULL, NULL, 0, '{}', CURRENT_TIMESTAMP)");

        // Widen, backfill, tighten. The intermediate nullable definition exists
        // only so the UPDATE below can run without violating NOT NULL.
        $this->addSql('CREATE TABLE stream_event_tmp (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, seq INTEGER NOT NULL, author VARCHAR(16) NOT NULL, role VARCHAR(16) NOT NULL, content CLOB NOT NULL, tool_calls CLOB NOT NULL, tool_results CLOB NOT NULL, created_at DATETIME NOT NULL, being_id INTEGER NOT NULL, run_id INTEGER DEFAULT NULL, exchange_id INTEGER DEFAULT NULL, CONSTRAINT FK_STREAM_EVENT_BEING FOREIGN KEY (being_id) REFERENCES being (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_74C6EFCC84E3FEC4 FOREIGN KEY (run_id) REFERENCES run (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_74C6EFCC68AFD1A0 FOREIGN KEY (exchange_id) REFERENCES chat_exchange (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');

        $this->addSql('INSERT INTO stream_event_tmp (id, seq, author, role, content, tool_calls, tool_results, created_at, being_id, run_id, exchange_id) SELECT id, seq, author, role, content, tool_calls, tool_results, created_at, (SELECT id FROM being ORDER BY id ASC LIMIT 1), run_id, exchange_id FROM stream_event');

        $this->addSql('DROP TABLE stream_event');
        $this->addSql('ALTER TABLE stream_event_tmp RENAME TO stream_event');

        // One number line per being: the old global constraint is replaced, not
        // kept beside the new one (see the docblock).
        $this->addSql('CREATE UNIQUE INDEX UNIQ_STREAM_EVENT_BEING_SEQ ON stream_event (being_id, seq)');
        $this->addSql('CREATE INDEX IDX_STREAM_EVENT_RUN ON stream_event (run_id)');
        $this->addSql('CREATE INDEX IDX_STREAM_EVENT_EXCHANGE ON stream_event (exchange_id)');
    }

    public function down(Schema $schema): void
    {
        // Back to the global shape. Rows from several beings would collide on
        // the restored global unique index; there is only ever one being today,
        // so the honest downgrade is the rebuild plus the old constraint.
        $this->addSql('CREATE TABLE stream_event_tmp (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, seq INTEGER NOT NULL, author VARCHAR(16) NOT NULL, role VARCHAR(16) NOT NULL, content CLOB NOT NULL, tool_calls CLOB NOT NULL, tool_results CLOB NOT NULL, created_at DATETIME NOT NULL, run_id INTEGER DEFAULT NULL, exchange_id INTEGER DEFAULT NULL, CONSTRAINT FK_74C6EFCC84E3FEC4 FOREIGN KEY (run_id) REFERENCES run (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_74C6EFCC68AFD1A0 FOREIGN KEY (exchange_id) REFERENCES chat_exchange (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');

        $this->addSql('INSERT INTO stream_event_tmp (id, seq, author, role, content, tool_calls, tool_results, created_at, run_id, exchange_id) SELECT id, seq, author, role, content, tool_calls, tool_results, created_at, run_id, exchange_id FROM stream_event');

        $this->addSql('DROP TABLE stream_event');
        $this->addSql('ALTER TABLE stream_event_tmp RENAME TO stream_event');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_STREAM_EVENT_SEQ ON stream_event (seq)');
        $this->addSql('CREATE INDEX IDX_STREAM_EVENT_RUN ON stream_event (run_id)');
        $this->addSql('CREATE INDEX IDX_STREAM_EVENT_EXCHANGE ON stream_event (exchange_id)');

        $this->addSql('DROP TABLE being');
    }
}

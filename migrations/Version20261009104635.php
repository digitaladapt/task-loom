<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The session memory store: session_memory + session_memory_revision
 * (docs/design/SESSION_TASKS.md §3, build order step 2).
 *
 * Two tables, and neither carries a key. That is the design decision this
 * migration records: a session's store serves exactly one session, so the
 * task id is the whole namespace (§3.1) — a key column would be a second
 * dimension with no consumer.
 *
 * - **`session_memory`** holds both shapes the store carries, told apart by
 *   `kind`: the singular **objective** (always injected, never aged, not in
 *   the tier system — hence `tier` NULL on its row) and the plural **notes**
 *   (`tier` hot/cold, `pinned` for an operator-kept hot note). `source` is a
 *   typed column rather than a sentence in the text because there are two
 *   writers whose text is not the same thing (§6.3): operator entries are
 *   directives, session entries are recollections. `revision` bumps on every
 *   write.
 *
 * - **`session_memory_revision`** is the append-only history: one row per
 *   text an edit replaced, carrying the revision it belonged to and its
 *   provenance. It exists so steering is visible and reversible — the
 *   objective is "last writer wins, *visibly*" (§6.3).
 *
 * The `(task_id, kind)` index is the read path: the injector fetches the
 * objective and the hot notes for one session on every request (§4.1), and
 * the store asks for the same pair to age the tier. `text` is CLOB because a
 * note is prose; its size is bounded by `TASKLOOM_SESSION_WRITE_MAX_CHARS`
 * at the store, not by the column — the store is the gate (§3.3).
 *
 * Both foreign keys cascade, and the revision table's cascade is load-bearing
 * twice over: a dropped cold note takes its history with it (history of a
 * thing that no longer exists would be the unbounded store this table must
 * not be), and a deleted task takes its session's entire memory. Note that
 * SQLite does not enforce foreign keys without the `PRAGMA` (see
 * TaskCrud::sweepRefsTo for the same caveat) — so the store drops revisions
 * explicitly where it relies on this, rather than leaning on the constraint.
 */
final class Version20261009104635 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'session_memory + session_memory_revision: a session\'s objective and capped notes, with their replacement history (docs/design/SESSION_TASKS.md §3).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE session_memory (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, kind VARCHAR(16) NOT NULL, text CLOB NOT NULL, source VARCHAR(16) NOT NULL, tier VARCHAR(16) DEFAULT NULL, pinned BOOLEAN DEFAULT 0 NOT NULL, revision INTEGER DEFAULT 1 NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, task_id INTEGER NOT NULL, CONSTRAINT FK_85063E6C8DB60186 FOREIGN KEY (task_id) REFERENCES task (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX idx_session_memory_task_kind ON session_memory (task_id, kind)');
        $this->addSql('CREATE INDEX IDX_85063E6C8DB60186 ON session_memory (task_id)');

        $this->addSql('CREATE TABLE session_memory_revision (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, revision INTEGER NOT NULL, text CLOB NOT NULL, source VARCHAR(16) NOT NULL, saved_at DATETIME NOT NULL, memory_id INTEGER NOT NULL, CONSTRAINT FK_441ED302CCC80CB3 FOREIGN KEY (memory_id) REFERENCES session_memory (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX idx_session_memory_revision_memory ON session_memory_revision (memory_id)');
    }

    public function down(Schema $schema): void
    {
        // Children first: the revision table points at the memory table, and
        // dropping in dependency order keeps the intent legible even where
        // the database would allow either order.
        $this->addSql('DROP TABLE session_memory_revision');
        $this->addSql('DROP TABLE session_memory');
    }
}

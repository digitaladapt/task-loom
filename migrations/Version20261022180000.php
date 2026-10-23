<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `chat.session_id`: a conversation can be *about* a session — the one context
 * (docs/design/SESSION_TASKS.md §8.2, "What this implies for a chat surface").
 *
 * ## Why a link and not a fourth noun
 *
 * The two channels already have their own nouns (SPEC §15: `Chat` →
 * `ChatExchange` → `ChatExchangeEvent`; SPEC §9: `Task` → `Run`), and every
 * attempt to make one model absorb the other has failed for a stated reason — a
 * chat exchange is deliberately not a `Run` (`ChatExchange`'s docblock argues
 * that against the code) and a session slice is deliberately not an exchange
 * (it declares justified completion; a conversation never does). What was
 * missing was never a shared table: it was the statement that **some
 * conversations are about a session**, which is one nullable column and no
 * change to either lifecycle.
 *
 * ## What it points at
 *
 * The row that was current when the binding was made — and a session outlives
 * its task rows (§8.2), so a chat bound to one version would lose its subject
 * on the next approved edit. The reading side therefore follows the chain to
 * its live end ({@see App\Repository\TaskRepository::liveEndOfChain()}).
 * Following on read rather than rewriting on approval is deliberate: approval
 * already carries that session's history, standing and memory, and a pointer
 * that resolves correctly on read is cheaper and safer than one more write that
 * must be remembered in one more place.
 *
 * Nullable, and null is the normal state: a chat that is not about a session is
 * the ordinary chat, and every conversation written before this migration is
 * one. No backfill, because there is nothing to backfill from.
 *
 * ## Why the column arrives by rebuilding the table
 *
 * SQLite cannot add a column and its foreign key in one step: `ALTER TABLE ...
 * ADD COLUMN` accepts no constraint that references another table, and the FK is
 * the part that carries the meaning here. So this is the same temporary-table
 * dance Doctrine emits for an added relation (`chat` has few enough rows and no
 * inbound references, which is what makes it safe). The `CREATE TABLE`/`INSERT`/
 * `DROP` are Doctrine's own output for this model, verbatim, so
 * `doctrine:migrations:diff` has nothing left to say.
 *
 * ## `ON DELETE SET NULL`, deliberately, not `CASCADE`
 *
 * Deleting a task must not delete the conversations about it. `chat` is one of
 * only two tables here with no inbound references — the other being the whole
 * transcript hanging off it (`ChatExchange`, `ChatExchangeEvent`, both cascade
 * *downstream*) — so a cascade from `task` would not just unlink a chat, it would
 * destroy every exchange and event in it. The human's own typed questions would
 * go with a row whose deletion had nothing to do with them.
 *
 * `SET NULL` says the honest thing instead: the conversation outlives its
 * subject and becomes an ordinary chat, which is the state this column already
 * treats as normal. Nothing removes tasks today (`TaskRepository::remove()` has
 * no caller), so this is the constraint being right before the first caller
 * arrives, rather than a decision that can wait for it.
 */
final class Version20261022180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'chat.session_id: a conversation can be about a session — the link that makes a chat a second way into the one context (docs/design/SESSION_TASKS.md §8.2).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TEMPORARY TABLE __temp__chat AS SELECT id, title, created_at, updated_at, grounding_at, context_base, context_batch FROM chat');
        $this->addSql('DROP TABLE chat');
        $this->addSql('CREATE TABLE chat (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, title VARCHAR(200) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, grounding_at DATETIME DEFAULT NULL, context_base INTEGER DEFAULT NULL, context_batch BOOLEAN DEFAULT 0 NOT NULL, session_id INTEGER DEFAULT NULL, CONSTRAINT FK_659DF2AA613FECDF FOREIGN KEY (session_id) REFERENCES task (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO chat (id, title, created_at, updated_at, grounding_at, context_base, context_batch) SELECT id, title, created_at, updated_at, grounding_at, context_base, context_batch FROM __temp__chat');
        $this->addSql('DROP TABLE __temp__chat');
        $this->addSql('CREATE INDEX IDX_659DF2AA613FECDF ON chat (session_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE TEMPORARY TABLE __temp__chat AS SELECT id, title, created_at, updated_at, grounding_at, context_base, context_batch FROM chat');
        $this->addSql('DROP TABLE chat');
        $this->addSql('CREATE TABLE chat (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, title VARCHAR(200) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, grounding_at DATETIME DEFAULT NULL, context_base INTEGER DEFAULT NULL, context_batch BOOLEAN NOT NULL DEFAULT 0)');
        $this->addSql('INSERT INTO chat (id, title, created_at, updated_at, grounding_at, context_base, context_batch) SELECT id, title, created_at, updated_at, grounding_at, context_base, context_batch FROM __temp__chat');
        $this->addSql('DROP TABLE __temp__chat');
    }
}

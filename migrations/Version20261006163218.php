<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The chat exchange's toolbox: what the human chose, and what it resolved to
 * (SPEC §15, `docs/design/CHAT_TOOLS.md`).
 *
 * Two columns, and the reason there are two rather than one:
 *
 * - **`toolbox_declaration`** is what the human picked (mode + tag or tool
 *   names). It is stored so the picker can reopen showing *their* choice —
 *   including an entry the catalog no longer carries. Resolving on read
 *   instead would silently rewrite a selection to whatever happens to resolve
 *   today, which is how a declared tool quietly disappears from a task.
 * - **`toolbox_snapshot`** is the resolved set, frozen in the run engine's
 *   snapshot shape. Frozen because chat can now *act*, and the property that
 *   makes acting safe is the one the run engine already relies on: the tool
 *   definitions on the wire, the toolbox in the prompt, and the map dispatch
 *   consults are one set. A conversation could otherwise change its tools
 *   mid-turn, and a tool outside the declared set would have a path to
 *   dispatch.
 *
 * Both nullable, and the null is meaningful: it reads as "no tools", which is
 * also what every exchange written before chat had a toolbox means. Rows
 * predating this migration therefore keep behaving and rendering exactly as
 * they did — no backfill, and no exchange that suddenly grew permissions.
 */
final class Version20261006163218 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'chat_exchange.toolbox_declaration + toolbox_snapshot: a conversation\'s per-exchange toolbox (SPEC §15).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE chat_exchange ADD COLUMN toolbox_declaration CLOB DEFAULT NULL');
        $this->addSql('ALTER TABLE chat_exchange ADD COLUMN toolbox_snapshot CLOB DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // SQLite cannot drop columns in place, so the table is rebuilt. The
        // copy lists every column being kept *explicitly* — a `SELECT *` here
        // is how a future column silently vanishes from a rollback.
        $this->addSql('CREATE TEMPORARY TABLE __temp__chat_exchange AS SELECT id, triggered_by, status, checkpoint, lock_version, claimed_at, claim_fleet, started_at, finished_at, error_class, chat_id FROM chat_exchange');
        $this->addSql('DROP TABLE chat_exchange');
        $this->addSql('CREATE TABLE chat_exchange (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, triggered_by VARCHAR(16) DEFAULT \'inbound\' NOT NULL, status VARCHAR(16) NOT NULL, checkpoint CLOB DEFAULT NULL, lock_version INTEGER DEFAULT 0 NOT NULL, claimed_at INTEGER DEFAULT NULL, claim_fleet VARCHAR(32) DEFAULT NULL, started_at DATETIME DEFAULT NULL, finished_at DATETIME DEFAULT NULL, error_class VARCHAR(32) DEFAULT NULL, chat_id INTEGER NOT NULL, CONSTRAINT FK_E71967491A9A7125 FOREIGN KEY (chat_id) REFERENCES chat (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO chat_exchange (id, triggered_by, status, checkpoint, lock_version, claimed_at, claim_fleet, started_at, finished_at, error_class, chat_id) SELECT id, triggered_by, status, checkpoint, lock_version, claimed_at, claim_fleet, started_at, finished_at, error_class, chat_id FROM __temp__chat_exchange');
        $this->addSql('DROP TABLE __temp__chat_exchange');
        $this->addSql('CREATE INDEX idx_chat_exchange_chat ON chat_exchange (chat_id)');
        $this->addSql('CREATE INDEX idx_chat_exchange_status ON chat_exchange (status)');
    }
}

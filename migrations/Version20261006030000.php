<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The chat aggregate: chat, chat_exchange, chat_exchange_event (SPEC §15).
 *
 * Three tables, and no fourth. The design note considered and rejected a
 * separate `chat_turn` table twice over: a turn is the conversational row of
 * an event, and a second table would duplicate rows that already exist and
 * give one more thing to keep in step. The layout here is the point —
 * `chat_exchange_event` carries the same machinery columns as `run_event`
 * (`seq`, `created_at`, `payload`, `error_class`, `attempt_no`, `duration_ms`)
 * because both extend `App\Entity\LedgerEvent`, so "debug a chat turn like a
 * task turn" is true by construction rather than by two authors remembering.
 *
 * What is deliberately NOT shared with `run`:
 *
 * - **No `task_id`.** A chat exchange is not a task's execution, and the
 *   alternative — a synthetic "Internal: Chat" task row — would put a lie at
 *   the centre of the schema and leak into the task admin surface.
 * - **No `role`, `parent_id`, `step_id`, `toolbox_snapshot`.** Those are the
 *   step model's vocabulary; a conversation has no graph and no frozen
 *   toolbox.
 * - **A four-state status, not `RunStatus`.** `answered` and `failed`, not
 *   `succeeded`/`incomplete`/`needs_attention`/`paused`: a conversation never
 *   declares justified completion, so the run's terminal semantics would be a
 *   lie here.
 *
 * `triggered_by` exists with `inbound` as its only value. That is the whole
 * point of it being modelled now: v1 is respond-only, and the one design
 * decision that would make initiation hard later is a non-null FK saying "an
 * exchange always has a triggering inbound turn". As a column with a closed
 * enum it is a value, so the assistant opening a conversation later is a new
 * case rather than a migration.
 *
 * The claim columns (`lock_version`, `claimed_at`, `claim_fleet`) are the
 * run's, verbatim, because the claim protocol is shared machinery rather than
 * shared tables (`App\Claims\ClaimStore`): a chat exchange is claimed for one
 * message exactly as a run is, and boot recovery sweeps it for the same
 * reason — an exchange abandoned by a killed fleet has a human waiting on the
 * other end of it.
 */
final class Version20261006030000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Chat aggregate: chat, chat_exchange, chat_exchange_event (SPEC §15).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE chat (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, title VARCHAR(200) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL)');

        $this->addSql('CREATE TABLE chat_exchange (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, triggered_by VARCHAR(16) DEFAULT \'inbound\' NOT NULL, status VARCHAR(16) NOT NULL, checkpoint CLOB DEFAULT NULL, lock_version INTEGER DEFAULT 0 NOT NULL, claimed_at INTEGER DEFAULT NULL, claim_fleet VARCHAR(32) DEFAULT NULL, started_at DATETIME DEFAULT NULL, finished_at DATETIME DEFAULT NULL, error_class VARCHAR(32) DEFAULT NULL, chat_id INTEGER NOT NULL, CONSTRAINT FK_E71967491A9A7125 FOREIGN KEY (chat_id) REFERENCES chat (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX idx_chat_exchange_chat ON chat_exchange (chat_id)');
        $this->addSql('CREATE INDEX idx_chat_exchange_status ON chat_exchange (status)');

        $this->addSql('CREATE TABLE chat_exchange_event (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, seq INTEGER NOT NULL, created_at DATETIME NOT NULL, payload CLOB NOT NULL, error_class VARCHAR(32) DEFAULT NULL, attempt_no INTEGER DEFAULT NULL, duration_ms INTEGER DEFAULT NULL, type VARCHAR(32) NOT NULL, speaker VARCHAR(32) DEFAULT NULL, role VARCHAR(32) DEFAULT NULL, origin VARCHAR(16) DEFAULT NULL, content CLOB DEFAULT NULL, reply_to_id INTEGER DEFAULT NULL, exchange_id INTEGER NOT NULL, CONSTRAINT FK_336FC8F268AFD1A0 FOREIGN KEY (exchange_id) REFERENCES chat_exchange (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX idx_chat_exchange_event_exchange_seq ON chat_exchange_event (exchange_id, seq)');
        $this->addSql('CREATE INDEX IDX_336FC8F268AFD1A0 ON chat_exchange_event (exchange_id)');
    }

    public function down(Schema $schema): void
    {
        // Children first: both foreign keys cascade, but dropping in
        // dependency order keeps the intent legible and the SQLite behaviour
        // explicit rather than implied.
        $this->addSql('DROP TABLE chat_exchange_event');
        $this->addSql('DROP TABLE chat_exchange');
        $this->addSql('DROP TABLE chat');
    }
}

<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Run concurrency (chunk 4): the Messenger table + the run execution claim.
 *
 * messenger_messages — the shared doctrine transport backing the `llm`,
 * `tools`, and `failed` lanes (selected by queue_name). Created explicitly
 * here because MESSENGER_TRANSPORT_DSN carries auto_setup=0: schema changes
 * are an explicit deployment step, never a per-boot side effect (§8.6).
 *
 * run.lock_version / run.claimed_at — the engine's execution claim (SPEC
 * §6): one atomic UPDATE with a version compare-and-swap fences duplicate
 * deliveries from executing a turn concurrently; the claim is taken over
 * after CLAIM_STALE_SECONDS when its owner died.
 */
final class Version20260923224143 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Messenger transport table (llm/tools/failed lanes) + run execution claim (SPEC §6).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE messenger_messages (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, body CLOB NOT NULL, headers CLOB NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL, available_at DATETIME NOT NULL, delivered_at DATETIME DEFAULT NULL)');
        $this->addSql('CREATE INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 ON messenger_messages (queue_name, available_at, delivered_at, id)');
        $this->addSql('ALTER TABLE run ADD COLUMN lock_version INTEGER DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE run ADD COLUMN claimed_at INTEGER DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE messenger_messages');
        $this->addSql('CREATE TEMPORARY TABLE __temp__run AS SELECT id, status, toolbox_snapshot, started_at, finished_at, checkpoint, step_count, error_class, task_id FROM run');
        $this->addSql('DROP TABLE run');
        $this->addSql('CREATE TABLE run (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, status VARCHAR(32) NOT NULL, toolbox_snapshot CLOB NOT NULL, started_at DATETIME DEFAULT NULL, finished_at DATETIME DEFAULT NULL, checkpoint CLOB DEFAULT NULL, step_count INTEGER NOT NULL, error_class VARCHAR(32) DEFAULT NULL, task_id INTEGER DEFAULT NULL, CONSTRAINT FK_5076A4C08DB60186 FOREIGN KEY (task_id) REFERENCES task (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO run (id, status, toolbox_snapshot, started_at, finished_at, checkpoint, step_count, error_class, task_id) SELECT id, status, toolbox_snapshot, started_at, finished_at, checkpoint, step_count, error_class, task_id FROM __temp__run');
        $this->addSql('DROP TABLE __temp__run');
        $this->addSql('CREATE INDEX idx_run_task ON run (task_id)');
        $this->addSql('CREATE INDEX idx_run_status ON run (status)');
    }
}

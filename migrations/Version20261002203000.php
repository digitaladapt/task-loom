<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * run.claim_fleet — which fleet took the claim (SPEC §6.2).
 *
 * Boot recovery's first cut inferred "abandoned" from claim age, reusing the
 * engine's CLAIM_STALE_SECONDS window. That was wrong, and the field showed
 * why immediately: a container that is `down`'d and `up`'d inside a minute
 * leaves claims seconds old, and no amount of sweeping can distinguish "10
 * seconds old because the owner is dead" from "10 seconds old because the
 * owner is mid-turn". The boot sweep reported "0 cleared, 2 left held", the
 * requeue dispatched the owed turns it had correctly derived, and every one of
 * those turns was then dropped on delivery — `claim()` requires the claim to be
 * past the same hour, so the re-dispatched messages hit a lock nobody would
 * release for 59 more minutes. Recovery that looks like recovery and does
 * nothing.
 *
 * The missing fact was identity, not recency. The process group that boots
 * already knows its own identity (FLEET_ID, generated once per container
 * start), so a run recovered at boot can be asked a much sharper question:
 * was this claim taken by *the fleet I am replacing*? If it was, the writable
 * column is the same column that says so, and the hour never enters the
 * picture.
 *
 * Nullable on purpose, and the null is meaningful. Rows claimed before this
 * migration have no recorded owner, and rows claimed by a process that never
 * took a stamp (a one-shot `app:run:now`, which has no fleet identity) have
 * none either. Neither is provably dead, so neither may be swept: those keep
 * the old lease behaviour, which is always safe and merely slow. The sweep
 * touches only what it can prove.
 */
final class Version20261002203000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'run.claim_fleet: the fleet that holds a claim, so boot recovery can clear exactly its dead predecessor (SPEC §6.2).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE run ADD COLUMN claim_fleet VARCHAR(32) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // SQLite may not drop a column in place; the same table-rebuild the
        // project's other down() migrations use.
        $this->addSql('CREATE TEMPORARY TABLE __temp__run AS SELECT id, status, toolbox_snapshot, started_at, finished_at, checkpoint, step_count, error_class, task_id, lock_version, claimed_at, role, parent_id, step_id, triggered_by FROM run');
        $this->addSql('DROP TABLE run');
        $this->addSql('CREATE TABLE run (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, status VARCHAR(32) NOT NULL, toolbox_snapshot CLOB NOT NULL, started_at DATETIME DEFAULT NULL, finished_at DATETIME DEFAULT NULL, checkpoint CLOB DEFAULT NULL, step_count INTEGER NOT NULL, error_class VARCHAR(32) DEFAULT NULL, task_id INTEGER DEFAULT NULL, lock_version INTEGER DEFAULT 0 NOT NULL, claimed_at INTEGER DEFAULT NULL, role VARCHAR(16) DEFAULT \'standalone\' NOT NULL, parent_id INTEGER DEFAULT NULL, step_id INTEGER DEFAULT NULL, triggered_by VARCHAR(16) DEFAULT \'manual\' NOT NULL, CONSTRAINT FK_5076A4C08DB60186 FOREIGN KEY (task_id) REFERENCES task (id) ON UPDATE NO ACTION ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_5076A4C0727ACA70 FOREIGN KEY (parent_id) REFERENCES run (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_5076A4C073B21E9C FOREIGN KEY (step_id) REFERENCES step (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO run (id, status, toolbox_snapshot, started_at, finished_at, checkpoint, step_count, error_class, task_id, lock_version, claimed_at, role, parent_id, step_id, triggered_by) SELECT id, status, toolbox_snapshot, started_at, finished_at, checkpoint, step_count, error_class, task_id, lock_version, claimed_at, role, parent_id, step_id, triggered_by FROM __temp__run');
        $this->addSql('DROP TABLE __temp__run');
        $this->addSql('CREATE INDEX idx_run_task ON run (task_id)');
        $this->addSql('CREATE INDEX idx_run_status ON run (status)');
        $this->addSql('CREATE INDEX idx_run_parent ON run (parent_id)');
        $this->addSql('CREATE INDEX IDX_5076A4C073B21E9C ON run (step_id)');
    }
}

<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The step model's run graph (SPEC §13.3): the run table gains a role, a
 * parent, and a step reference.
 *
 * - role: standalone | parent | step | final_consumer. Existing rows
 *   default to standalone — every pre-step-model run was one.
 * - parent_id: the parent run of a graph; cascades with it.
 * - step_id: which step a step child executes; SET NULL so removing a
 *   (draft-only) step never destroys run history.
 *
 * SQLite rebuilds the table (Doctrine's diff); defaults carry the existing
 * rows across.
 */
final class Version20260926125825 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Run graph (SPEC §13.3): run.role, run.parent_id, run.step_id.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TEMPORARY TABLE __temp__run AS SELECT id, status, toolbox_snapshot, started_at, finished_at, checkpoint, step_count, error_class, task_id, lock_version, claimed_at FROM run');
        $this->addSql('DROP TABLE run');
        $this->addSql('CREATE TABLE run (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, status VARCHAR(32) NOT NULL, toolbox_snapshot CLOB NOT NULL, started_at DATETIME DEFAULT NULL, finished_at DATETIME DEFAULT NULL, checkpoint CLOB DEFAULT NULL, step_count INTEGER NOT NULL, error_class VARCHAR(32) DEFAULT NULL, task_id INTEGER DEFAULT NULL, lock_version INTEGER DEFAULT 0 NOT NULL, claimed_at INTEGER DEFAULT NULL, role VARCHAR(16) DEFAULT \'standalone\' NOT NULL, parent_id INTEGER DEFAULT NULL, step_id INTEGER DEFAULT NULL, CONSTRAINT FK_5076A4C08DB60186 FOREIGN KEY (task_id) REFERENCES task (id) ON UPDATE NO ACTION ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_5076A4C0727ACA70 FOREIGN KEY (parent_id) REFERENCES run (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_5076A4C073B21E9C FOREIGN KEY (step_id) REFERENCES step (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO run (id, status, toolbox_snapshot, started_at, finished_at, checkpoint, step_count, error_class, task_id, lock_version, claimed_at) SELECT id, status, toolbox_snapshot, started_at, finished_at, checkpoint, step_count, error_class, task_id, lock_version, claimed_at FROM __temp__run');
        $this->addSql('DROP TABLE __temp__run');
        $this->addSql('CREATE INDEX idx_run_status ON run (status)');
        $this->addSql('CREATE INDEX idx_run_task ON run (task_id)');
        $this->addSql('CREATE INDEX idx_run_parent ON run (parent_id)');
        $this->addSql('CREATE INDEX IDX_5076A4C073B21E9C ON run (step_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE TEMPORARY TABLE __temp__run AS SELECT id, status, toolbox_snapshot, started_at, finished_at, checkpoint, step_count, lock_version, claimed_at, error_class, task_id FROM run');
        $this->addSql('DROP TABLE run');
        $this->addSql('CREATE TABLE run (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, status VARCHAR(32) NOT NULL, toolbox_snapshot CLOB NOT NULL, started_at DATETIME DEFAULT NULL, finished_at DATETIME DEFAULT NULL, checkpoint CLOB DEFAULT NULL, step_count INTEGER NOT NULL, lock_version INTEGER DEFAULT 0 NOT NULL, claimed_at INTEGER DEFAULT NULL, error_class VARCHAR(32) DEFAULT NULL, task_id INTEGER DEFAULT NULL, CONSTRAINT FK_5076A4C08DB60186 FOREIGN KEY (task_id) REFERENCES task (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO run (id, status, toolbox_snapshot, started_at, finished_at, checkpoint, step_count, lock_version, claimed_at, error_class, task_id) SELECT id, status, toolbox_snapshot, started_at, finished_at, checkpoint, step_count, lock_version, claimed_at, error_class, task_id FROM __temp__run');
        $this->addSql('DROP TABLE __temp__run');
        $this->addSql('CREATE INDEX idx_run_task ON run (task_id)');
        $this->addSql('CREATE INDEX idx_run_status ON run (status)');
    }
}

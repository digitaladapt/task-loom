<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260922015040 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Run engine v1: task, run, run_event, tool_call tables (SPEC §7).';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE run (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, status VARCHAR(32) NOT NULL, toolbox_snapshot CLOB NOT NULL, started_at DATETIME DEFAULT NULL, finished_at DATETIME DEFAULT NULL, checkpoint CLOB DEFAULT NULL, step_count INTEGER NOT NULL, error_class VARCHAR(32) DEFAULT NULL, task_id INTEGER DEFAULT NULL, CONSTRAINT FK_5076A4C08DB60186 FOREIGN KEY (task_id) REFERENCES task (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX idx_run_task ON run (task_id)');
        $this->addSql('CREATE INDEX idx_run_status ON run (status)');
        $this->addSql('CREATE TABLE run_event (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, seq INTEGER NOT NULL, type VARCHAR(32) NOT NULL, created_at DATETIME NOT NULL, payload CLOB NOT NULL, error_class VARCHAR(32) DEFAULT NULL, attempt_no INTEGER DEFAULT NULL, duration_ms INTEGER DEFAULT NULL, run_id INTEGER DEFAULT NULL, CONSTRAINT FK_731102984E3FEC4 FOREIGN KEY (run_id) REFERENCES run (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX idx_run_event_run_seq ON run_event (run_id, seq)');
        $this->addSql('CREATE INDEX IDX_731102984E3FEC4 ON run_event (run_id)');
        $this->addSql('CREATE TABLE task (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, title VARCHAR(200) NOT NULL, brief CLOB NOT NULL, kind VARCHAR(16) NOT NULL, toolbox_mode VARCHAR(16) NOT NULL, toolbox CLOB NOT NULL, schedule VARCHAR(64) DEFAULT NULL, enabled BOOLEAN NOT NULL, created_by VARCHAR(16) NOT NULL, archived_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, replacement_for_id INTEGER DEFAULT NULL, superseded_by_id INTEGER DEFAULT NULL, CONSTRAINT FK_527EDB25BE0A272A FOREIGN KEY (replacement_for_id) REFERENCES task (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_527EDB2539626D86 FOREIGN KEY (superseded_by_id) REFERENCES task (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX idx_task_replacement_for ON task (replacement_for_id)');
        $this->addSql('CREATE INDEX IDX_527EDB2539626D86 ON task (superseded_by_id)');
        $this->addSql('CREATE TABLE tool_call (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, tool VARCHAR(128) NOT NULL, server VARCHAR(64) NOT NULL, arguments CLOB NOT NULL, result CLOB NOT NULL, error_class VARCHAR(32) DEFAULT NULL, attempt_no INTEGER NOT NULL, duration_ms INTEGER DEFAULT NULL, created_at DATETIME NOT NULL, run_event_id INTEGER DEFAULT NULL, CONSTRAINT FK_17CC6F754AC1AA33 FOREIGN KEY (run_event_id) REFERENCES run_event (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX idx_tool_call_run_event ON tool_call (run_event_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE run');
        $this->addSql('DROP TABLE run_event');
        $this->addSql('DROP TABLE task');
        $this->addSql('DROP TABLE tool_call');
    }
}

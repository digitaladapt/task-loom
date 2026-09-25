<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The step model's data foundation (SPEC §13.2): the step table.
 *
 * depends_on is CLOB (JSON) — a list of step ids, the DAG edges. Position
 * is display order only; execution reads the edges. Rows cascade with
 * their task, matching the rest of the model's content lifetime.
 */
final class Version20260924193043 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Step table (SPEC §13.2): brief + toolbox + depends_on edges per task.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE step (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, position INTEGER NOT NULL, title VARCHAR(200) NOT NULL, brief CLOB NOT NULL, toolbox_mode VARCHAR(16) NOT NULL, toolbox CLOB NOT NULL, depends_on CLOB NOT NULL, task_id INTEGER DEFAULT NULL, CONSTRAINT FK_43B9FE3C8DB60186 FOREIGN KEY (task_id) REFERENCES task (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX idx_step_task ON step (task_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE step');
    }
}

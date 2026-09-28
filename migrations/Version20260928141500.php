<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The scheduler's state (SPEC §14): task.next_run_at, run.triggered_by.
 *
 * - task.next_run_at: the scheduler cursor. Epoch seconds (INTEGER), not a
 *   datetime — the same choice the execution claim made for claimed_at,
 *   and for a concrete reason: DBAL reinterprets SQLite datetime columns
 *   in the process's *current* default timezone on hydration, so a
 *   datetime cursor compares unstably across environments. An epoch
 *   instant has no such ambiguity, and the cursor is advanced by a
 *   compare-and-swap on exactly this column, so a due occurrence fires at
 *   most once even if two ticks race. Nullable: scheduling state, not
 *   content — null means "not armed", and the tick arms an enabled
 *   scheduled task on first sight.
 *
 * - run.triggered_by: what launched the run — `manual` (Run now, console)
 *   or `scheduled` (the tick). Existing rows are all manual by definition;
 *   the column default carries them across.
 */
final class Version20260928141500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Scheduler (SPEC §14): task.next_run_at cursor, run.triggered_by.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE task ADD COLUMN next_run_at INTEGER DEFAULT NULL');
        $this->addSql('ALTER TABLE run ADD COLUMN triggered_by VARCHAR(16) DEFAULT \'manual\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE task DROP COLUMN next_run_at');
        $this->addSql('ALTER TABLE run DROP COLUMN triggered_by');
    }
}

<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `run.declaration`: a session slice's typed declaration
 * (docs/design/SESSION_TASKS.md §8, build order step 4) — the outcome
 * (`milestone` / `done` / `blocked`) and the slice's summary, recorded in
 * the same commit that settles the slice.
 *
 * The column is the machine-readable half of the declaration: the session's
 * derived state (§8 — working / owed / complete / blocked / stopped) and
 * the requeue sweep's session pass re-derive everything from committed rows,
 * and reading one column beside the row is how they ask "what did the last
 * slice declare?" without scanning a ledger. The human-readable half stays
 * in the ledger where everything else lives: the declaration tool call, its
 * result, and the Completion (or Failure) event the settlement appends.
 *
 * Nullable, and null means something: a slice that settled WITHOUT a
 * declaration — an exhausted slice budget, a failed turn, a tripped breaker,
 * or a terminal message that declared nothing — is exactly the case the
 * design treats as "the session stops and needs a human" (§8). A slice of a
 * `run`-kind task never carries one.
 */
final class Version20261010212428 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'run.declaration: a session slice\'s typed declaration (milestone/done/blocked + summary), the record the next-slice advancement and the requeue sweep re-derive from (docs/design/SESSION_TASKS.md §8).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE run ADD COLUMN declaration CLOB DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // SQLite supports dropping a column since 3.35; this is a plain
        // additive column, so no table rebuild is needed.
        $this->addSql('ALTER TABLE run DROP COLUMN declaration');
    }
}

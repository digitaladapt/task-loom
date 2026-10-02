<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Nothing to migrate — and that is the point (SPEC §6.2).
 *
 * Boot recovery reuses the execution claim the engine already has:
 * RunEngine::CLAIM_STALE_SECONDS for "abandoned", run.lock_version for the
 * ownership token, run.claimed_at for liveness. Clearing an abandoned claim
 * is an UPDATE on those existing columns, so the sweep ships without a schema
 * change.
 *
 * This class exists as the reviewable record of that decision. "Reuse the
 * claim columns" and "add a fleet_heartbeat / claimant_id table" are the two
 * candidate designs — the second is the honest one for a deployment whose
 * workers and web interface are separate process groups sharing a database,
 * and it is written up in docs/design/GRACEFUL_RESTART.md as the follow-up.
 * When that lands, this is where its schema goes. An empty migration that
 * says why is cheaper than a comment somewhere nobody reads.
 */
final class Version20261002120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'No-op: boot recovery reuses run.lock_version/claimed_at (SPEC §6.2). Records that no schema change was needed.';
    }

    public function up(Schema $schema): void
    {
        // Deliberately empty — see the class docblock.
    }

    public function down(Schema $schema): void
    {
        // Deliberately empty.
    }
}

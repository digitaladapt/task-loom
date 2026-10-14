<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `task.grounding_at`: the instant a session's grounding block is fixed to
 * (`docs/design/KV_CACHE.md` §6.3, B4).
 *
 * A session slice is an ordinary `Run` whose head is re-compiled per slice
 * (`SessionSliceEngine::createSlice`), so a live grounding timestamp made every
 * slice a brand-new head: slice *n+1* re-prefilled from scratch even when the
 * memory it carried was mostly the same text. The block is now rendered as of
 * this anchor and held byte-stable, so within one deployment day every slice of
 * a session compiles an identical head; the anchor is moved when the deployment
 * day turns, which keeps the freshness that matters (a session resumed tomorrow
 * is told tomorrow's date) at one cache break a day rather than one a slice.
 *
 * Nullable, and null means something: an ordinary run never anchors (its head is
 * compiled once and never re-compiled), and a session started before this column
 * has no anchor — `Task::groundingInstant()` answers with the task's
 * `created_at` rather than the live clock, so an existing session is grounded in
 * its own past and then frozen, instead of putting the moving value back on the
 * wire for exactly the rows a migration did not touch.
 */
final class Version20261014161000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'task.grounding_at: the instant a session\'s grounding block is fixed to, so slices within a day reuse each other\'s prefix instead of each re-prefilling a fresh head (docs/design/KV_CACHE.md §6.3, B4).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE task ADD COLUMN grounding_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // SQLite supports dropping a column since 3.35; this is a plain
        // additive column, so no table rebuild is needed.
        $this->addSql('ALTER TABLE task DROP COLUMN grounding_at');
    }
}

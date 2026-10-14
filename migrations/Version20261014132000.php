<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `chat.grounding_at`: the instant a conversation's grounding block is fixed to
 * (`docs/design/KV_CACHE_POSITION_FINDINGS.md`).
 *
 * A chat was grounded live on every reply, on purpose — a conversation resumed
 * after a day of silence should be told the day it is resumed on. But a live
 * clock at position 0 of the system message breaks prefix reuse for the *whole*
 * conversation on every minute boundary (measured: 28% reuse against 94% when
 * the block does not move), because a prefix-caching server reuses a request
 * only up to the first byte that changed. So the block is rendered as of this
 * anchor and held byte-stable, and the anchor is moved when the deployment day
 * turns — the only moment at which "grounded in the day it is resumed on"
 * can differ.
 *
 * Nullable, and null means something: a chat written before this column has no
 * anchor, and `Chat::groundingInstant()` answers with the conversation's
 * `created_at` rather than the live clock — so an old conversation is grounded
 * in its own past and then frozen, instead of putting the moving value back on
 * the wire for exactly the rows a migration did not touch.
 */
final class Version20261014132000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'chat.grounding_at: the instant a conversation\'s grounding block is fixed to, so its replies reuse the prefix instead of re-prefilling on every minute boundary (docs/design/KV_CACHE_POSITION_FINDINGS.md).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE chat ADD COLUMN grounding_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // SQLite supports dropping a column since 3.35; this is a plain
        // additive column, so no table rebuild is needed.
        $this->addSql('ALTER TABLE chat DROP COLUMN grounding_at');
    }
}

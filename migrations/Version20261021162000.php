<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `chat.context_base` / `chat.context_batch`: the sticky shedding front, moved
 * from the exchange to the conversation (`docs/design/KV_CACHE.md` §6.2, B2).
 *
 * §6.2 put chat's sticky front in `chat_exchange.checkpoint`, "where the
 * turn-to-turn progress already lives" — reasoning that holds for a run, whose
 * whole life is one row, and fails for a conversation, whose life is a
 * sequence of exchanges. Every message in chat is a *new* `ChatExchange` with
 * an empty checkpoint, so the front a trim established was written by the
 * reply and then discarded when the message was answered: the next message
 * began with no anchor at all, the front slid forward on every message, and the
 * whole conversation was re-prefilled each time. Batching was correct and
 * inert — the measured symptom was chat degrading sharply once a conversation
 * passed the window bound.
 *
 * The front is a wire event id, which is already scoped to the conversation
 * (the events join to their exchange), so it means the same thing on the next
 * message as it did on the last. Null means "no anchor yet", which is the
 * stateless count bound — the exact behaviour a pre-migration conversation has,
 * so an existing chat keeps working and simply establishes an anchor on its
 * next forced trim rather than needing a backfill.
 */
final class Version20261021162000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'chat.context_base / chat.context_batch: the sticky shed front lives on the conversation, not the exchange, so a chat reuses its prefix across messages instead of re-prefilling each one (docs/design/KV_CACHE.md §6.2, B2).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE chat ADD COLUMN context_base INTEGER DEFAULT NULL');
        // Not null with a false default: a conversation that has never been
        // forced to shed has never latched batching, and false is that state.
        // Spelled `0` rather than `FALSE`, which is the literal Doctrine's own
        // diff expects for a boolean on SQLite — anything else shows up as a
        // permanent pending change to `schema:update`.
        $this->addSql('ALTER TABLE chat ADD COLUMN context_batch BOOLEAN NOT NULL DEFAULT 0');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE chat DROP COLUMN context_batch');
        $this->addSql('ALTER TABLE chat DROP COLUMN context_base');
    }
}

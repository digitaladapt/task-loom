<?php

declare(strict_types=1);

namespace App\RunEngine;

use App\Entity\Being;
use App\Entity\Task;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Reads and writes the being's durable facts: her frozen heads and her trim
 * watermark (docs/design/THE_STREAM.md §2.2, build step 3).
 *
 * ## Why a store rather than setters on the entity
 *
 * Two rules make the frozen-head trick work, and both are violated by a thin
 * accessor: a head is compiled **once** and never recompiled, and the watermark
 * only ever moves when a trim is actually forced. An accessor invites
 * `$being->setHead(...)` at the call site, and then every later engine change
 * quietly reintroduces per-run compilation — the exact bug this step removes.
 * A single narrow surface is easier to keep honest than a dozen call sites.
 *
 * ## One being
 *
 * `current()` is get-or-create and returns the same row for the lifetime of the
 * deployment. That is not a cache; it is the design. §2.5 describes one being
 * who turns, finishes, and turns again. A second row would mean a second
 * number line, a second head, and no answer to "whose stream is this".
 */
final class BeingStore
{
    /**
     * The being this request is working with, or null before the first read.
     *
     * Held as a weak reference: the EntityManager is the owner of entity
     * identity, and it can close ({@see EntityManagerInterface::clear()}, a
     * worker's per-message reset) without telling us. A strong reference would
     * then be a detached row that still looks usable and fails only at flush
     * with "a new entity was found through the relationship ..." — which is how
     * this was found.
     *
     * @var \WeakReference<Being>|null
     */
    private ?\WeakReference $being = null;

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * The being, created on first use.
     *
     * Created lazily rather than by a fixture because every deployment has
     * exactly one and it is not configuration — it is what the deployment *is*.
     * The row is flushed immediately: a caller that appends to her stream needs
     * an id, and `stream_event.being_id` is not nullable.
     *
     * A remembered row is only handed back while the EntityManager still
     * manages it. After a `clear()` the old instance is detached and every write
     * through it fails at flush, so the re-read is not an optimisation guard —
     * it is the difference between working and not.
     */
    public function current(): Being
    {
        $remembered = $this->being?->get();

        if (null !== $remembered && $this->em->contains($remembered)) {
            return $remembered;
        }

        /** @var Being|null $existing */
        $existing = $this->em->createQuery('SELECT b FROM App\\Entity\\Being b ORDER BY b.id ASC')
            ->setMaxResults(1)
            ->getOneOrNullResult();

        if (null === $existing) {
            $existing = new Being();
            $this->em->persist($existing);
            $this->em->flush();
        }

        $this->being = \WeakReference::create($existing);

        return $existing;
    }

    /**
     * The key a head is frozen under.
     *
     * A head is a function of the task (its title, brief, toolbox and completion
     * text), of which kind of head it is — an ordinary run's, a session slice's,
     * or a final consumer's — and of the grounding instant it was compiled at.
     *
     * Including the task id keeps one task's constitution out of another's
     * prompt. That is not a nicety: SPEC §4.1 says a run's toolbox and prompt are
     * frozen at creation, and a head shared across tasks would hand a run tools
     * it was never granted.
     *
     * Including the grounding instant is subtler, and it was missing at first.
     * A session's grounding is *anchored* rather than live (KV_CACHE.md §6.5):
     * the anchor normally only moves when the deployment day turns. That is
     * exactly why the key has to carry it. Freezing a slice head under a key
     * without the instant means the first slice ever compiled is served to every
     * later slice — so a session resumed tomorrow is told today's date forever,
     * unresolved and unfixable except by clearing her heads. Anchoring bought one
     * cache break a day; a key that ignores the anchor spends it once and then
     * lies. The date is part of the head, so it is part of the key.
     *
     * Defaulted to null so an ordinary run, whose grounding is the live clock and
     * is therefore already covered by "compiled once", keeps a stable key.
     */
    public function headKey(Task $task, string $kind, ?\DateTimeImmutable $groundingAt = null): string
    {
        $key = \sprintf('%s:%s', $kind, $task->getId() ?? 'unsaved');

        if (null !== $groundingAt) {
            $key .= ':'.$groundingAt->format('Y-m-d\TH:i:sP');
        }

        return $key;
    }

    /**
     * The frozen head for `$key`, compiling and freezing it on first use.
     *
     * `$compile` must be *pure* with respect to the being's state: it may read
     * the task, the toolbox and the grounding anchor, and must not read the
     * clock, the stream, or anything else that changes between two calls with
     * the same key. If it does, the first call's answer is frozen and every
     * later call silently disagrees with what a fresh compile would produce —
     * which is a deoptimisation, not a bug, but it is worth saying out loud
     * because it is the one way this method can mislead.
     *
     * @param callable(): array{system: string, user: string} $compile
     *
     * @return array{system: string, user: string}
     */
    public function head(Being $being, string $key, callable $compile): array
    {
        $frozen = $being->head($key);

        if (null !== $frozen) {
            return $frozen;
        }

        $head = $compile();
        $being->rememberHead($key, $head);

        $this->em->flush();

        return $head;
    }

    /**
     * The being's trim watermark, or null when no trim has ever been forced.
     *
     * Returning the pair rather than two getters because they are only
     * meaningful together: the base says where the retained window starts, and
     * the batch flag says whether the *next* trim should shed a whole batch
     * instead of stopping at "just fits" ({@see \App\Context\ShedPlan}). Reading
     * one without the other is how the batching stops amortising.
     *
     * @return array{base: int|null, batch: bool}
     */
    public function watermark(Being $being): array
    {
        return ['base' => $being->getContextBase(), 'batch' => $being->isContextBatch()];
    }

    /**
     * Record where the retained window now begins.
     *
     * Called only when a trim was actually forced, and never with a base that
     * moves the front *backwards*. That second rule is the invariant §2.3 cares
     * about: the front may fall behind (the window keeps whole exchanges and a
     * later turn may fit fewer of them), but it must not advance past an
     * exchange the model has already been shown, or the prefix it prefilled no
     * longer exists and every later turn pays for a full prefill.
     */
    public function advanceWatermark(Being $being, ?int $base, bool $batch): void
    {
        $being->setContextBase($base);
        $being->setContextBatch($batch);

        $this->em->flush();
    }

    /**
     * Forget the watermark and every frozen head, as if she had never turned.
     *
     * Both are derived from state that can be taken away underneath her: the
     * base indexes into the transcript, and a head is compiled from a task.
     * A fixture that empties `stream_event` while leaving `context_base = 9`
     * behind hands the next run a front past the end of a transcript that no
     * longer exists - and it fails silently, because the window only sheds when
     * the retained set *exceeds* the budget, so a base past the end raises no
     * trim at all. That is how one test passed alone and failed in the suite:
     * it counts trims, and the count had quietly become zero.
     *
     * This exists for exactly that caller - a reset to a blank deployment - and
     * is not on the turn path. Nothing in the engine calls it.
     */
    public function reset(Being $being): void
    {
        $being->setContextBase(null);
        $being->setContextBatch(false);
        $being->forgetHeads();

        $this->em->flush();
    }
}

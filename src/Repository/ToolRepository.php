<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\McpServer;
use App\Entity\Tool;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Tool>
 */
class ToolRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Tool::class);
    }

    public function save(Tool $entity, bool $flush = true): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * The whole catalog in the canonical display order: server name, then
     * tool name, both ascending.
     *
     * Every human-facing surface that lists tools uses this — the catalog
     * page, the task editor's explicit-tool picker, the toolbox preview —
     * so the same tool sits in the same place wherever the operator meets
     * it, and one server's tools stay together instead of interleaving with
     * every other server's alphabetically.
     *
     * The run engine's resolution passes (ToolboxResolver, ToolboxPreviewer,
     * ToolAdminService) share it too: a resolved toolbox, a preview, and the
     * picker then present the same tools in the same order, and the frozen
     * snapshot inherits it. The tie-break on id keeps the order total: two
     * servers may share a name only transiently (there is a unique
     * constraint), but a query must never leave equal keys in an arbitrary
     * order.
     *
     * @return list<Tool>
     */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('t')
            ->innerJoin('t.server', 's')
            ->orderBy('s.name', \SortDirection::Ascending)
            ->addOrderBy('t.name', \SortDirection::Ascending)
            ->addOrderBy('t.id', \SortDirection::Ascending)
            ->getQuery()
            ->getResult();
    }

    /**
     * All tools of one server, indexed by name — the shape the sync merge
     * needs (SPEC §7: pin-over-discovered, never wipe on down server).
     *
     * @return array<string, Tool>
     */
    public function findForServerIndexedByName(McpServer $server): array
    {
        $rows = $this->createQueryBuilder('t')
            ->andWhere('t.server = :server')
            ->setParameter('server', $server)
            ->getQuery()
            ->getResult();

        $byName = [];
        foreach ($rows as $row) {
            $byName[$row->getName()] = $row;
        }

        return $byName;
    }
}

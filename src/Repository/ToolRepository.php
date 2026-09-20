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

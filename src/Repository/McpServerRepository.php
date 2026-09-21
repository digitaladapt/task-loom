<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\McpServer;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<McpServer>
 */
class McpServerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, McpServer::class);
    }

    public function save(McpServer $entity, bool $flush = true): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(McpServer $entity, bool $flush = true): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * All servers the synchronizer should contact, in stable order.
     *
     * @return list<McpServer>
     */
    public function findSyncable(): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.enabled = :enabled')
            ->setParameter('enabled', true)
            ->orderBy('s.name', \SortDirection::Ascending)
            ->getQuery()
            ->getResult();
    }
}

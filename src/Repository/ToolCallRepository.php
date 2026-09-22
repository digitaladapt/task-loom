<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ToolCall;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * ToolCall queries.
 *
 * @extends ServiceEntityRepository<ToolCall>
 */
final class ToolCallRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ToolCall::class);
    }

    public function save(ToolCall $call): void
    {
        $this->getEntityManager()->persist($call);
        $this->getEntityManager()->flush();
    }
}

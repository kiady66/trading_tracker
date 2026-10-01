<?php

namespace App\Repository;

use App\Entity\CentralBankRefresh;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CentralBankRefresh>
 */
class CentralBankRefreshRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CentralBankRefresh::class);
    }

    public function findLatest(): ?CentralBankRefresh
    {
        return $this->findOneBy([], ['startedAt' => 'DESC', 'id' => 'DESC']);
    }
}

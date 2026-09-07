<?php

namespace App\Repository;

use App\Entity\CentralBank;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CentralBank>
 *
 * @method CentralBank|null find($id, $lockMode = null, $lockVersion = null)
 * @method CentralBank|null findOneBy(array $criteria, array $orderBy = null)
 * @method CentralBank[]    findAll()
 * @method CentralBank[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class CentralBankRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CentralBank::class);
    }

    /**
     * @return CentralBank[]
     */
    public function findAllOrdered(): array
    {
        return $this->findBy([], ['position' => 'ASC']);
    }
}

<?php

namespace App\Repository;

use App\Entity\BiasPair;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BiasPair>
 */
class BiasPairRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BiasPair::class);
    }

    /**
     * @return list<BiasPair> dans l'ordre choisi par l'utilisateur
     */
    public function findByUserOrdered(User $user): array
    {
        return $this->findBy(['user' => $user], ['position' => 'ASC', 'id' => 'ASC']);
    }
}

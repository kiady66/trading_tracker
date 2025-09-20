<?php

namespace App\Repository;

use App\Entity\TradeScreenshot;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\ORM\QueryBuilder;

/**
 * @extends ServiceEntityRepository<TradeScreenshot>
 *
 * @method TradeScreenshot|null find($id, $lockMode = null, $lockVersion = null)
 * @method TradeScreenshot|null findOneBy(array $criteria, array $orderBy = null)
 * @method TradeScreenshot[]    findAll()
 * @method TradeScreenshot[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class TradeScreenshotRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TradeScreenshot::class);
    }
}

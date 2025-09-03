<?php

namespace App\Repository;

use App\Entity\Confluence;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\ORM\QueryBuilder;


/**
 * @extends ServiceEntityRepository<Confluence>
 *
 * @method Confluence|null find($id, $lockMode = null, $lockVersion = null)
 * @method Confluence|null findOneBy(array $criteria, array $orderBy = null)
 * @method Confluence[]    findAll()
 * @method Confluence[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class ConfluenceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Confluence::class);
    }
}

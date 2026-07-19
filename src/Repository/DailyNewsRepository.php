<?php

namespace App\Repository;

use App\Entity\DailyNews;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DailyNews>
 *
 * @method DailyNews|null find($id, $lockMode = null, $lockVersion = null)
 * @method DailyNews|null findOneBy(array $criteria, array $orderBy = null)
 * @method DailyNews[]    findAll()
 * @method DailyNews[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class DailyNewsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DailyNews::class);
    }

    public function findOneByDate(\DateTimeImmutable $date): ?DailyNews
    {
        return $this->createQueryBuilder('n')
            ->andWhere('n.date = :date')
            ->setParameter('date', $date->format('Y-m-d'))
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findLatest(): ?DailyNews
    {
        return $this->createQueryBuilder('n')
            ->orderBy('n.date', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return \DateTimeImmutable[] dates ayant une news, ordre croissant
     */
    public function findAvailableDates(): array
    {
        $rows = $this->createQueryBuilder('n')
            ->select('n.date')
            ->orderBy('n.date', 'ASC')
            ->getQuery()
            ->getScalarResult();

        return array_map(
            static fn (array $row) => $row['date'] instanceof \DateTimeImmutable
                ? $row['date']
                : new \DateTimeImmutable($row['date']),
            $rows
        );
    }
}
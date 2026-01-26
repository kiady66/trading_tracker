<?php

namespace App\Repository;

use App\Entity\Trade;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\ORM\QueryBuilder;

/**
 * @extends ServiceEntityRepository<Trade>
 */
class TradeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Trade::class);
    }

    public function getStatistics(array $filters = [])
    {
        $qb = $this->createQueryBuilder('t')
            ->andWhere('t.exitDate IS NOT NULL');

        $this->applyFilters($qb, $filters);

        $trades = $qb->getQuery()->getResult();

        $stats = [
            'total_trades' => count($trades),
            'total_gain_euro' => 0,
            'total_gain_rr' => 0,
            'avg_gain_euro' => 0,
            'avg_gain_rr' => 0,
            'max_gain_euro' => 0,
            'min_gain_euro' => 0,
        ];

        foreach ($trades as $trade) {
            $gainEuro = $trade->getGainEuro() ?? 0;
            $finalRR = $trade->getGainRR() ?? 0;

            $stats['total_gain_euro'] += $gainEuro;
            $stats['total_gain_rr'] += $finalRR;

            if ($gainEuro > $stats['max_gain_euro']) {
                $stats['max_gain_euro'] = $gainEuro;
            }

            if ($gainEuro < $stats['min_gain_euro']) {
                $stats['min_gain_euro'] = $gainEuro;
            }
        }

        // Calcul des moyennes
        if ($stats['total_trades'] > 0) {
            $stats['avg_gain_euro'] = $stats['total_gain_euro'] / $stats['total_trades'];
            $stats['avg_gain_rr'] = $stats['total_gain_rr'] / $stats['total_trades'];
        }

        return $stats;
    }

    public function getChartData(array $filters = [])
    {
        $qb = $this->createQueryBuilder('t')
            ->andWhere('t.exitDate IS NOT NULL')
            ->orderBy('t.exitDate', 'ASC');

        $this->applyFilters($qb, $filters);

        $trades = $qb->getQuery()->getResult();

        $dates = [];
        $gainsEuro = [];
        $cumulativeGains = [];
        $finalRR = [];
        $gainRR = [];
        $cumulative = 0;

        // Ajouter un point de départ à 0
        if (count($trades) > 0) {
            $firstTrade = $trades[0];
            $firstDate = $firstTrade->getExitDate()->modify('-1 day')->format('Y-m-d');

            $dates[] = $firstDate;
            $gainsEuro[] = 0;
            $cumulativeGains[] = 0;
            $finalRR[] = 0;
            $gainRR[] = 0;
        }

        foreach ($trades as $trade) {
            $date = $trade->getExitDate()->format('Y-m-d');
            $gainEuro = $trade->getGainEuro() ?? 0;
            $finalRRValue = $trade->getFinalRR() ?? 0;
            $gainRRValue = $trade->getGainRR() ?? 0;

            $dates[] = $date;
            $gainsEuro[] = $gainEuro;
            $finalRR[] = $finalRRValue;
            $gainRR[] = $gainRRValue;

            $cumulative += $gainEuro;
            $cumulativeGains[] = $cumulative;
        }

        return [
            'dates' => $dates,
            'gains_euro' => $gainsEuro,
            'cumulative_gains' => $cumulativeGains,
            'final_rr' => $finalRR,
            'gain_rr' => $gainRR,
        ];
    }

    public function getConfluenceStats(array $filters = [])
    {
        $qb = $this->createQueryBuilder('t')
            ->leftJoin('t.confluences', 'c')
            ->andWhere('t.exitDate IS NOT NULL');

        $this->applyFilters($qb, $filters);

        $trades = $qb->getQuery()->getResult();

        $confluenceStats = [];

        foreach ($trades as $trade) {
            foreach ($trade->getConfluences() as $confluence) {
                $confluenceName = $confluence->getName();

                if (!isset($confluenceStats[$confluenceName])) {
                    $confluenceStats[$confluenceName] = [
                        'count' => 0,
                        'total_gain' => 0,
                        'total_rr' => 0,
                    ];
                }

                $confluenceStats[$confluenceName]['count']++;
                $confluenceStats[$confluenceName]['total_gain'] += $trade->getGainEuro() ?? 0;
                $confluenceStats[$confluenceName]['total_rr'] += $trade->getFinalRR() ?? 0;
            }
        }

        // Calcul des moyennes
        foreach ($confluenceStats as &$stats) {
            if ($stats['count'] > 0) {
                $stats['avg_gain'] = $stats['total_gain'] / $stats['count'];
                $stats['avg_rr'] = $stats['total_rr'] / $stats['count'];
            }
        }

        return $confluenceStats;
    }

    private function applyFilters(QueryBuilder $qb, array $filters): void
    {
        if (!empty($filters['user'])) {
            $qb->andWhere('t.user = :user')
                ->setParameter('user', $filters['user']);
        }

        if (!empty($filters['start_date'])) {
            $qb->andWhere('t.exitDate >= :start_date')
                ->setParameter('start_date', new \DateTime($filters['start_date']));
        }

        if (!empty($filters['end_date'])) {
            $qb->andWhere('t.exitDate <= :end_date')
                ->setParameter('end_date', new \DateTime($filters['end_date'] . ' 23:59:59'));
        }

        if (!empty($filters['confluences'])) {
            $confluences = is_array($filters['confluences']) ? $filters['confluences'] : [$filters['confluences']];

            // Pour chaque confluence, ajoutez une jointure et une condition
            foreach ($confluences as $index => $confluenceId) {
                $alias = 'c_filter_' . $index;
                $qb->join('t.confluences', $alias)
                    ->andWhere($alias . '.id = :confluence_' . $index)
                    ->setParameter('confluence_' . $index, $confluenceId);
            }
        }
    }

    public function getDayStats(array $filters = [])
    {
        $qb = $this->createQueryBuilder('t')
            ->select([
                't.day',
                'COUNT(t.id) as total_trades',
                'SUM(t.gainEuro) as total_gain_euro',
                'SUM(t.gainRR) as total_gain_rr',
                'AVG(t.gainEuro) as avg_gain_euro',
                'AVG(t.gainRR) as avg_gain_rr'
            ])
            ->andWhere('t.exitDate IS NOT NULL')
            ->andWhere('t.day IS NOT NULL')
            ->groupBy('t.day');

        $this->applyFilters($qb, $filters);

        $results = $qb->getQuery()->getResult();

        // Organiser par jours de la semaine dans l'ordre
        $daysOrder = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
        $dayStats = [];

        foreach ($daysOrder as $day) {
            $dayStats[$day] = [
                'total_trades' => 0,
                'total_gain_euro' => 0,
                'total_gain_rr' => 0,
                'avg_gain_euro' => 0,
                'avg_gain_rr' => 0,
            ];
        }

        foreach ($results as $result) {
            $day = $result['day'];
            if (isset($dayStats[$day])) {
                $dayStats[$day] = [
                    'total_trades' => (int)$result['total_trades'],
                    'total_gain_euro' => (float)$result['total_gain_euro'],
                    'total_gain_rr' => (float)$result['total_gain_rr'],
                    'avg_gain_euro' => (float)$result['avg_gain_euro'],
                    'avg_gain_rr' => (float)$result['avg_gain_rr'],
                ];
            }
        }

        return $dayStats;
    }
}

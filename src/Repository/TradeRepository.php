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

    public function findByUserAndDateRange($user, ?string $startDate = null, ?string $endDate = null): array
    {
        $qb = $this->createQueryBuilder('t')
            ->andWhere('t.user = :user')
            ->setParameter('user', $user)
            ->orderBy('t.exitDate', 'DESC');

        if (!empty($startDate)) {
            $qb->andWhere('t.entryDate >= :start_date')
                ->setParameter('start_date', new \DateTime($startDate));
        }

        if (!empty($endDate)) {
            $qb->andWhere('t.entryDate <= :end_date')
                ->setParameter('end_date', new \DateTime($endDate . ' 23:59:59'));
        }

        return $qb->getQuery()->getResult();
    }

    public function getStatistics(array $filters = [])
    {
        $qb = $this->createQueryBuilder('t')
            ->andWhere('t.exitDate IS NOT NULL')
            ->orderBy('t.exitDate', 'ASC');

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
            'winning_trades' => 0,
            'losing_trades' => 0,
            'win_rate' => 0,
            'gross_profit' => 0,
            'gross_loss' => 0,
            'profit_factor' => null,
            'max_drawdown_euro' => 0,
            'max_drawdown_rr' => 0,
            'max_win_streak' => 0,
            'max_loss_streak' => 0,
            'current_streak' => 0,
        ];

        $cumulativeEuro = 0;
        $peakEuro = 0;
        $cumulativeRR = 0;
        $peakRR = 0;
        $winStreak = 0;
        $lossStreak = 0;

        foreach ($trades as $trade) {
            $gainEuro = $trade->getGainEuro() ?? 0;
            $finalRR = $trade->getGainRR() ?? 0;

            $stats['total_gain_euro'] += $gainEuro;
            $stats['total_gain_rr'] += $finalRR;

            if ($gainEuro > 0) {
                $stats['winning_trades']++;
                $stats['gross_profit'] += $gainEuro;
                $winStreak++;
                $lossStreak = 0;
            } elseif ($gainEuro < 0) {
                $stats['losing_trades']++;
                $stats['gross_loss'] += abs($gainEuro);
                $lossStreak++;
                $winStreak = 0;
            } else {
                $winStreak = 0;
                $lossStreak = 0;
            }

            $stats['max_win_streak'] = max($stats['max_win_streak'], $winStreak);
            $stats['max_loss_streak'] = max($stats['max_loss_streak'], $lossStreak);

            $cumulativeEuro += $gainEuro;
            $peakEuro = max($peakEuro, $cumulativeEuro);
            $stats['max_drawdown_euro'] = max($stats['max_drawdown_euro'], $peakEuro - $cumulativeEuro);

            $cumulativeRR += $finalRR;
            $peakRR = max($peakRR, $cumulativeRR);
            $stats['max_drawdown_rr'] = max($stats['max_drawdown_rr'], $peakRR - $cumulativeRR);

            if ($gainEuro > $stats['max_gain_euro']) {
                $stats['max_gain_euro'] = $gainEuro;
            }

            if ($gainEuro < $stats['min_gain_euro']) {
                $stats['min_gain_euro'] = $gainEuro;
            }
        }

        $stats['current_streak'] = $winStreak > 0 ? $winStreak : -$lossStreak;

        if ($stats['gross_loss'] > 0) {
            $stats['profit_factor'] = $stats['gross_profit'] / $stats['gross_loss'];
        }

        // Calcul des moyennes et win rate
        if ($stats['total_trades'] > 0) {
            $stats['avg_gain_euro'] = $stats['total_gain_euro'] / $stats['total_trades'];
            $stats['avg_gain_rr'] = $stats['total_gain_rr'] / $stats['total_trades'];
            $stats['win_rate'] = ($stats['winning_trades'] / $stats['total_trades']) * 100;
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
        $drawdown = [];
        $cumulative = 0;
        $peak = 0;

        // Ajouter un point de départ à 0
        if (count($trades) > 0) {
            $firstTrade = $trades[0];
            $firstDate = $firstTrade->getExitDate()->modify('-1 day')->format('Y-m-d');

            $dates[] = $firstDate;
            $gainsEuro[] = 0;
            $cumulativeGains[] = 0;
            $finalRR[] = 0;
            $gainRR[] = 0;
            $drawdown[] = 0;
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

            $peak = max($peak, $cumulative);
            $drawdown[] = $cumulative - $peak;
        }

        return [
            'dates' => $dates,
            'gains_euro' => $gainsEuro,
            'cumulative_gains' => $cumulativeGains,
            'final_rr' => $finalRR,
            'gain_rr' => $gainRR,
            'drawdown' => $drawdown,
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
            ->andWhere('t.exitDate IS NOT NULL')
            ->andWhere('t.day IS NOT NULL');

        $this->applyFilters($qb, $filters);

        $trades = $qb->getQuery()->getResult();

        $daysOrder = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
        $dayStats = [];

        foreach ($daysOrder as $day) {
            $dayStats[$day] = [
                'total_trades' => 0,
                'winning_trades' => 0,
                'total_gain_euro' => 0,
                'total_gain_rr' => 0,
                'avg_gain_euro' => 0,
                'avg_gain_rr' => 0,
                'win_rate' => 0,
            ];
        }

        foreach ($trades as $trade) {
            $day = $trade->getDay();
            if (!isset($dayStats[$day])) {
                continue;
            }
            $gainEuro = $trade->getGainEuro() ?? 0;
            $dayStats[$day]['total_trades']++;
            $dayStats[$day]['total_gain_euro'] += $gainEuro;
            $dayStats[$day]['total_gain_rr'] += $trade->getGainRR() ?? 0;
            if ($gainEuro > 0) {
                $dayStats[$day]['winning_trades']++;
            }
        }

        foreach ($dayStats as $day => &$stats) {
            if ($stats['total_trades'] > 0) {
                $stats['avg_gain_euro'] = $stats['total_gain_euro'] / $stats['total_trades'];
                $stats['avg_gain_rr'] = $stats['total_gain_rr'] / $stats['total_trades'];
                $stats['win_rate'] = ($stats['winning_trades'] / $stats['total_trades']) * 100;
            }
        }

        return $dayStats;
    }

    public function getCalendarData(array $filters = []): array
    {
        $qb = $this->createQueryBuilder('t')
            ->andWhere('t.exitDate IS NOT NULL')
            ->orderBy('t.exitDate', 'ASC');

        $this->applyFilters($qb, $filters);

        $trades = $qb->getQuery()->getResult();

        // P&L journalier
        $daily = [];
        foreach ($trades as $trade) {
            $key = $trade->getExitDate()->format('Y-m-d');
            if (!isset($daily[$key])) {
                $daily[$key] = ['gain' => 0, 'rr' => 0, 'count' => 0];
            }
            $daily[$key]['gain'] += $trade->getGainEuro() ?? 0;
            $daily[$key]['rr'] += $trade->getGainRR() ?? 0;
            $daily[$key]['count']++;
        }

        if (empty($daily)) {
            return [];
        }

        $months = [];
        $cursor = (new \DateTimeImmutable(array_key_first($daily)))->modify('first day of this month');
        $end = (new \DateTimeImmutable(array_key_last($daily)))->modify('first day of this month');

        while ($cursor <= $end) {
            $months[] = $this->buildMonthCalendar($cursor, $daily);
            $cursor = $cursor->modify('+1 month');
        }

        // Mois le plus récent en premier, limité à 12 mois pour que chaque
        // nom de mois soit unique dans le sélecteur (pas besoin de l'année)
        return array_slice(array_reverse($months), 0, 12);
    }

    private function buildMonthCalendar(\DateTimeImmutable $monthStart, array $daily): array
    {
        $frMonths = [
            1 => 'Janvier', 2 => 'Février', 3 => 'Mars', 4 => 'Avril',
            5 => 'Mai', 6 => 'Juin', 7 => 'Juillet', 8 => 'Août',
            9 => 'Septembre', 10 => 'Octobre', 11 => 'Novembre', 12 => 'Décembre',
        ];

        $year = (int) $monthStart->format('Y');
        $monthNum = (int) $monthStart->format('n');
        $daysInMonth = (int) $monthStart->format('t');

        $month = [
            'label' => $frMonths[$monthNum] . ' ' . $year,
            'month_name' => $frMonths[$monthNum],
            'total_gain' => 0,
            'total_rr' => 0,
            'total_count' => 0,
            'weeks' => [],
        ];

        $emptyWeek = ['days' => array_fill(0, 5, null), 'gain' => 0, 'rr' => 0, 'count' => 0];
        $week = $emptyWeek;

        for ($d = 1; $d <= $daysInMonth; $d++) {
            $date = $monthStart->setDate($year, $monthNum, $d);
            $isoDay = (int) $date->format('N');
            $data = $daily[$date->format('Y-m-d')] ?? null;

            // Seuls lundi-vendredi ont une cellule ; les trades clôturés le
            // week-end comptent quand même dans les totaux semaine/mois
            if ($isoDay <= 5) {
                $week['days'][$isoDay - 1] = ['day' => $d, 'data' => $data];
            }

            if ($data !== null) {
                $week['gain'] += $data['gain'];
                $week['rr'] += $data['rr'];
                $week['count'] += $data['count'];
                $month['total_gain'] += $data['gain'];
                $month['total_rr'] += $data['rr'];
                $month['total_count'] += $data['count'];
            }

            if ($isoDay === 7 || $d === $daysInMonth) {
                $month['weeks'][] = $week;
                $week = $emptyWeek;
            }
        }

        return $month;
    }
}

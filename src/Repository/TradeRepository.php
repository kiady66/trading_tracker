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
            'max_gain_rr' => 0,
            'min_gain_rr' => 0,
            'winning_trades' => 0,
            'losing_trades' => 0,
            'win_rate' => 0,
            'gross_profit' => 0,
            'gross_loss' => 0,
            'profit_factor' => null,
            'gross_profit_rr' => 0,
            'gross_loss_rr' => 0,
            'profit_factor_rr' => null,
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

            if ($finalRR > 0) {
                $stats['gross_profit_rr'] += $finalRR;
            } elseif ($finalRR < 0) {
                $stats['gross_loss_rr'] += abs($finalRR);
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

            $stats['max_gain_rr'] = max($stats['max_gain_rr'], $finalRR);
            $stats['min_gain_rr'] = min($stats['min_gain_rr'], $finalRR);
        }

        $stats['current_streak'] = $winStreak > 0 ? $winStreak : -$lossStreak;

        if ($stats['gross_loss'] > 0) {
            $stats['profit_factor'] = $stats['gross_profit'] / $stats['gross_loss'];
        }

        if ($stats['gross_loss_rr'] > 0) {
            $stats['profit_factor_rr'] = $stats['gross_profit_rr'] / $stats['gross_loss_rr'];
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
        $cumulativeRRs = [];
        $drawdownRR = [];
        $cumulative = 0;
        $peak = 0;
        $cumulativeRR = 0;
        $peakRR = 0;

        // Ajouter un point de départ à 0
        if (count($trades) > 0) {
            $firstTrade = $trades[0];
            // Clone obligatoire : exitDate est mutable, modify() décalerait la date
            // de l'entité partagée pour tout le reste de la requête (calendrier compris)
            $firstDate = (clone $firstTrade->getExitDate())->modify('-1 day')->format('Y-m-d');

            $dates[] = $firstDate;
            $gainsEuro[] = 0;
            $cumulativeGains[] = 0;
            $finalRR[] = 0;
            $gainRR[] = 0;
            $drawdown[] = 0;
            $cumulativeRRs[] = 0;
            $drawdownRR[] = 0;
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

            $cumulativeRR += $gainRRValue;
            $cumulativeRRs[] = $cumulativeRR;

            $peakRR = max($peakRR, $cumulativeRR);
            $drawdownRR[] = $cumulativeRR - $peakRR;
        }

        return [
            'dates' => $dates,
            'gains_euro' => $gainsEuro,
            'cumulative_gains' => $cumulativeGains,
            'final_rr' => $finalRR,
            'gain_rr' => $gainRR,
            'drawdown' => $drawdown,
            'cumulative_rr' => $cumulativeRRs,
            'drawdown_rr' => $drawdownRR,
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

    /**
     * Trades visibles publiquement pour un profil partagé.
     *
     * - $statuses : sous-ensemble de ['open', 'closed'] selon les toggles du
     *   propriétaire — les trades "watching" ne sont jamais exposés ;
     * - $currentMonthOnly : borne les trades terminés sur exitDate >= 1er du
     *   mois courant ; les trades ouverts restent visibles quel que soit leur
     *   mois d'entrée ;
     * - $filters : filtres visiteur (start_date/end_date/confluences), à
     *   écrêter côté contrôleur avant l'appel. Les bornes de dates portent
     *   sur exitDate : ne pas en passer pour lister les trades ouverts.
     *
     * @return Trade[]
     */
    public function findPublicTrades($owner, array $statuses, bool $currentMonthOnly, array $filters = [], ?int $limit = null): array
    {
        $qb = $this->createPublicTradesQueryBuilder($owner, $statuses, $currentMonthOnly);

        if ($qb === null) {
            return [];
        }

        $this->applyFilters($qb, $filters);

        $qb->addSelect('COALESCE(t.exitDate, t.entryDate) AS HIDDEN sortDate')
            ->orderBy('sortDate', 'DESC');

        if ($limit !== null) {
            $qb->setMaxResults($limit);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Résumé agrégé des trades visibles d'un trader pour la liste /traders.
     *
     * Retourne null si aucun trade n'est visible (le trader est alors masqué
     * de la liste). Les métriques (total R, win rate) portent sur les trades
     * terminés visibles ; last_activity sert au tri « Activité récente ».
     */
    public function getPublicSummary($owner, array $statuses, bool $currentMonthOnly): ?array
    {
        $qb = $this->createPublicTradesQueryBuilder($owner, $statuses, $currentMonthOnly);

        if ($qb === null) {
            return null;
        }

        $row = $qb
            ->select('COUNT(t.id) AS trade_count')
            ->addSelect('MAX(COALESCE(t.exitDate, t.entryDate)) AS last_activity')
            ->addSelect("SUM(CASE WHEN t.status = 'closed' THEN COALESCE(t.gainRR, 0) ELSE 0 END) AS total_rr")
            ->addSelect("SUM(CASE WHEN t.status = 'closed' THEN 1 ELSE 0 END) AS closed_count")
            ->addSelect("SUM(CASE WHEN t.status = 'closed' AND t.gainRR > 0 THEN 1 ELSE 0 END) AS winning_count")
            ->getQuery()
            ->getSingleResult();

        if ((int) $row['trade_count'] === 0) {
            return null;
        }

        return [
            'trade_count' => (int) $row['trade_count'],
            'last_activity' => $row['last_activity'] !== null ? new \DateTimeImmutable($row['last_activity']) : null,
            'total_rr' => (float) ($row['total_rr'] ?? 0),
            'closed_count' => (int) ($row['closed_count'] ?? 0),
            'win_rate' => ((int) ($row['closed_count'] ?? 0)) > 0
                ? ((int) $row['winning_count'] / (int) $row['closed_count']) * 100
                : null,
        ];
    }

    private function createPublicTradesQueryBuilder($owner, array $statuses, bool $currentMonthOnly): ?QueryBuilder
    {
        $statuses = array_values(array_intersect($statuses, ['open', 'closed']));

        if ($statuses === []) {
            return null;
        }

        $qb = $this->createQueryBuilder('t')
            ->andWhere('t.user = :owner')
            ->setParameter('owner', $owner)
            ->andWhere('t.status IN (:statuses)')
            ->setParameter('statuses', $statuses);

        if ($currentMonthOnly) {
            $monthStart = (new \DateTimeImmutable('first day of this month'))->setTime(0, 0);
            $qb->andWhere("t.status = 'open' OR (t.status = 'closed' AND t.exitDate >= :monthStart)")
                ->setParameter('monthStart', $monthStart);
        }

        return $qb;
    }

    private function applyFilters(QueryBuilder $qb, array $filters): void
    {
        if (!empty($filters['user'])) {
            $qb->andWhere('t.user = :user')
                ->setParameter('user', $filters['user']);
        }

        if (!empty($filters['month_start'])) {
            $qb->andWhere('t.exitDate >= :month_start')
                ->setParameter('month_start', $filters['month_start']);
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

<?php

namespace App\Service;

use App\Repository\TradeRepository;

/**
 * Construit les données de la page statistiques dans l'unité demandée (€ ou R).
 *
 * En mode R, aucune valeur en euro n'est présente dans le résultat : les pages
 * publiques (qui forcent le mode R) ne peuvent donc rien faire fuiter.
 */
class StatsProvider
{
    public const UNIT_EURO = 'eur';
    public const UNIT_RR = 'rr';

    public function __construct(private readonly TradeRepository $tradeRepository)
    {
    }

    public static function normalizeUnit(?string $unit): string
    {
        return $unit === self::UNIT_RR ? self::UNIT_RR : self::UNIT_EURO;
    }

    public function build(array $filters, string $unit): array
    {
        $unit = self::normalizeUnit($unit);
        $isRR = $unit === self::UNIT_RR;

        return [
            'unit' => $unit,
            'unit_label' => $isRR ? 'R' : '€',
            'stats' => $this->normalizeStats($this->tradeRepository->getStatistics($filters), $isRR),
            'chart_data' => $this->normalizeChartData($this->tradeRepository->getChartData($filters), $isRR),
            'confluence_stats' => $this->normalizeConfluenceStats($this->tradeRepository->getConfluenceStats($filters), $isRR),
            'day_stats' => $this->normalizeDayStats($this->tradeRepository->getDayStats($filters), $isRR),
            'calendar_data' => $this->normalizeCalendarData($this->tradeRepository->getCalendarData($filters), $isRR),
        ];
    }

    private function normalizeStats(array $stats, bool $isRR): array
    {
        if ($isRR) {
            $stats['total_gain'] = $stats['total_gain_rr'];
            $stats['avg_gain'] = $stats['avg_gain_rr'];
            $stats['max_gain'] = $stats['max_gain_rr'];
            $stats['min_gain'] = $stats['min_gain_rr'];
            $stats['gross_profit'] = $stats['gross_profit_rr'];
            $stats['gross_loss'] = $stats['gross_loss_rr'];
            $stats['profit_factor'] = $stats['profit_factor_rr'];
            $stats['max_drawdown'] = $stats['max_drawdown_rr'];

            unset(
                $stats['total_gain_euro'],
                $stats['avg_gain_euro'],
                $stats['max_gain_euro'],
                $stats['min_gain_euro'],
                $stats['max_drawdown_euro'],
            );
        } else {
            $stats['total_gain'] = $stats['total_gain_euro'];
            $stats['avg_gain'] = $stats['avg_gain_euro'];
            $stats['max_gain'] = $stats['max_gain_euro'];
            $stats['min_gain'] = $stats['min_gain_euro'];
            $stats['max_drawdown'] = $stats['max_drawdown_euro'];
        }

        return $stats;
    }

    private function normalizeChartData(array $chartData, bool $isRR): array
    {
        if ($isRR) {
            $chartData['cumulative_gains'] = $chartData['cumulative_rr'];
            $chartData['drawdown'] = $chartData['drawdown_rr'];
            unset($chartData['gains_euro']);
        }

        return $chartData;
    }

    private function normalizeConfluenceStats(array $confluenceStats, bool $isRR): array
    {
        if (!$isRR) {
            return $confluenceStats;
        }

        foreach ($confluenceStats as &$stats) {
            unset($stats['total_gain'], $stats['avg_gain']);
        }

        return $confluenceStats;
    }

    private function normalizeDayStats(array $dayStats, bool $isRR): array
    {
        if (!$isRR) {
            return $dayStats;
        }

        foreach ($dayStats as &$stats) {
            unset($stats['total_gain_euro'], $stats['avg_gain_euro']);
        }

        return $dayStats;
    }

    private function normalizeCalendarData(array $months, bool $isRR): array
    {
        if (!$isRR) {
            return $months;
        }

        foreach ($months as &$month) {
            $month['total_gain'] = $month['total_rr'];
            foreach ($month['weeks'] as &$week) {
                $week['gain'] = $week['rr'];
                foreach ($week['days'] as &$cell) {
                    if ($cell !== null && $cell['data'] !== null) {
                        $cell['data']['gain'] = $cell['data']['rr'];
                    }
                }
            }
        }

        return $months;
    }
}

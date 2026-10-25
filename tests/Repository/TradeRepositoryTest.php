<?php

namespace App\Tests\Repository;

use App\Entity\Trade;
use App\Repository\TradeRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class TradeRepositoryTest extends KernelTestCase
{
    private TradeRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->repository = static::getContainer()->get(TradeRepository::class);
    }

    private static function trade(float $gainEuro, float $gainRR): Trade
    {
        return (new Trade())
            ->setGainEuro($gainEuro)
            ->setGainRR($gainRR);
    }

    public function testComputeStatisticsWithoutTrades(): void
    {
        $stats = $this->repository->computeStatistics([]);

        $this->assertSame(0, $stats['total_trades']);
        $this->assertSame(0, $stats['avg_gain_rr']);
        $this->assertSame(0, $stats['avg_win_rr']);
        $this->assertSame(0, $stats['avg_loss_rr']);
        $this->assertNull($stats['profit_factor']);
    }

    public function testComputeStatisticsAverages(): void
    {
        $stats = $this->repository->computeStatistics([
            self::trade(200, 2),
            self::trade(300, 3),
            self::trade(-100, -1),
            self::trade(-150, -1.5),
        ]);

        $this->assertSame(4, $stats['total_trades']);
        $this->assertSame(2, $stats['winning_trades']);
        $this->assertSame(2, $stats['losing_trades']);

        // avg_gain_rr : tous les trades ; avg_win_rr / avg_loss_rr : wins et losses séparés
        $this->assertEqualsWithDelta(0.625, $stats['avg_gain_rr'], 1e-9);
        $this->assertEqualsWithDelta(2.5, $stats['avg_win_rr'], 1e-9);
        $this->assertEqualsWithDelta(-1.25, $stats['avg_loss_rr'], 1e-9);

        $this->assertEqualsWithDelta(2.0, $stats['profit_factor_rr'], 1e-9);
    }

    public function testBreakevenTradesExcludedFromWinLossAverages(): void
    {
        $stats = $this->repository->computeStatistics([
            self::trade(200, 2),
            self::trade(0, 0),
            self::trade(-100, -1),
        ]);

        $this->assertEqualsWithDelta(2.0, $stats['avg_win_rr'], 1e-9);
        $this->assertEqualsWithDelta(-1.0, $stats['avg_loss_rr'], 1e-9);
    }

    public function testComputeAssetStatsGroupsAndSortsByTradeCount(): void
    {
        $stats = $this->repository->computeAssetStats([
            self::trade(200, 2)->setAsset('XAU/USD'),
            self::trade(-100, -1)->setAsset('XAU/USD'),
            self::trade(300, 3)->setAsset('EUR/USD'),
        ]);

        $this->assertSame(['XAU/USD', 'EUR/USD'], array_keys($stats));

        $gold = $stats['XAU/USD'];
        $this->assertSame(2, $gold['total_trades']);
        $this->assertSame(1, $gold['winning_trades']);
        $this->assertEqualsWithDelta(50.0, $gold['win_rate'], 1e-9);
        $this->assertEqualsWithDelta(100.0, $gold['total_gain_euro'], 1e-9);
        $this->assertEqualsWithDelta(0.5, $gold['avg_gain_rr'], 1e-9);
    }

    private static function heldTrade(float $gainEuro, float $gainRR, string $entry, string $exit): Trade
    {
        return self::trade($gainEuro, $gainRR)
            ->setEntryDate(new \DateTime($entry))
            ->setExitDate(new \DateTime($exit));
    }

    public function testComputeHoldingStatsDurationsAndBuckets(): void
    {
        $stats = $this->repository->computeHoldingStats([
            // Gagnant intraday (6 h = 0,25 j) → tranche "moins d'1 jour"
            self::heldTrade(200, 2, '2026-01-05 09:00', '2026-01-05 15:00'),
            // Gagnant 2 jours → tranche "1 à 3 jours"
            self::heldTrade(100, 1, '2026-01-06 10:00', '2026-01-08 10:00'),
            // Perdant 5 jours → tranche "3 à 5 jours"
            self::heldTrade(-100, -1, '2026-01-09 10:00', '2026-01-14 10:00'),
            // Perdant 6 jours → tranche "5 à 7 jours"
            self::heldTrade(-100, -1, '2026-01-08 10:00', '2026-01-14 10:00'),
            // Perdant 10 jours → tranche "7 à 14 jours"
            self::heldTrade(-100, -1, '2026-01-05 10:00', '2026-01-15 10:00'),
            // Perdant 15 jours → tranche "plus de 14 jours"
            self::heldTrade(-100, -1, '2026-01-01 10:00', '2026-01-16 10:00'),
        ]);

        $this->assertSame(6, $stats['total_trades']);
        $this->assertEqualsWithDelta(1.125, $stats['avg_duration_win_days'], 1e-9); // (0,25 + 2) / 2
        $this->assertEqualsWithDelta(9.0, $stats['avg_duration_loss_days'], 1e-9); // (5 + 6 + 10 + 15) / 4

        // R/jour : -1 R / (1 + 2 + 5 + 6 + 10 + 15) jours — l'intraday compte 1 jour minimum
        $this->assertEqualsWithDelta(-1 / 39, $stats['rr_per_day'], 1e-9);

        $this->assertSame(1, $stats['buckets']['lt1']['total_trades']);
        $this->assertSame(1, $stats['buckets']['1to3']['total_trades']);
        $this->assertSame(1, $stats['buckets']['3to5']['total_trades']);
        $this->assertSame(1, $stats['buckets']['5to7']['total_trades']);
        $this->assertSame(1, $stats['buckets']['7to14']['total_trades']);
        $this->assertSame(1, $stats['buckets']['gt14']['total_trades']);

        $this->assertEqualsWithDelta(2.0, $stats['buckets']['lt1']['rr_per_day'], 1e-9); // 2 R / 1 j plancher
        $this->assertEqualsWithDelta(0.5, $stats['buckets']['1to3']['rr_per_day'], 1e-9); // 1 R / 2 j
        $this->assertEqualsWithDelta(100.0, $stats['buckets']['lt1']['win_rate'], 1e-9);
        $this->assertEqualsWithDelta(0.0, $stats['buckets']['gt14']['win_rate'], 1e-9);
    }
}

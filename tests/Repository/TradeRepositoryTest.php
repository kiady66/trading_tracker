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
}

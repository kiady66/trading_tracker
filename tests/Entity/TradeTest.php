<?php

namespace App\Tests\Entity;

use App\Entity\Confluence;
use App\Entity\Timeframe;
use App\Entity\Trade;
use App\Entity\TradeError;
use App\Entity\TradeScreenshot;
use App\Entity\TradeType;
use App\Entity\Trend;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

class TradeTest extends TestCase
{
    private Trade $trade;

    protected function setUp(): void
    {
        $this->trade = new Trade();
    }

    public function testConstructorInitializesCollections(): void
    {
        $this->assertCount(0, $this->trade->getTimeframes());
        $this->assertCount(0, $this->trade->getConfluences());
        $this->assertCount(0, $this->trade->getScreenshots());
    }

    public function testConstructorSetsWatchlistDate(): void
    {
        $this->assertInstanceOf(\DateTimeInterface::class, $this->trade->getWatchlistDate());
    }

    public function testConstructorSetsDefaultStatus(): void
    {
        $this->assertSame('watching', $this->trade->getStatus());
    }

    public function testSetAndGetAsset(): void
    {
        $asset = 'EUR/USD';
        $result = $this->trade->setAsset($asset);

        $this->assertSame($this->trade, $result);
        $this->assertSame($asset, $this->trade->getAsset());
    }

    public function testSetAssetWithInvalidAssetThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid asset "INVALID/PAIR"');

        $this->trade->setAsset('INVALID/PAIR');
    }

    public function testSetAssetWithAllAllowedAssets(): void
    {
        foreach (Trade::ALLOWED_ASSETS as $asset) {
            $this->trade->setAsset($asset);
            $this->assertSame($asset, $this->trade->getAsset());
        }
    }

    public function testSetAndGetOrderType(): void
    {
        $orderType = 'LIMIT';
        $result = $this->trade->setOrderType($orderType);

        $this->assertSame($this->trade, $result);
        $this->assertSame($orderType, $this->trade->getOrderType());
    }

    public function testSetAndGetRiskPercentage(): void
    {
        $riskPercentage = 2.5;
        $result = $this->trade->setRiskPercentage($riskPercentage);

        $this->assertSame($this->trade, $result);
        $this->assertSame($riskPercentage, $this->trade->getRiskPercentage());
    }

    public function testCalculateStatusWhenWatching(): void
    {
        $this->trade->calculateStatus();

        $this->assertSame('watching', $this->trade->getStatus());
    }

    public function testCalculateStatusWhenOpen(): void
    {
        $this->trade->setEntryDate(new \DateTime('2024-01-01'));

        $this->assertSame('open', $this->trade->getStatus());
    }

    public function testCalculateStatusWhenClosed(): void
    {
        $this->trade->setEntryDate(new \DateTime('2024-01-01'));
        $this->trade->setExitDate(new \DateTime('2024-01-02'));

        $this->assertSame('closed', $this->trade->getStatus());
    }

    public function testCalculateDayFromEntryDate(): void
    {
        $monday = new \DateTime('2024-01-01');
        $this->trade->setEntryDate($monday);

        $this->assertSame('Monday', $this->trade->getDay());
    }

    public function testCalculateDayWithNullEntryDate(): void
    {
        $this->trade->setDay('Friday');
        $this->trade->calculateDay();

        $this->assertSame('Friday', $this->trade->getDay());
    }

    public function testCalculateGainRR(): void
    {
        $this->trade->setRiskPercentage(2.0);
        $this->trade->setFinalRR(3.5);

        $expectedGain = 3.5 * (2.0 / 100);
        $this->assertSame($expectedGain, $this->trade->getGainRR());
    }

    public function testCalculateGainRRWithNullValues(): void
    {
        $this->trade->setRiskPercentage(2.0);
        $this->trade->setFinalRR(null);
        $this->trade->calculateGainRR();

        $this->assertNull($this->trade->getGainRR());
    }

    public function testCalculateGainEuro(): void
    {
        $this->trade->setRiskPercentage(2.0);
        $this->trade->setFinalRR(3.0);
        $this->trade->setMaxRiskEuro(100.0);

        $expectedGainRR = 3.0 * (2.0 / 100);
        $expectedGainEuro = $expectedGainRR * 100.0;

        $this->assertSame($expectedGainEuro, $this->trade->getGainEuro());
    }

    public function testCalculateGainEuroWithNullGainRR(): void
    {
        $this->trade->setMaxRiskEuro(100.0);
        $this->trade->calculateGainEuro();

        $this->assertNull($this->trade->getGainEuro());
    }

    public function testAddTimeframe(): void
    {
        $timeframe = $this->createMock(Timeframe::class);
        $result = $this->trade->addTimeframe($timeframe);

        $this->assertSame($this->trade, $result);
        $this->assertCount(1, $this->trade->getTimeframes());
        $this->assertTrue($this->trade->getTimeframes()->contains($timeframe));
    }

    public function testAddTimeframeDoesNotDuplicateExisting(): void
    {
        $timeframe = $this->createMock(Timeframe::class);
        $this->trade->addTimeframe($timeframe);
        $this->trade->addTimeframe($timeframe);

        $this->assertCount(1, $this->trade->getTimeframes());
    }

    public function testRemoveTimeframe(): void
    {
        $timeframe = $this->createMock(Timeframe::class);
        $this->trade->addTimeframe($timeframe);
        $result = $this->trade->removeTimeframe($timeframe);

        $this->assertSame($this->trade, $result);
        $this->assertCount(0, $this->trade->getTimeframes());
    }

    public function testAddConfluence(): void
    {
        $confluence = $this->createMock(Confluence::class);
        $result = $this->trade->addConfluence($confluence);

        $this->assertSame($this->trade, $result);
        $this->assertCount(1, $this->trade->getConfluences());
    }

    public function testRemoveConfluence(): void
    {
        $confluence = $this->createMock(Confluence::class);
        $this->trade->addConfluence($confluence);
        $result = $this->trade->removeConfluence($confluence);

        $this->assertSame($this->trade, $result);
        $this->assertCount(0, $this->trade->getConfluences());
    }

    public function testAddScreenshot(): void
    {
        $screenshot = $this->createMock(TradeScreenshot::class);
        $screenshot->expects($this->once())
            ->method('setTrade')
            ->with($this->trade);

        $result = $this->trade->addScreenshot($screenshot);

        $this->assertSame($this->trade, $result);
        $this->assertCount(1, $this->trade->getScreenshots());
    }

    public function testRemoveScreenshot(): void
    {
        $screenshot = $this->createMock(TradeScreenshot::class);
        $screenshot->expects($this->exactly(2))
            ->method('setTrade')
            ->with($this->callback(function ($trade) {
                return $trade === $this->trade || $trade === null;
            }));
        $screenshot->expects($this->once())
            ->method('getTrade')
            ->willReturn($this->trade);

        $this->trade->addScreenshot($screenshot);
        $result = $this->trade->removeScreenshot($screenshot);

        $this->assertSame($this->trade, $result);
        $this->assertCount(0, $this->trade->getScreenshots());
    }

    public function testGetScreenshotsByCategory(): void
    {
        $screenshot1 = $this->createMock(TradeScreenshot::class);
        $screenshot1->method('getCategory')->willReturn('execution');
        $screenshot1->method('setTrade');

        $screenshot2 = $this->createMock(TradeScreenshot::class);
        $screenshot2->method('getCategory')->willReturn('management');
        $screenshot2->method('setTrade');

        $screenshot3 = $this->createMock(TradeScreenshot::class);
        $screenshot3->method('getCategory')->willReturn('execution');
        $screenshot3->method('setTrade');

        $this->trade->addScreenshot($screenshot1);
        $this->trade->addScreenshot($screenshot2);
        $this->trade->addScreenshot($screenshot3);

        $executionScreenshots = $this->trade->getScreenshotsByCategory('execution');

        $this->assertCount(2, $executionScreenshots);
    }

    public function testSetAndGetUser(): void
    {
        $user = $this->createMock(User::class);
        $result = $this->trade->setUser($user);

        $this->assertSame($this->trade, $result);
        $this->assertSame($user, $this->trade->getUser());
    }

    public function testSetAndGetTradeType(): void
    {
        $tradeType = $this->createMock(TradeType::class);
        $result = $this->trade->setTradeType($tradeType);

        $this->assertSame($this->trade, $result);
        $this->assertSame($tradeType, $this->trade->getTradeType());
    }

    public function testSetAndGetTrend(): void
    {
        $trend = $this->createMock(Trend::class);
        $result = $this->trade->setTrend($trend);

        $this->assertSame($this->trade, $result);
        $this->assertSame($trend, $this->trade->getTrend());
    }

    public function testSetAndGetError(): void
    {
        $error = $this->createMock(TradeError::class);
        $result = $this->trade->setError($error);

        $this->assertSame($this->trade, $result);
        $this->assertSame($error, $this->trade->getError());
    }

    public function testIsTradeManagement(): void
    {
        $this->assertFalse($this->trade->isTradeManagement());

        $this->trade->setTradeManagement(true);
        $this->assertTrue($this->trade->isTradeManagement());
    }

    public function testIsGoodTrade(): void
    {
        $this->assertNull($this->trade->isGoodTrade());

        $this->trade->setGoodTrade(true);
        $this->assertTrue($this->trade->isGoodTrade());

        $this->trade->setGoodTrade(false);
        $this->assertFalse($this->trade->isGoodTrade());
    }

    public function testSetAndGetExecutionReason(): void
    {
        $reason = 'Strong support level';
        $result = $this->trade->setExecutionReason($reason);

        $this->assertSame($this->trade, $result);
        $this->assertSame($reason, $this->trade->getExecutionReason());
    }

    public function testSetAndGetNoteErrors(): void
    {
        $note = 'Entered too early';
        $result = $this->trade->setNoteErrors($note);

        $this->assertSame($this->trade, $result);
        $this->assertSame($note, $this->trade->getNoteErrors());
    }

    public function testSetAndGetExecutionScreenshots(): void
    {
        $screenshots = ['screenshot1.png', 'screenshot2.png'];
        $result = $this->trade->setExecutionScreenshots($screenshots);

        $this->assertSame($this->trade, $result);
        $this->assertSame($screenshots, $this->trade->getExecutionScreenshots());
    }

    public function testSetAndGetManagementScreenshots(): void
    {
        $screenshots = ['screenshot3.png'];
        $result = $this->trade->setManagementScreenshots($screenshots);

        $this->assertSame($this->trade, $result);
        $this->assertSame($screenshots, $this->trade->getManagementScreenshots());
    }

    public function testSetAndGetClosingScreenshots(): void
    {
        $screenshots = ['screenshot4.png'];
        $result = $this->trade->setClosingScreenshots($screenshots);

        $this->assertSame($this->trade, $result);
        $this->assertSame($screenshots, $this->trade->getClosingScreenshots());
    }

    public function testGetIdReturnsNullForNewEntity(): void
    {
        $this->assertNull($this->trade->getId());
    }
}
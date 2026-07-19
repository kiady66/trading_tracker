<?php

namespace App\Dto;

use App\Entity\Trade;
use App\Entity\TradeScreenshot;

/**
 * Vue publique d'un trade : la seule structure passée aux templates /traders.
 *
 * Ne contient volontairement aucun champ monétaire (gainEuro, maxRiskEuro)
 * ni aucune donnée privée (error, noteErrors, goodTrade, tradeQuality,
 * tradeManagement, ctraderPositionId) — tout est exprimé en R.
 */
final readonly class PublicTradeView
{
    /**
     * @param string[] $timeframes
     * @param string[] $confluences
     * @param string[] $executionScreenshots
     * @param string[] $managementScreenshots
     * @param string[] $closingScreenshots
     */
    private function __construct(
        public int $id,
        public string $asset,
        public ?string $orderType,
        public ?\DateTimeInterface $entryDate,
        public ?\DateTimeInterface $exitDate,
        public ?string $day,
        public string $status,
        public array $timeframes,
        public ?string $trend,
        public ?string $tradeType,
        public array $confluences,
        public ?float $initialRR,
        public ?float $finalRR,
        public ?float $gainRR,
        public ?float $riskPercentage,
        public ?string $executionReason,
        public array $executionScreenshots,
        public array $managementScreenshots,
        public array $closingScreenshots,
    ) {
    }

    public static function fromTrade(Trade $trade): self
    {
        return new self(
            id: $trade->getId(),
            asset: $trade->getAsset(),
            orderType: $trade->getOrderType(),
            entryDate: $trade->getEntryDate(),
            exitDate: $trade->getExitDate(),
            day: $trade->getDay(),
            status: $trade->getStatus(),
            timeframes: array_values(array_map(
                static fn ($timeframe) => $timeframe->getName(),
                $trade->getTimeframes()->toArray()
            )),
            trend: $trade->getTrend()?->getName(),
            tradeType: $trade->getTradeType()?->getName(),
            confluences: array_values(array_map(
                static fn ($confluence) => $confluence->getName(),
                $trade->getConfluences()->toArray()
            )),
            initialRR: $trade->getInitialRR(),
            finalRR: $trade->getFinalRR(),
            gainRR: $trade->getGainRR(),
            riskPercentage: $trade->getRiskPercentage(),
            executionReason: $trade->getExecutionReason(),
            executionScreenshots: self::screenshotFilenames($trade, 'execution'),
            managementScreenshots: self::screenshotFilenames($trade, 'management'),
            closingScreenshots: self::screenshotFilenames($trade, 'closing'),
        );
    }

    /**
     * @return string[]
     */
    private static function screenshotFilenames(Trade $trade, string $category): array
    {
        $screenshots = $trade->getScreenshotsByCategory($category);
        usort($screenshots, static fn (TradeScreenshot $a, TradeScreenshot $b) => $a->getPosition() <=> $b->getPosition());

        return array_values(array_map(
            static fn (TradeScreenshot $screenshot) => $screenshot->getFilename(),
            $screenshots
        ));
    }
}

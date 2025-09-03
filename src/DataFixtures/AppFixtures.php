<?php

namespace App\DataFixtures;

use App\Entity\Timeframe;
use App\Entity\Confluence;
use App\Entity\Setup;
use App\Entity\TradeError;
use App\Entity\TradeType;
use App\Entity\Trend;
use App\Entity\Result;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

class AppFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        // Timeframes
        $timeframes = ['1m', '5m', '15m', '30m', '1h', '4h', '1d', '1w'];
        foreach ($timeframes as $name) {
            $timeframe = new Timeframe();
            $timeframe->setName($name);
            $manager->persist($timeframe);
        }

        // Results
        $results = ['Gagnant', 'Perdant', 'Break-even'];
        foreach ($results as $name) {
            $result = new Result();
            $result->setName($name);
            $manager->persist($result);
        }

        // Trade Types
        $tradeTypes = ['Swing', 'Day Trade', 'Scalping', 'Position'];
        foreach ($tradeTypes as $name) {
            $tradeType = new TradeType();
            $tradeType->setName($name);
            $manager->persist($tradeType);
        }

        // Trends
        $trends = ['Hausier', 'Baissier', 'Sideways'];
        foreach ($trends as $name) {
            $trend = new Trend();
            $trend->setName($name);
            $manager->persist($trend);
        }

        // Errors
        $errors = ['Overtrading', 'Revenge Trading', 'Mauvais Risk Management', 'Emotions'];
        foreach ($errors as $name) {
            $error = new TradeError();
            $error->setName($name);
            $manager->persist($error);
        }

        // Confluences
        $confluences = ['Support/Resistance', 'Fibonacci', 'Moving Average', 'Volume', 'Pattern'];
        foreach ($confluences as $name) {
            $confluence = new Confluence();
            $confluence->setName($name);
            $manager->persist($confluence);
        }

        // Setups
        $setups = ['Breakout', 'Pullback', 'Reversal', 'Continuation'];
        foreach ($setups as $name) {
            $setup = new Setup();
            $setup->setName($name);
            $manager->persist($setup);
        }

        $manager->flush();
    }
}

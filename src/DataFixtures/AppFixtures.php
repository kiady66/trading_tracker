<?php

namespace App\DataFixtures;

use App\Entity\Timeframe;
use App\Entity\Confluence;
use App\Entity\TradeError;
use App\Entity\TradeType;
use App\Entity\Trend;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

/**
 * Données de référence. Idempotent : chaque valeur n'est créée que si elle
 * n'existe pas déjà (lookup par nom), ce qui rend le chargement avec
 * --append sans danger pour une base contenant déjà des données.
 */
class AppFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        $this->createMissing($manager, Timeframe::class, ['15min', '30 min', '1h', '4h']);
        $this->createMissing($manager, TradeType::class, ['Continuation', 'Reversal']);
        $this->createMissing($manager, Trend::class, ['Haussier', 'Baissier', 'Range']);
        $this->createMissing($manager, TradeError::class, ['Overtrading', 'Revenge Trading', 'Mauvais Risk Management', 'Emotions']);
        $this->createMissing($manager, Confluence::class, ['Support/Resistance', 'Fibonacci', 'Moving Average', 'Volume', 'Pattern']);

        $manager->flush();
    }

    /**
     * @param class-string $class
     * @param string[] $names
     */
    private function createMissing(ObjectManager $manager, string $class, array $names): void
    {
        $repository = $manager->getRepository($class);

        foreach ($names as $name) {
            if ($repository->findOneBy(['name' => $name]) !== null) {
                continue;
            }

            $entity = new $class();
            $entity->setName($name);
            $manager->persist($entity);
        }
    }
}

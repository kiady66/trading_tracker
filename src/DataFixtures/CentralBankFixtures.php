<?php

namespace App\DataFixtures;

use App\Entity\CentralBank;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

/**
 * Banques centrales de l'horloge des cycles de taux. Idempotent : chaque
 * banque n'est créée que si son code n'existe pas déjà, ce qui rend le
 * chargement avec --append sans danger pour une base contenant déjà des
 * données (les positions modifiées par l'utilisateur ne sont pas écrasées).
 *
 * À charger avec : symfony console doctrine:fixtures:load --append
 */
class CentralBankFixtures extends Fixture
{
    private const BANKS = [
        ['code' => 'RBA',  'rate' => '4,35 %',      'angle' => 308.0, 'bias' => 'neutre',  'retracement' => false],
        ['code' => 'BoJ',  'rate' => '1,00 %',      'angle' => 288.0, 'bias' => 'hawkish', 'retracement' => false],
        ['code' => 'RBNZ', 'rate' => '2,75 %',      'angle' => 266.0, 'bias' => 'hawkish', 'retracement' => true],
        ['code' => 'BCE',  'rate' => '2,25 %',      'angle' => 246.0, 'bias' => 'neutre',  'retracement' => true],
        ['code' => 'BoE',  'rate' => '3,75 %',      'angle' => 218.0, 'bias' => 'hawkish', 'retracement' => false],
        ['code' => 'Fed',  'rate' => '3,50-3,75 %', 'angle' => 199.0, 'bias' => 'hawkish', 'retracement' => true],
        ['code' => 'BoC',  'rate' => '2,25 %',      'angle' => 180.0, 'bias' => 'neutre',  'retracement' => false],
        ['code' => 'SNB',  'rate' => '0,00 %',      'angle' => 161.0, 'bias' => 'neutre',  'retracement' => false],
    ];

    public function load(ObjectManager $manager): void
    {
        $repository = $manager->getRepository(CentralBank::class);

        foreach (self::BANKS as $position => $data) {
            if ($repository->findOneBy(['code' => $data['code']]) !== null) {
                continue;
            }

            $bank = (new CentralBank())
                ->setCode($data['code'])
                ->setRate($data['rate'])
                ->setAngle($data['angle'])
                ->setBias($data['bias'])
                ->setRetracement($data['retracement'])
                ->setPosition($position);
            $manager->persist($bank);
        }

        $manager->flush();
    }
}

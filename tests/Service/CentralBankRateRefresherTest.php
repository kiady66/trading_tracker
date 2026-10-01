<?php

namespace App\Tests\Service;

use App\Entity\CentralBank;
use App\Repository\CentralBankRepository;
use App\Service\CentralBankRateRefresher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class CentralBankRateRefresherTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private CentralBankRateRefresher $refresher;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->executeStatement('TRUNCATE central_bank RESTART IDENTITY CASCADE');
        $this->refresher = static::getContainer()->get(CentralBankRateRefresher::class);

        $this->createBank('Fed', '3,50-3,75 %', 199.0, 'hawkish', 0);
        $this->createBank('BCE', '2,25 %', 246.0, 'neutre', 1);
    }

    private function createBank(string $code, string $rate, float $angle, string $bias, int $position): void
    {
        $this->em->persist((new CentralBank())
            ->setCode($code)->setRate($rate)->setAngle($angle)->setBias($bias)->setPosition($position));
        $this->em->flush();
    }

    public function testPromptListsEveryBankInBase(): void
    {
        $prompt = $this->refresher->buildPrompt();

        $this->assertStringContainsString('- "Fed" : Federal Reserve', $prompt);
        $this->assertStringContainsString('- "BCE" : Banque centrale européenne', $prompt);
        $this->assertStringContainsString('{"banks":[', $prompt);
    }

    public function testParseAcceptsFencedJsonAndNormalizesRates(): void
    {
        $output = <<<'OUT'
        Voici les données :
        ```json
        {"banks":[
          {"code":"Fed","rate":"4.00–4.25%","bias":"dovish","last_decision":"2026-09-17","note":"Baisse de 25 pb."},
          {"code":"BCE","rate":"2,00 %","bias":"bullish","note":null},
          {"code":"BoE","rate":null,"bias":"neutre"}
        ]}
        ```
        OUT;

        $parsed = $this->refresher->parse($output);

        $this->assertSame(['Fed', 'BCE', 'BoE'], array_keys($parsed));
        $this->assertSame('4,00-4,25 %', $parsed['Fed']['rate']);
        $this->assertSame('dovish', $parsed['Fed']['bias']);
        $this->assertSame('2,00 %', $parsed['BCE']['rate']);
        // Valeurs hors vocabulaire ignorées plutôt que d'écraser la base
        $this->assertNull($parsed['BCE']['bias']);
        $this->assertNull($parsed['BoE']['rate']);
    }

    public function testParseRejectsOutputWithoutJson(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->refresher->parse('Désolé, je ne trouve pas ces informations.');
    }

    public function testApplyUpdatesRateAndBiasButNeverTheDial(): void
    {
        $changes = $this->refresher->apply([
            'Fed' => ['rate' => '4,00-4,25 %', 'bias' => 'dovish', 'last_decision' => null, 'note' => null],
            'BCE' => ['rate' => '2,25 %', 'bias' => 'neutre', 'last_decision' => null, 'note' => null],
        ]);

        $this->assertSame([
            'Fed : taux 3,50-3,75 % → 4,00-4,25 %',
            'Fed : biais hawkish → dovish',
        ], $changes);

        $this->em->clear();
        $repository = static::getContainer()->get(CentralBankRepository::class);
        $fed = $repository->findOneBy(['code' => 'Fed']);
        $this->assertSame('4,00-4,25 %', $fed->getRate());
        $this->assertSame('dovish', $fed->getBias());
        // La position sur le cadran appartient à l'utilisateur
        $this->assertSame(199.0, $fed->getAngle());
        $this->assertSame(246.0, $repository->findOneBy(['code' => 'BCE'])->getAngle());
    }

    public function testDryRunAndMissingBankLeaveDatabaseUntouched(): void
    {
        $changes = $this->refresher->apply([
            'Fed' => ['rate' => '4,00-4,25 %', 'bias' => null, 'last_decision' => null, 'note' => null],
        ], dryRun: true);

        $this->assertSame(['Fed : taux 3,50-3,75 % → 4,00-4,25 %', 'BCE : absent de la réponse, inchangé'], $changes);

        $this->em->clear();
        $this->assertSame('3,50-3,75 %', static::getContainer()->get(CentralBankRepository::class)->findOneBy(['code' => 'Fed'])->getRate());
    }
}

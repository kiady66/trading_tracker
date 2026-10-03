<?php

namespace App\Tests\Service;

use App\Entity\BiasPair;
use App\Entity\CentralBank;
use App\Entity\User;
use App\Repository\CentralBankRepository;
use App\Service\BiasPairList;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class BiasPairListTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private BiasPairList $list;
    private User $user;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->executeStatement('TRUNCATE "user" RESTART IDENTITY CASCADE');
        $this->em->getConnection()->executeStatement('TRUNCATE central_bank RESTART IDENTITY CASCADE');
        $this->list = static::getContainer()->get(BiasPairList::class);

        $this->user = new User();
        $this->user->setEmail('bob@test.com');
        $this->user->setPassword('irrelevant');
        $this->user->setDisplayName('bob');
        $this->em->persist($this->user);

        foreach (BiasPairList::CURRENCY_BANKS as $bankCode) {
            $this->em->persist((new CentralBank())->setCode($bankCode)->setRate('2,00 %')->setBias('neutre'));
        }
        $this->em->flush();
    }

    private function setBias(string $currency, string $bias): void
    {
        static::getContainer()->get(CentralBankRepository::class)
            ->findOneBy(['code' => BiasPairList::CURRENCY_BANKS[$currency]])
            ->setBias($bias);
        $this->em->flush();
    }

    /** @return list<string> */
    private function pairs(): array
    {
        return array_map(static fn (BiasPair $p) => $p->getPair(), $this->list->sync($this->user));
    }

    public function testFxPairsExcludeMetalsCryptoAndIndices(): void
    {
        $pairs = BiasPairList::fxPairs();

        $this->assertCount(28, $pairs);
        $this->assertContains('GBP/AUD', $pairs);
        $this->assertNotContains('XAU/USD', $pairs);
        $this->assertNotContains('BTC/USD', $pairs);
        $this->assertNotContains('SP500', $pairs);
    }

    public function testDivergentPairsIgnoreNeutralCurrencies(): void
    {
        $pairs = BiasPairList::divergentPairs(['USD' => 'hawkish', 'EUR' => 'dovish', 'JPY' => 'dovish', 'GBP' => 'neutre']);

        // Ordre de Trade::ALLOWED_ASSETS ; EUR/JPY (dovish/dovish) et GBP/USD (neutre) exclus
        $this->assertSame(['EUR/USD', 'USD/JPY'], $pairs);
    }

    public function testSyncKeepsUserOrderAndAppendsReturningPairsAtTheBottom(): void
    {
        $this->setBias('USD', 'hawkish');
        $this->setBias('EUR', 'dovish');
        $this->setBias('JPY', 'dovish');
        $this->assertSame(['EUR/USD', 'USD/JPY'], $this->pairs());

        // L'utilisateur inverse l'ordre
        $this->list->reorder($this->user, ['USD/JPY', 'EUR/USD']);
        $this->assertSame(['USD/JPY', 'EUR/USD'], $this->pairs());

        // GBP devient dovish : ses paires contre USD arrivent en bas, l'ordre existant est intact
        $this->setBias('GBP', 'dovish');
        $this->assertSame(['USD/JPY', 'EUR/USD', 'GBP/USD'], $this->pairs());

        // EUR repasse neutre : EUR/USD sort ; puis redevient dovish : EUR/USD revient en bas
        $this->setBias('EUR', 'neutre');
        $this->assertSame(['USD/JPY', 'GBP/USD'], $this->pairs());
        $this->setBias('EUR', 'dovish');
        $this->assertSame(['USD/JPY', 'GBP/USD', 'EUR/USD'], $this->pairs());

        $this->assertSame(3, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM bias_pair'));
    }

    public function testReorderRejectsAListThatDoesNotMatchTheCurrentPairs(): void
    {
        $this->setBias('USD', 'hawkish');
        $this->setBias('EUR', 'dovish');
        $this->list->sync($this->user);

        $this->expectException(\InvalidArgumentException::class);
        $this->list->reorder($this->user, ['EUR/USD', 'GBP/USD']);
    }
}

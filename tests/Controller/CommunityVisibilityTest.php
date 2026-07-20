<?php

namespace App\Tests\Controller;

use App\Entity\Trade;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Tests d'accès de la visibilité communautaire : c'est une feature de
 * confidentialité, chaque règle est vérifiée côté serveur (404, filtrage SQL).
 */
class CommunityVisibilityTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->executeStatement('TRUNCATE "user" RESTART IDENTITY CASCADE');
    }

    private function createUser(
        string $email,
        ?string $pseudo = null,
        bool $shareEnabled = false,
        bool $shareStats = true,
        bool $shareOpenTrades = true,
        bool $shareClosedTrades = true,
        bool $shareCurrentMonthOnly = false,
    ): User {
        $user = new User();
        $user->setEmail($email);
        $user->setPassword('irrelevant-for-loginUser');
        // Le pseudo est obligatoire depuis l'onboarding : un utilisateur sans
        // pseudo serait redirigé vers /onboarding sur toutes les pages.
        $user->setDisplayName($pseudo ?? $this->pseudoFromEmail($email));
        $user->setShareEnabled($shareEnabled);
        $user->setShareStats($shareStats);
        $user->setShareOpenTrades($shareOpenTrades);
        $user->setShareClosedTrades($shareClosedTrades);
        $user->setShareCurrentMonthOnly($shareCurrentMonthOnly);

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function pseudoFromEmail(string $email): string
    {
        return preg_replace('/[^a-zA-Z0-9_-]/', '', strstr($email, '@', true));
    }

    private function createTrade(
        User $owner,
        ?\DateTime $entryDate,
        ?\DateTime $exitDate,
        float $finalRR = 2.0,
        string $asset = 'EUR/USD',
    ): Trade {
        $trade = new Trade();
        $trade->setUser($owner);
        $trade->setAsset($asset);
        $trade->setOrderType('buy');
        // riskPercentage à 100 => gainRR == finalRR (lisible dans les asserts)
        $trade->setRiskPercentage(100.0);
        $trade->setMaxRiskEuro(98765.0);
        $trade->setFinalRR($finalRR);
        $trade->setEntryDate($entryDate);
        $trade->setExitDate($exitDate);

        $this->em->persist($trade);
        $this->em->flush();

        return $trade;
    }

    private function loginAs(User $user): void
    {
        $this->client->loginUser($user, 'main');
    }

    private static function thisMonth(int $day): \DateTime
    {
        return (new \DateTime('first day of this month'))->setTime(10, 0)->modify(sprintf('+%d days', $day - 1));
    }

    private static function lastMonth(int $day): \DateTime
    {
        return (new \DateTime('first day of last month'))->setTime(10, 0)->modify(sprintf('+%d days', $day - 1));
    }

    public function testPrivateProfileIsNotFoundAndAbsentFromList(): void
    {
        $owner = $this->createUser('ghost@test.com', 'ghost', shareEnabled: false);
        $trade = $this->createTrade($owner, self::thisMonth(2), self::thisMonth(3));
        $visitor = $this->createUser('visitor@test.com');
        $this->loginAs($visitor);

        $this->client->request('GET', '/traders/ghost');
        $this->assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/traders/ghost/trades/' . $trade->getId());
        $this->assertResponseStatusCodeSame(404);

        $crawler = $this->client->request('GET', '/traders');
        $this->assertResponseIsSuccessful();
        $this->assertStringNotContainsString('ghost', $crawler->filter('body')->text());
    }

    public function testClosedTradesToggleOffHidesThemEverywhere(): void
    {
        $owner = $this->createUser('bob@test.com', 'bob', shareEnabled: true, shareClosedTrades: false);
        $closed = $this->createTrade($owner, self::thisMonth(2), self::thisMonth(3), asset: 'BTC/USD');
        $this->createTrade($owner, self::thisMonth(4), null, asset: 'ETH/USD');
        $this->loginAs($this->createUser('visitor@test.com'));

        $crawler = $this->client->request('GET', '/traders/bob');
        $this->assertResponseIsSuccessful();
        $this->assertStringNotContainsString('Terminés', $crawler->filter('.nav-tabs')->text());

        $crawler = $this->client->request('GET', '/traders/bob?tab=open');
        $this->assertStringContainsString('ETH/USD', $crawler->filter('body')->text());
        $this->assertStringNotContainsString('BTC/USD', $crawler->filter('body')->text());

        $this->client->request('GET', '/traders/bob/trades/' . $closed->getId());
        $this->assertResponseStatusCodeSame(404);
    }

    public function testOpenTradesToggleOffHidesThemEverywhere(): void
    {
        $owner = $this->createUser('bob@test.com', 'bob', shareEnabled: true, shareOpenTrades: false);
        $open = $this->createTrade($owner, self::thisMonth(4), null, asset: 'ETH/USD');
        $this->createTrade($owner, self::thisMonth(2), self::thisMonth(3), asset: 'BTC/USD');
        $this->loginAs($this->createUser('visitor@test.com'));

        $crawler = $this->client->request('GET', '/traders/bob');
        $this->assertResponseIsSuccessful();
        $this->assertStringNotContainsString('En cours', $crawler->filter('.nav-tabs')->text());

        $crawler = $this->client->request('GET', '/traders/bob?tab=closed');
        $this->assertStringContainsString('BTC/USD', $crawler->filter('body')->text());
        $this->assertStringNotContainsString('ETH/USD', $crawler->filter('body')->text());

        $this->client->request('GET', '/traders/bob/trades/' . $open->getId());
        $this->assertResponseStatusCodeSame(404);
    }

    public function testWatchingTradeIsNeverVisible(): void
    {
        $owner = $this->createUser('bob@test.com', 'bob', shareEnabled: true);
        $watching = $this->createTrade($owner, null, null, asset: 'XAU/USD');
        $this->createTrade($owner, self::thisMonth(2), self::thisMonth(3), asset: 'BTC/USD');
        $this->loginAs($this->createUser('visitor@test.com'));

        $crawler = $this->client->request('GET', '/traders/bob?tab=open');
        $this->assertResponseIsSuccessful();
        $this->assertStringNotContainsString('XAU/USD', $crawler->filter('body')->text());

        $this->client->request('GET', '/traders/bob/trades/' . $watching->getId());
        $this->assertResponseStatusCodeSame(404);
    }

    public function testCurrentMonthOnlyHidesLastMonthClosedTrade(): void
    {
        $owner = $this->createUser('bob@test.com', 'bob', shareEnabled: true, shareCurrentMonthOnly: true);
        $lastMonthTrade = $this->createTrade($owner, self::lastMonth(5), self::lastMonth(6), finalRR: 7.0, asset: 'BTC/USD');
        $this->createTrade($owner, self::thisMonth(2), self::thisMonth(3), finalRR: 3.0, asset: 'ETH/USD');
        $this->loginAs($this->createUser('visitor@test.com'));

        $crawler = $this->client->request('GET', '/traders/bob?tab=closed');
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('ETH/USD', $crawler->filter('body')->text());
        $this->assertStringNotContainsString('BTC/USD', $crawler->filter('body')->text());

        $this->client->request('GET', '/traders/bob/trades/' . $lastMonthTrade->getId());
        $this->assertResponseStatusCodeSame(404);

        // Les stats publiques n'incluent que le mois : total 3.00 R, pas 10.00 R
        $crawler = $this->client->request('GET', '/traders/bob?tab=stats');
        $this->assertResponseIsSuccessful();
        $body = $crawler->filter('body')->text();
        $this->assertStringContainsString('3.00 R', $body);
        $this->assertStringNotContainsString('10.00 R', $body);
    }

    public function testCurrentMonthOnlyKeepsOpenTradeEnteredLastMonth(): void
    {
        $owner = $this->createUser('bob@test.com', 'bob', shareEnabled: true, shareCurrentMonthOnly: true);
        $straddling = $this->createTrade($owner, self::lastMonth(20), null, asset: 'XAU/USD');
        $this->loginAs($this->createUser('visitor@test.com'));

        $crawler = $this->client->request('GET', '/traders/bob?tab=open');
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('XAU/USD', $crawler->filter('body')->text());

        $this->client->request('GET', '/traders/bob/trades/' . $straddling->getId());
        $this->assertResponseIsSuccessful();
    }

    public function testForcedStartDateDoesNotLeakOutsideCurrentMonth(): void
    {
        $owner = $this->createUser('bob@test.com', 'bob', shareEnabled: true, shareCurrentMonthOnly: true);
        $this->createTrade($owner, self::lastMonth(5), self::lastMonth(6), asset: 'BTC/USD');
        $this->loginAs($this->createUser('visitor@test.com'));

        $crawler = $this->client->request('GET', '/traders/bob?tab=closed&start_date=2020-01-01&end_date=2030-01-01');
        $this->assertResponseIsSuccessful();
        $this->assertStringNotContainsString('BTC/USD', $crawler->filter('body')->text());
    }

    public function testPublicTradePageContainsNoEuroValue(): void
    {
        $owner = $this->createUser('bob@test.com', 'bob', shareEnabled: true);
        // gainEuro = 3.0 * 98765 = 296295
        $trade = $this->createTrade($owner, self::thisMonth(2), self::thisMonth(3), finalRR: 3.0);
        $this->loginAs($this->createUser('visitor@test.com'));

        $crawler = $this->client->request('GET', '/traders/bob/trades/' . $trade->getId());
        $this->assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        $this->assertStringNotContainsString('98765', $content);
        $this->assertStringNotContainsString('296295', $content);
        $this->assertStringNotContainsString('296,295', $content);
        $this->assertNoEuroInVisibleContent($crawler);

        // La page stats publique non plus
        $crawler = $this->client->request('GET', '/traders/bob?tab=stats');
        $this->assertResponseIsSuccessful();
        $this->assertStringNotContainsString('296,295', $this->client->getResponse()->getContent());
        $this->assertNoEuroInVisibleContent($crawler);
    }

    /**
     * Le layout global contient un « € » dans un script statique (calculateur
     * du formulaire de trade) : on vérifie donc le contenu visible (cartes,
     * tableaux, alertes), qui porte toutes les données affichées.
     */
    private function assertNoEuroInVisibleContent($crawler): void
    {
        foreach ($crawler->filter('.card, table, .alert') as $node) {
            $this->assertStringNotContainsString('€', $node->textContent);
        }
    }

    // Les tests « pseudo requis pour activer le partage » et « unicité insensible
    // à la casse depuis /profile » ont été retirés : le pseudo est désormais collecté
    // à l'onboarding et obligatoire pour tous, un compte sans pseudo ne peut plus
    // atteindre /profile. Ces deux comportements sont couverts par OnboardingControllerTest.

    public function testDisplayNameIsImmutableOnceSet(): void
    {
        $user = $this->createUser('bob@test.com', 'bob', shareEnabled: true);
        $this->loginAs($user);

        $crawler = $this->client->request('GET', '/profile');
        $form = $crawler->selectButton('Enregistrer')->form();
        $token = $form->get('profile_visibility[_token]')->getValue();

        // POST forgé tentant de changer le pseudo malgré le champ désactivé
        $this->client->request('POST', '/profile/visibility', [
            'profile_visibility' => [
                'displayName' => 'hacker',
                'shareEnabled' => '1',
                '_token' => $token,
            ],
        ]);

        $this->em->clear();
        $reloaded = static::getContainer()->get(UserRepository::class)->find($user->getId());
        $this->assertSame('bob', $reloaded->getDisplayName());
    }

    public function testTraderWithoutVisibleTradeIsHiddenFromList(): void
    {
        // Profil public mais aucun trade
        $this->createUser('empty@test.com', 'empty_trader', shareEnabled: true);
        // Profil public avec seulement un trade watching
        $watcher = $this->createUser('watcher@test.com', 'watcher_only', shareEnabled: true);
        $this->createTrade($watcher, null, null);
        // Profil public avec un trade visible
        $active = $this->createUser('active@test.com', 'active_trader', shareEnabled: true);
        $this->createTrade($active, self::thisMonth(2), self::thisMonth(3));

        $this->loginAs($this->createUser('visitor@test.com'));

        $crawler = $this->client->request('GET', '/traders');
        $this->assertResponseIsSuccessful();
        $body = $crawler->filter('body')->text();
        $this->assertStringContainsString('active_trader', $body);
        $this->assertStringNotContainsString('empty_trader', $body);
        $this->assertStringNotContainsString('watcher_only', $body);
    }

    public function testCurrentUserIsExcludedFromList(): void
    {
        $me = $this->createUser('me@test.com', 'myself', shareEnabled: true);
        $this->createTrade($me, self::thisMonth(2), self::thisMonth(3));
        $other = $this->createUser('other@test.com', 'other_trader', shareEnabled: true);
        $this->createTrade($other, self::thisMonth(2), self::thisMonth(3));

        $this->loginAs($me);

        $crawler = $this->client->request('GET', '/traders');
        $this->assertResponseIsSuccessful();
        $body = $crawler->filter('body')->text();
        $this->assertStringContainsString('other_trader', $body);
        $this->assertStringNotContainsString('myself', $body);
    }

    public function testOwnerStillSeesOwnPagesInBothUnits(): void
    {
        $owner = $this->createUser('bob@test.com', 'bob', shareEnabled: true);
        $this->createTrade($owner, self::thisMonth(2), self::thisMonth(3), finalRR: 3.0);
        $this->loginAs($owner);

        $this->client->request('GET', '/trade/');
        $this->assertResponseIsSuccessful();

        $this->client->request('GET', '/stats/');
        $this->assertResponseIsSuccessful();
        // Mode € : gainEuro = 3.0 * 98765 = 296,295.00
        $this->assertStringContainsString('296,295.00', $this->client->getResponse()->getContent());

        $this->client->request('GET', '/stats/?unit=rr');
        $this->assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        $this->assertStringContainsString('3.00 R', $content);
        $this->assertStringNotContainsString('296,295.00', $content);
    }
}

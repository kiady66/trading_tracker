<?php

namespace App\Tests\Controller\Api;

use App\Entity\Trade;
use App\Entity\User;
use App\Repository\TradeRepository;
use App\Repository\UserRepository;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

class TradeApiControllerTest extends WebTestCase
{
    private const USER_EMAIL = 'admin@trading-tracker.com';

    private string $bearerToken;
    private User $authenticatedUser;

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Démarre le client et prépare l'authentification sans initialiser l'ORM :
     *  - récupère le token via DBAL (pas d'EntityManager)
     *  - crée un User en mémoire
     *  - mocke UserRepository pour que l'auth API retourne cet utilisateur
     *
     * Ainsi TradeRepository reste libre d'être mocké dans chaque test.
     */
    private function bootClient(): KernelBrowser
    {
        $client = static::createClient();

        // Lecture du token via DBAL — n'initialise PAS l'ORM EntityManager
        $conn = static::getContainer()->get(Connection::class);
        $row = $conn->fetchAssociative(
            'SELECT api_token FROM "user" WHERE email = ?',
            [self::USER_EMAIL]
        );

        if (!$row) {
            $this->markTestSkipped(sprintf('Utilisateur "%s" introuvable en base.', self::USER_EMAIL));
        }

        $this->bearerToken = $row['api_token'];

        // Utilisateur en mémoire — même token que celui en DB
        $this->authenticatedUser = new User();
        $this->authenticatedUser->setEmail(self::USER_EMAIL);
        $this->authenticatedUser->setPassword('hashed');
        $this->authenticatedUser->setApiToken($this->bearerToken);

        // Mock UserRepository : l'authenticator API retourne l'user en mémoire
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findOneBy')
            ->with(['apiToken' => $this->bearerToken])
            ->willReturn($this->authenticatedUser);
        static::getContainer()->set(UserRepository::class, $userRepo);

        return $client;
    }

    private function authHeaders(): array
    {
        return [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->bearerToken,
        ];
    }

    /** Trade en mémoire appartenant à l'utilisateur authentifié. */
    private function makeTestTrade(): Trade
    {
        $trade = new Trade();
        $trade->setAsset('EUR/USD');
        $trade->setOrderType('buy market');
        $trade->setRiskPercentage(1.5);
        $trade->setMaxRiskEuro(200.5);
        $trade->setUser($this->authenticatedUser);

        return $trade;
    }

    private function mockRepoWithTrade(Trade $trade, int $id): void
    {
        $repo = $this->createMock(TradeRepository::class);
        $repo->method('find')
            ->willReturnCallback(static fn(mixed $foundId) => $foundId == $id ? $trade : null);
        static::getContainer()->set(TradeRepository::class, $repo);
    }

    private function mockRepoReturnsNull(): void
    {
        $repo = $this->createMock(TradeRepository::class);
        $repo->method('find')->willReturn(null);
        static::getContainer()->set(TradeRepository::class, $repo);
    }

    // =========================================================================
    // POST /api/trades — Création
    // =========================================================================

    public function testCreerUnTradeValideRetourne201(): void
    {
        $client = $this->bootClient();

        $client->request(
            'POST',
            '/api/trades',
            [],
            [],
            $this->authHeaders(),
            json_encode([
                'asset'          => 'EUR/USD',
                'orderType'      => 'buy market',
                'riskPercentage' => 1.5,
                'maxRiskEuro'    => 200.0,
            ])
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('EUR/USD', $data['asset']);
        $this->assertSame('buy market', $data['orderType']);
        $this->assertEquals(1.5, $data['riskPercentage']);
        $this->assertEquals(200.0, $data['maxRiskEuro']);
        $this->assertSame('watching', $data['status']);
        $this->assertIsArray($data['timeframes']);
        $this->assertIsArray($data['confluences']);
        $this->assertNull($data['tradeType']);
    }

    public function testCreerUnTradeAvecEntryDateMetStatusOpen(): void
    {
        $client = $this->bootClient();

        $client->request(
            'POST',
            '/api/trades',
            [],
            [],
            $this->authHeaders(),
            json_encode([
                'asset'          => 'GBP/USD',
                'orderType'      => 'sell market',
                'riskPercentage' => 2.0,
                'maxRiskEuro'    => 100.0,
                'entryDate'      => '2024-06-03T09:30:00+00:00',
            ])
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('open', $data['status']);
        $this->assertSame('Monday', $data['day']);
    }

    public function testCreerUnTradeAvecFinalRRCalculeLeGain(): void
    {
        $client = $this->bootClient();

        $client->request(
            'POST',
            '/api/trades',
            [],
            [],
            $this->authHeaders(),
            json_encode([
                'asset'          => 'XAU/USD',
                'orderType'      => 'buy limit',
                'riskPercentage' => 2.0,
                'maxRiskEuro'    => 100.0,
                'finalRR'        => 3.0,
            ])
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals(3.0 * (2.0 / 100), $data['gainRR']);
        $this->assertEquals(3.0 * (2.0 / 100) * 100.0, $data['gainEuro']);
    }

    public function testCreerUnTradeJsonInvalideRetourne400(): void
    {
        $client = $this->bootClient();

        $client->request('POST', '/api/trades', [], [], $this->authHeaders(), 'pas du json');

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
    }

    public function testCreerUnTradeChampsManquantsRetourne422(): void
    {
        $client = $this->bootClient();

        $client->request(
            'POST',
            '/api/trades',
            [],
            [],
            $this->authHeaders(),
            json_encode(['asset' => 'EUR/USD'])
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('errors', $data);
        $this->assertArrayHasKey('orderType', $data['errors']);
        $this->assertArrayHasKey('riskPercentage', $data['errors']);
        $this->assertArrayHasKey('maxRiskEuro', $data['errors']);
    }

    public function testCreerUnTradeAssetInvalideRetourne422(): void
    {
        $client = $this->bootClient();

        $client->request(
            'POST',
            '/api/trades',
            [],
            [],
            $this->authHeaders(),
            json_encode([
                'asset'          => 'FAKE/PAIR',
                'orderType'      => 'buy market',
                'riskPercentage' => 1.0,
                'maxRiskEuro'    => 50.0,
            ])
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('asset', $data['errors']);
    }

    public function testCreerUnTradeSansAuthRetourne401(): void
    {
        $client = static::createClient();

        $client->request(
            'POST',
            '/api/trades',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'asset'          => 'EUR/USD',
                'orderType'      => 'buy market',
                'riskPercentage' => 1.0,
                'maxRiskEuro'    => 50.0,
            ])
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    // =========================================================================
    // GET /api/trades — Liste
    // =========================================================================

    public function testListerLesTradesRetourne200AvecTableau(): void
    {
        $client = $this->bootClient();

        $trade1 = $this->makeTestTrade();
        $trade2 = $this->makeTestTrade();
        $trade2->setAsset('GBP/USD');

        $repo = $this->createMock(TradeRepository::class);
        $repo->method('findBy')->willReturn([$trade1, $trade2]);
        static::getContainer()->set(TradeRepository::class, $repo);

        $client->request('GET', '/api/trades', [], [], $this->authHeaders());

        $this->assertResponseStatusCodeSame(Response::HTTP_OK);
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertIsArray($data);
        $this->assertCount(2, $data);
        $this->assertSame('EUR/USD', $data[0]['asset']);
        $this->assertSame('GBP/USD', $data[1]['asset']);
    }

    public function testListerLesTradesAvecFiltreStatusEtAsset(): void
    {
        $client = $this->bootClient();

        $repo = $this->createMock(TradeRepository::class);
        $repo->expects($this->once())
            ->method('findBy')
            ->with($this->callback(fn(array $c) =>
                ($c['status'] ?? null) === 'open' && ($c['asset'] ?? null) === 'EUR/USD'
            ))
            ->willReturn([$this->makeTestTrade()]);
        static::getContainer()->set(TradeRepository::class, $repo);

        $client->request('GET', '/api/trades?status=open&asset=EUR/USD', [], [], $this->authHeaders());

        $this->assertResponseStatusCodeSame(Response::HTTP_OK);
        $this->assertCount(1, json_decode($client->getResponse()->getContent(), true));
    }

    public function testListerLesTradesVideRetourneTableauVide(): void
    {
        $client = $this->bootClient();

        $repo = $this->createMock(TradeRepository::class);
        $repo->method('findBy')->willReturn([]);
        static::getContainer()->set(TradeRepository::class, $repo);

        $client->request('GET', '/api/trades', [], [], $this->authHeaders());

        $this->assertResponseStatusCodeSame(Response::HTTP_OK);
        $this->assertSame([], json_decode($client->getResponse()->getContent(), true));
    }

    // =========================================================================
    // GET /api/trades/{id} — Lecture d'un trade
    // =========================================================================

    public function testVoirUnTradeRetourne200(): void
    {
        $client = $this->bootClient();

        $trade = $this->makeTestTrade();
        $this->mockRepoWithTrade($trade, 1);

        $client->request('GET', '/api/trades/1', [], [], $this->authHeaders());

        $this->assertResponseStatusCodeSame(Response::HTTP_OK);
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('EUR/USD', $data['asset']);
        $this->assertSame('buy market', $data['orderType']);
        $this->assertEquals(1.5, $data['riskPercentage']);
        $this->assertSame('watching', $data['status']);
    }

    public function testVoirUnTradeInexistantRetourne404(): void
    {
        $client = $this->bootClient();
        $this->mockRepoReturnsNull();

        $client->request('GET', '/api/trades/999', [], [], $this->authHeaders());

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testVoirUnTradeDUnAutreUtilisateurRetourne403(): void
    {
        $client = $this->bootClient();

        $autreUser = new User();
        $autreUser->setEmail('autre@example.com');
        $autreUser->setPassword('hash');

        $trade = $this->makeTestTrade();
        $trade->setUser($autreUser);
        $this->mockRepoWithTrade($trade, 1);

        $client->request('GET', '/api/trades/1', [], [], $this->authHeaders());

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    // =========================================================================
    // PUT /api/trades/{id} — Mise à jour complète
    // =========================================================================

    public function testMettreAJourUnTradeRetourne200(): void
    {
        $client = $this->bootClient();

        $trade = $this->makeTestTrade();
        $this->mockRepoWithTrade($trade, 1);

        $client->request(
            'PUT',
            '/api/trades/1',
            [],
            [],
            $this->authHeaders(),
            json_encode([
                'asset'           => 'GBP/USD',
                'orderType'       => 'sell market',
                'riskPercentage'  => 3.0,
                'maxRiskEuro'     => 150.5,
                'executionReason' => 'Résistance forte',
            ])
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_OK);
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('GBP/USD', $data['asset']);
        $this->assertSame('sell market', $data['orderType']);
        $this->assertEquals(3.0, $data['riskPercentage']);
        $this->assertSame('Résistance forte', $data['executionReason']);
    }

    public function testMettreAJourUnTradeJsonInvalideRetourne400(): void
    {
        $client = $this->bootClient();

        $trade = $this->makeTestTrade();
        $this->mockRepoWithTrade($trade, 1);

        $client->request('PUT', '/api/trades/1', [], [], $this->authHeaders(), 'pas du json');

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    public function testMettreAJourUnTradeInexistantRetourne404(): void
    {
        $client = $this->bootClient();
        $this->mockRepoReturnsNull();

        $client->request(
            'PUT',
            '/api/trades/999',
            [],
            [],
            $this->authHeaders(),
            json_encode([
                'asset'          => 'EUR/USD',
                'orderType'      => 'buy market',
                'riskPercentage' => 1.0,
                'maxRiskEuro'    => 50.0,
            ])
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    // =========================================================================
    // PATCH /api/trades/{id} — Mise à jour partielle
    // =========================================================================

    public function testMettreAJourPartiellementUnTradeRetourne200(): void
    {
        $client = $this->bootClient();

        $trade = $this->makeTestTrade();
        $this->mockRepoWithTrade($trade, 1);

        $client->request(
            'PATCH',
            '/api/trades/1',
            [],
            [],
            $this->authHeaders(),
            json_encode(['executionReason' => 'Support clé'])
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_OK);
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('EUR/USD', $data['asset']);
        $this->assertSame('Support clé', $data['executionReason']);
    }

    public function testPatchAvecEntryDateChangeLeSatutEnOpen(): void
    {
        $client = $this->bootClient();

        $trade = $this->makeTestTrade();
        $this->mockRepoWithTrade($trade, 1);

        $client->request(
            'PATCH',
            '/api/trades/1',
            [],
            [],
            $this->authHeaders(),
            json_encode(['entryDate' => '2024-06-03T09:00:00+00:00'])
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_OK);
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('open', $data['status']);
    }

    public function testPatchTradeDUnAutreUtilisateurRetourne403(): void
    {
        $client = $this->bootClient();

        $autreUser = new User();
        $autreUser->setEmail('autre@example.com');
        $autreUser->setPassword('hash');

        $trade = $this->makeTestTrade();
        $trade->setUser($autreUser);
        $this->mockRepoWithTrade($trade, 1);

        $client->request(
            'PATCH',
            '/api/trades/1',
            [],
            [],
            $this->authHeaders(),
            json_encode(['executionReason' => 'tentative'])
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }
}
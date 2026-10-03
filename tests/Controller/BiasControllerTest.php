<?php

namespace App\Tests\Controller;

use App\Entity\CentralBank;
use App\Entity\User;
use App\Repository\CentralBankRepository;
use App\Repository\CurrencyNoteRepository;
use App\Service\BiasPairList;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class BiasControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $user;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->executeStatement('TRUNCATE "user" RESTART IDENTITY CASCADE');
        $this->em->getConnection()->executeStatement('TRUNCATE central_bank RESTART IDENTITY CASCADE');

        $this->user = new User();
        $this->user->setEmail('bob@test.com');
        $this->user->setPassword('irrelevant-for-loginUser');
        $this->user->setDisplayName('bob');
        $this->em->persist($this->user);

        $biases = ['Fed' => 'hawkish', 'BCE' => 'dovish'];
        foreach (BiasPairList::CURRENCY_BANKS as $bankCode) {
            $this->em->persist((new CentralBank())->setCode($bankCode)->setRate('2,00 %')->setBias($biases[$bankCode] ?? 'neutre'));
        }
        $this->em->flush();
    }

    private function login(): string
    {
        $this->client->loginUser($this->user, 'main');
        $crawler = $this->client->request('GET', '/biais');
        $this->assertResponseIsSuccessful();

        return $crawler->filter('[data-bias-csrf-value]')->attr('data-bias-csrf-value');
    }

    public function testAnonymousIsRedirectedToLogin(): void
    {
        $this->client->request('GET', '/biais');
        $this->assertResponseRedirects('http://localhost/login');
    }

    public function testPageShowsOneCardPerCurrencyAndTheDivergentPairs(): void
    {
        $this->login();

        $this->assertSelectorCount(8, '.bias-card');
        $this->assertSelectorExists('.bias-card[data-currency="USD"].bias-hawkish');
        $this->assertSelectorExists('.bias-card[data-currency="EUR"].bias-dovish');
        $this->assertSelectorCount(1, '.bias-pair');
        $this->assertSelectorTextContains('.bias-pair .bias-pair-name', 'EUR/USD');
        $this->assertSelectorTextContains('nav', 'Biais');
    }

    public function testChangingABiasUpdatesTheCentralBankAndThePairList(): void
    {
        $token = $this->login();

        $this->client->jsonRequest('POST', '/biais/JPY/biais', ['bias' => 'bullish', '_token' => $token]);
        $this->assertResponseStatusCodeSame(422);

        $this->client->jsonRequest('POST', '/biais/JPY/biais', ['bias' => 'dovish', '_token' => 'forged']);
        $this->assertResponseStatusCodeSame(419);

        $this->client->jsonRequest('POST', '/biais/JPY/biais', ['bias' => 'dovish', '_token' => $token]);
        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('dovish', $data['bias']);
        $this->assertSame(['EUR/USD', 'USD/JPY'], array_column($data['pairs'], 'pair'));
        $this->assertSame(['USD', 'JPY', 'hawkish', 'dovish'], [$data['pairs'][1]['base'], $data['pairs'][1]['quote'], $data['pairs'][1]['baseBias'], $data['pairs'][1]['quoteBias']]);

        // Même source que la page Cycles
        $this->em->clear();
        $this->assertSame('dovish', static::getContainer()->get(CentralBankRepository::class)->findOneBy(['code' => 'BoJ'])->getBias());
    }

    public function testNoteIsSanitizedPersistedAndShownAgain(): void
    {
        $token = $this->login();

        $this->client->jsonRequest('POST', '/biais/USD/note', [
            'note' => '<h2>Fed</h2><p>Taux <strong>élevés</strong> <span class="ql-bg-yellow" style="color:red">longtemps</span>'
                . '<script>alert(1)</script><img src=x onerror=alert(1)></p><ol><li data-list="bullet">NFP solide</li></ol>',
            '_token' => $token,
        ]);
        $this->assertResponseIsSuccessful();
        $saved = json_decode($this->client->getResponse()->getContent(), true)['note'];
        $this->assertStringContainsString('<strong>élevés</strong>', $saved);
        $this->assertStringContainsString('<span class="ql-bg-yellow">longtemps</span>', $saved);
        $this->assertStringContainsString('<li data-list="bullet">NFP solide</li>', $saved);
        $this->assertStringNotContainsString('<script', $saved);
        $this->assertStringNotContainsString('onerror', $saved);
        $this->assertStringNotContainsString('<img', $saved);

        $crawler = $this->client->request('GET', '/biais');
        $this->assertSame('élevés', $crawler->filter('.bias-card[data-currency="USD"] .bias-editor strong')->text());
        $this->assertSelectorTextContains('.bias-card[data-currency="USD"] .bias-card-foot', 'Note du');

        // Vider la note ne garde pas la coquille "<p><br></p>" de l'éditeur
        $this->client->jsonRequest('POST', '/biais/USD/note', ['note' => '<p><br></p>', '_token' => $token]);
        $this->assertResponseIsSuccessful();
        $this->em->clear();
        $note = static::getContainer()->get(CurrencyNoteRepository::class)->findOneBy(['currency' => 'USD']);
        $this->assertSame('', $note->getContent());
        $this->assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM currency_note'));
    }

    public function testPairsOrderIsPersistedAndStaleOrderIsRejected(): void
    {
        $token = $this->login();
        $this->client->jsonRequest('POST', '/biais/JPY/biais', ['bias' => 'dovish', '_token' => $token]);

        $this->client->jsonRequest('POST', '/biais/paires/ordre', ['pairs' => ['USD/JPY', 'EUR/USD'], '_token' => $token]);
        $this->assertResponseIsSuccessful();

        $crawler = $this->client->request('GET', '/biais');
        $this->assertSame(['USD/JPY', 'EUR/USD'], $crawler->filter('.bias-pair')->each(static fn ($li) => $li->attr('data-pair')));

        // Liste périmée (paire absente) : 409 avec la liste courante
        $this->client->jsonRequest('POST', '/biais/paires/ordre', ['pairs' => ['EUR/USD', 'GBP/USD'], '_token' => $token]);
        $this->assertResponseStatusCodeSame(409);
        $this->assertSame(['USD/JPY', 'EUR/USD'], array_column(json_decode($this->client->getResponse()->getContent(), true)['pairs'], 'pair'));
    }
}

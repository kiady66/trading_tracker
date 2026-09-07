<?php

namespace App\Tests\Controller;

use App\Entity\CentralBank;
use App\Entity\User;
use App\Repository\CentralBankRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class CyclesControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->executeStatement('TRUNCATE "user" RESTART IDENTITY CASCADE');
        $this->em->getConnection()->executeStatement('TRUNCATE central_bank RESTART IDENTITY CASCADE');
    }

    private function createUser(string $email): User
    {
        $user = new User();
        $user->setEmail($email);
        $user->setPassword('irrelevant-for-loginUser');
        // Obligatoire depuis l'onboarding, sinon toute page redirige vers /onboarding.
        $user->setDisplayName(preg_replace('/[^a-zA-Z0-9_-]/', '', strstr($email, '@', true)));

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function createBank(string $code, float $angle, int $position = 0): CentralBank
    {
        $bank = (new CentralBank())
            ->setCode($code)
            ->setRate('2,25 %')
            ->setAngle($angle)
            ->setBias('neutre')
            ->setRetracement(false)
            ->setPosition($position);

        $this->em->persist($bank);
        $this->em->flush();

        return $bank;
    }

    /**
     * Récupère la page et le jeton CSRF exposé au contrôleur Stimulus.
     */
    private function fetchCsrfToken(): string
    {
        $crawler = $this->client->request('GET', '/cycles');
        $this->assertResponseIsSuccessful();

        return $crawler->filter('[data-cycles-clock-csrf-value]')->attr('data-cycles-clock-csrf-value');
    }

    public function testAnonymousIsRedirectedToLogin(): void
    {
        $this->client->request('GET', '/cycles');
        $this->assertResponseRedirects('http://localhost/login');

        $this->client->jsonRequest('POST', '/cycles/save', ['banks' => []]);
        $this->assertResponseRedirects('http://localhost/login');
    }

    public function testPageExposesBanksToTheStimulusController(): void
    {
        $this->createBank('Fed', 199.0);
        $this->createBank('BCE', 246.0, 1);
        $this->client->loginUser($this->createUser('bob@test.com'), 'main');

        $crawler = $this->client->request('GET', '/cycles');
        $this->assertResponseIsSuccessful();

        $banks = json_decode($crawler->filter('[data-cycles-clock-banks-value]')->attr('data-cycles-clock-banks-value'), true);
        $this->assertSame(['Fed', 'BCE'], array_column($banks, 'code'));
        // json_encode sérialise un flottant entier sans décimale, d'où le cast
        $this->assertSame(199.0, (float) $banks[0]['angle']);
    }

    public function testSavePersistsBankChanges(): void
    {
        $this->createBank('Fed', 199.0);
        $this->client->loginUser($this->createUser('bob@test.com'), 'main');
        $token = $this->fetchCsrfToken();

        $this->client->jsonRequest('POST', '/cycles/save', [
            'banks' => [[
                'code' => 'Fed',
                'rate' => '3,25-3,50 %',
                'angle' => 137.5,
                'bias' => 'dovish',
                'retracement' => true,
            ]],
            '_token' => $token,
        ]);
        $this->assertResponseIsSuccessful();
        $this->assertSame(1, json_decode($this->client->getResponse()->getContent(), true)['updated']);

        $this->em->clear();
        $bank = static::getContainer()->get(CentralBankRepository::class)->findOneBy(['code' => 'Fed']);
        $this->assertSame('3,25-3,50 %', $bank->getRate());
        $this->assertSame(137.5, $bank->getAngle());
        $this->assertSame('dovish', $bank->getBias());
        $this->assertTrue($bank->isRetracement());
    }

    public function testSaveRejectsInvalidCsrfAndInvalidValues(): void
    {
        $this->createBank('Fed', 199.0);
        $this->client->loginUser($this->createUser('bob@test.com'), 'main');
        $token = $this->fetchCsrfToken();

        $this->client->jsonRequest('POST', '/cycles/save', [
            'banks' => [['code' => 'Fed', 'angle' => 10]],
            '_token' => 'forged',
        ]);
        $this->assertResponseStatusCodeSame(419);

        $this->client->jsonRequest('POST', '/cycles/save', [
            'banks' => [['code' => 'Fed', 'bias' => 'bullish']],
            '_token' => $token,
        ]);
        $this->assertResponseStatusCodeSame(422);

        $this->em->clear();
        $bank = static::getContainer()->get(CentralBankRepository::class)->findOneBy(['code' => 'Fed']);
        $this->assertSame(199.0, $bank->getAngle());
        $this->assertSame('neutre', $bank->getBias());
    }
}

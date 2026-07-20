<?php

namespace App\Tests\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class OnboardingControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->executeStatement('TRUNCATE "user" RESTART IDENTITY CASCADE');
    }

    private function createUser(string $email, ?string $pseudo = null): User
    {
        $user = new User();
        $user->setEmail($email);
        $user->setPassword('irrelevant-for-loginUser');
        $user->setDisplayName($pseudo);

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /**
     * Lecture SQL brute : après une soumission invalide, le formulaire a muté
     * l'entité gérée en mémoire sans la persister. Passer par le repository
     * renverrait cette valeur non enregistrée et masquerait ce qu'il y a en base.
     */
    private function storedDisplayName(string $email): ?string
    {
        $value = static::getContainer()->get(EntityManagerInterface::class)
            ->getConnection()
            ->fetchOne('SELECT display_name FROM "user" WHERE email = ?', [$email]);

        return $value === false ? null : $value;
    }

    public function testUserWithoutPseudoIsRedirectedFromAnyPage(): void
    {
        $this->client->loginUser($this->createUser('new@test.com'), 'main');

        $this->client->request('GET', '/trade');

        $this->assertResponseRedirects('/onboarding');
    }

    /**
     * Non-régression sur la boucle infinie : la page d'onboarding elle-même ne
     * doit évidemment pas déclencher la redirection vers l'onboarding.
     */
    public function testOnboardingPageItselfIsNotRedirected(): void
    {
        $this->client->loginUser($this->createUser('new@test.com'), 'main');

        $this->client->request('GET', '/onboarding');

        $this->assertResponseIsSuccessful();
    }

    /**
     * Sans cette exclusion, un utilisateur sans pseudo serait incapable de se
     * déconnecter : toutes ses requêtes rebondiraient sur l'onboarding.
     */
    public function testLogoutRemainsReachableWithoutPseudo(): void
    {
        $this->client->loginUser($this->createUser('new@test.com'), 'main');

        $this->client->request('GET', '/logout');

        $this->assertResponseRedirects();
        $this->assertStringNotContainsString(
            '/onboarding',
            $this->client->getResponse()->headers->get('Location')
        );
    }

    public function testUserWithPseudoIsNotRedirected(): void
    {
        $this->client->loginUser($this->createUser('bob@test.com', 'bob'), 'main');

        $this->client->request('GET', '/trade/');

        $this->assertResponseIsSuccessful();
    }

    public function testPseudoIsPersistedAndUserIsReleased(): void
    {
        $user = $this->createUser('new@test.com');
        $this->client->loginUser($user, 'main');

        $crawler = $this->client->request('GET', '/onboarding');
        $form = $crawler->selectButton('Continuer')->form();
        $form['onboarding[displayName]'] = 'nouveau_trader';
        $this->client->submit($form);

        $this->assertResponseRedirects();
        $this->assertSame('nouveau_trader', $this->storedDisplayName('new@test.com'));
    }

    public function testPseudoUniquenessIsCaseInsensitive(): void
    {
        $this->createUser('alice@test.com', 'alice');
        $this->client->loginUser($this->createUser('bob@test.com'), 'main');

        $crawler = $this->client->request('GET', '/onboarding');
        $form = $crawler->selectButton('Continuer')->form();
        $form['onboarding[displayName]'] = 'ALICE';
        $this->client->submit($form);

        $this->assertStringContainsString('déjà pris', $this->client->getResponse()->getContent());
        $this->assertNull($this->storedDisplayName('bob@test.com'));
    }

    public function testInvalidPseudoIsRejected(): void
    {
        $this->client->loginUser($this->createUser('new@test.com'), 'main');

        $crawler = $this->client->request('GET', '/onboarding');
        $form = $crawler->selectButton('Continuer')->form();
        $form['onboarding[displayName]'] = 'ab';
        $this->client->submit($form);

        $this->assertStringContainsString('au moins 3 caractères', $this->client->getResponse()->getContent());
    }

    public function testPseudoCannotBeChangedByRevisitingOnboarding(): void
    {
        $this->client->loginUser($this->createUser('bob@test.com', 'bob'), 'main');

        $this->client->request('GET', '/onboarding');

        $this->assertResponseRedirects('/trade/');
    }
}

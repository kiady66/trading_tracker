<?php

namespace App\Tests\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class FirebaseAuthControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->executeStatement('TRUNCATE "user" RESTART IDENTITY CASCADE');
    }

    private function postToken(string $body): void
    {
        $this->client->request(
            'POST',
            '/auth/firebase',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: $body
        );
    }

    public function testMissingTokenIsRejectedWithoutCallingFirebase(): void
    {
        $this->postToken(json_encode(['autre' => 'chose']));

        $this->assertResponseStatusCodeSame(401);
        $this->assertJson($this->client->getResponse()->getContent());
    }

    public function testMalformedJsonIsRejected(): void
    {
        $this->postToken('pas du json');

        $this->assertResponseStatusCodeSame(401);
    }

    public function testEndpointIsReachableWithoutBeingAuthenticated(): void
    {
        $this->postToken(json_encode(['autre' => 'chose']));

        // 401 et non une redirection vers /login : la route est bien publique,
        // c'est l'authenticator qui refuse, pas le contrôle d'accès.
        $this->assertResponseStatusCodeSame(401);
    }

    /**
     * Un compte créé via Firebase n'a pas de mot de passe. Le formulaire de
     * connexion doit le refuser proprement, sans erreur serveur.
     */
    public function testFormLoginOnPasswordlessAccountFailsCleanly(): void
    {
        $user = new User();
        $user->setEmail('firebase@test.com');
        $user->setFirebaseUid('uid-firebase');
        $user->setDisplayName('firebase_user');
        $this->em->persist($user);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/login');
        $form = $crawler->selectButton('Se connecter')->form([
            'email' => 'firebase@test.com',
            'password' => 'peu-importe',
        ]);
        $this->client->submit($form);

        $this->assertResponseRedirects('/login');

        $this->client->followRedirect();
        $this->assertSelectorExists('.alert-danger');
    }
}

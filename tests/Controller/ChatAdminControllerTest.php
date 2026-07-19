<?php

namespace App\Tests\Controller;

use App\Entity\ChatMessage;
use App\Entity\User;
use App\Repository\ChatMessageRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class ChatAdminControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->executeStatement('TRUNCATE "user" RESTART IDENTITY CASCADE');
    }

    private function createUser(string $email, array $roles = []): User
    {
        $user = new User();
        $user->setEmail($email);
        $user->setPassword('irrelevant-for-loginUser');
        $user->setRoles($roles);

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function createMessage(User $user, string $content, bool $fromAdmin = false): ChatMessage
    {
        $message = new ChatMessage();
        $message->setUser($user);
        $message->setContent($content);
        $message->setFromAdmin($fromAdmin);

        $this->em->persist($message);
        $this->em->flush();

        return $message;
    }

    public function testAdminRoutesAreForbiddenToRegularUsers(): void
    {
        $user = $this->createUser('bob@test.com');
        $this->client->loginUser($user, 'main');

        $this->client->request('GET', '/admin/chat/');
        $this->assertResponseStatusCodeSame(403);

        $this->client->request('GET', '/admin/chat/' . $user->getId());
        $this->assertResponseStatusCodeSame(403);

        $this->client->request('POST', '/admin/chat/' . $user->getId(), ['content' => 'hack']);
        $this->assertResponseStatusCodeSame(403);
    }

    public function testAdminSeesConversationsAndUnreadBadge(): void
    {
        $bob = $this->createUser('bob@test.com');
        $this->createMessage($bob, 'Salut, une question sur les stats');
        $admin = $this->createUser('admin@test.com', [User::ROLE_ADMIN]);
        $this->client->loginUser($admin, 'main');

        $crawler = $this->client->request('GET', '/admin/chat/');
        $this->assertResponseIsSuccessful();
        $body = $crawler->filter('body')->text();
        $this->assertStringContainsString('bob@test.com', $body);
        $this->assertStringContainsString('Salut, une question sur les stats', $body);
    }

    public function testViewingThreadMarksUserMessagesAsReadAndReplyIsFromAdmin(): void
    {
        $bob = $this->createUser('bob@test.com');
        $this->createMessage($bob, 'Ma question');
        $admin = $this->createUser('admin@test.com', [User::ROLE_ADMIN]);
        $this->client->loginUser($admin, 'main');

        $repository = static::getContainer()->get(ChatMessageRepository::class);
        $this->assertSame(1, $repository->countUnreadForAdmin());

        $crawler = $this->client->request('GET', '/admin/chat/' . $bob->getId());
        $this->assertResponseIsSuccessful();
        $this->em->clear();
        $this->assertSame(0, $repository->countUnreadForAdmin());

        $form = $crawler->selectButton('Envoyer')->form();
        $form['content'] = 'Ma réponse';
        $this->client->submit($form);
        $this->assertResponseRedirects('/admin/chat/' . $bob->getId());

        $this->em->clear();
        $messages = $repository->findByUser($bob);
        $this->assertCount(2, $messages);
        $this->assertTrue($messages[1]->isFromAdmin());
        $this->assertSame('Ma réponse', $messages[1]->getContent());
        // La réponse admin reste non lue côté utilisateur
        $this->assertSame(1, $repository->countUnreadForUser($this->em->getRepository(User::class)->find($bob->getId())));
    }
}

<?php

namespace App\Tests\Controller;

use App\Entity\ChatMessage;
use App\Entity\User;
use App\Repository\ChatMessageRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class ChatControllerTest extends WebTestCase
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
        // Obligatoire depuis l'onboarding, sinon toute page redirige vers /onboarding.
        $user->setDisplayName(preg_replace('/[^a-zA-Z0-9_-]/', '', strstr($email, '@', true)));

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

    private function getJson(): array
    {
        return json_decode($this->client->getResponse()->getContent(), true);
    }

    /**
     * Récupère l'historique et le jeton CSRF comme le ferait le widget.
     */
    private function fetchMessages(): array
    {
        $this->client->request('GET', '/chat/messages');
        $this->assertResponseIsSuccessful();

        return $this->getJson();
    }

    public function testAnonymousIsRedirectedToLogin(): void
    {
        $this->client->request('GET', '/chat/messages');
        $this->assertResponseRedirects('http://localhost/login');

        $this->client->jsonRequest('POST', '/chat/messages', ['content' => 'hello']);
        $this->assertResponseRedirects('http://localhost/login');
    }

    public function testUserCanSendMessage(): void
    {
        $user = $this->createUser('bob@test.com');
        $this->client->loginUser($user, 'main');

        $data = $this->fetchMessages();
        $this->assertSame([], $data['messages']);

        $this->client->jsonRequest('POST', '/chat/messages', [
            'content' => 'Bonjour, j\'ai besoin d\'aide !',
            '_token' => $data['csrfToken'],
        ]);
        $this->assertResponseStatusCodeSame(201);
        $this->assertFalse($this->getJson()['message']['fromAdmin']);

        $messages = static::getContainer()->get(ChatMessageRepository::class)->findByUser($user);
        $this->assertCount(1, $messages);
        $this->assertSame('Bonjour, j\'ai besoin d\'aide !', $messages[0]->getContent());
        $this->assertFalse($messages[0]->isFromAdmin());
        $this->assertNull($messages[0]->getReadAt());
    }

    public function testSendRejectsInvalidCsrfEmptyAndTooLongContent(): void
    {
        $user = $this->createUser('bob@test.com');
        $this->client->loginUser($user, 'main');
        $token = $this->fetchMessages()['csrfToken'];

        $this->client->jsonRequest('POST', '/chat/messages', ['content' => 'x', '_token' => 'forged']);
        $this->assertResponseStatusCodeSame(419);

        $this->client->jsonRequest('POST', '/chat/messages', ['content' => '   ', '_token' => $token]);
        $this->assertResponseStatusCodeSame(422);

        $this->client->jsonRequest('POST', '/chat/messages', ['content' => str_repeat('a', 2001), '_token' => $token]);
        $this->assertResponseStatusCodeSame(422);

        $this->assertCount(0, static::getContainer()->get(ChatMessageRepository::class)->findByUser($user));
    }

    public function testFetchingMessagesMarksAdminRepliesAsRead(): void
    {
        $user = $this->createUser('bob@test.com');
        $this->createMessage($user, 'Ma question');
        $this->createMessage($user, 'Ma réponse admin', fromAdmin: true);
        $this->client->loginUser($user, 'main');

        // Fenêtre réduite : compteur seul, sans marquage lu
        $this->client->request('GET', '/chat/messages?unread_only=1');
        $this->assertResponseIsSuccessful();
        $this->assertSame(1, $this->getJson()['unreadCount']);

        // Fenêtre ouverte : l'historique marque la réponse admin comme lue
        $data = $this->fetchMessages();
        $this->assertCount(2, $data['messages']);
        $this->assertSame(0, $data['unreadCount']);

        $this->client->request('GET', '/chat/messages?unread_only=1');
        $this->assertSame(0, $this->getJson()['unreadCount']);

        // Le message utilisateur reste non lu côté admin
        $this->em->clear();
        $repository = static::getContainer()->get(ChatMessageRepository::class);
        $this->assertSame(1, $repository->countUnreadForAdmin());
    }

    public function testHistoryIsLimitedToLastFiftyMessages(): void
    {
        $user = $this->createUser('bob@test.com');
        for ($i = 1; $i <= 55; $i++) {
            $this->createMessage($user, 'message ' . $i);
        }
        $this->client->loginUser($user, 'main');

        $data = $this->fetchMessages();
        $this->assertCount(50, $data['messages']);
        $this->assertSame('message 6', $data['messages'][0]['content']);
        $this->assertSame('message 55', $data['messages'][49]['content']);
    }
}

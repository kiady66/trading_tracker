<?php

namespace App\Controller;

use App\Entity\ChatMessage;
use App\Entity\User;
use App\Repository\ChatMessageRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/chat')]
#[IsGranted('ROLE_USER')]
class ChatController extends AbstractController
{
    public const MAX_LENGTH = 2000;

    #[Route('/messages', name: 'app_chat_messages', methods: ['GET'])]
    public function messages(
        Request $request,
        ChatMessageRepository $chatMessageRepository,
        CsrfTokenManagerInterface $csrfTokenManager,
    ): JsonResponse {
        /** @var User $user */
        $user = $this->getUser();

        // Fenêtre réduite : le front ne demande que le compteur, sans marquer lu
        if ($request->query->getBoolean('unread_only')) {
            return $this->json([
                'unreadCount' => $chatMessageRepository->countUnreadForUser($user),
            ]);
        }

        $messages = $chatMessageRepository->findByUser($user);
        $chatMessageRepository->markAsRead($user, fromAdmin: true);

        return $this->json([
            'messages' => array_map(self::serialize(...), $messages),
            'unreadCount' => 0,
            // Lisible uniquement en same-origin : sert de jeton anti-CSRF pour le POST
            'csrfToken' => $csrfTokenManager->getToken('chat')->getValue(),
        ]);
    }

    #[Route('/messages', name: 'app_chat_send', methods: ['POST'])]
    public function send(
        Request $request,
        EntityManagerInterface $entityManager,
        ChatMessageRepository $chatMessageRepository,
    ): JsonResponse {
        /** @var User $user */
        $user = $this->getUser();

        $payload = $request->toArray();

        if (!$this->isCsrfTokenValid('chat', $payload['_token'] ?? '')) {
            return $this->json(['error' => 'Jeton CSRF invalide.'], 419);
        }

        $content = trim((string) ($payload['content'] ?? ''));
        if ($content === '') {
            return $this->json(['error' => 'Le message est vide.'], 422);
        }
        if (mb_strlen($content) > self::MAX_LENGTH) {
            return $this->json(['error' => sprintf('Le message dépasse %d caractères.', self::MAX_LENGTH)], 422);
        }

        $message = new ChatMessage();
        $message->setUser($user);
        $message->setContent($content);
        $entityManager->persist($message);
        $entityManager->flush();

        return $this->json([
            'message' => self::serialize($message),
            'unreadCount' => $chatMessageRepository->countUnreadForUser($user),
        ], 201);
    }

    public static function serialize(ChatMessage $message): array
    {
        return [
            'id' => $message->getId(),
            'fromAdmin' => $message->isFromAdmin(),
            'content' => $message->getContent(),
            'createdAt' => $message->getCreatedAt()->format('d/m H:i'),
        ];
    }
}

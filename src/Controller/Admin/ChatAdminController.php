<?php

namespace App\Controller\Admin;

use App\Controller\ChatController;
use App\Entity\ChatMessage;
use App\Entity\User;
use App\Repository\ChatMessageRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/chat')]
#[IsGranted(User::ROLE_ADMIN)]
class ChatAdminController extends AbstractController
{
    #[Route('/', name: 'app_admin_chat_index', methods: ['GET'])]
    public function index(ChatMessageRepository $chatMessageRepository): Response
    {
        return $this->render('admin/chat/index.html.twig', [
            'conversations' => $chatMessageRepository->findConversations(),
        ]);
    }

    #[Route('/{id}', name: 'app_admin_chat_show', methods: ['GET'])]
    public function show(User $user, ChatMessageRepository $chatMessageRepository): Response
    {
        $messages = $chatMessageRepository->findByUser($user);
        $chatMessageRepository->markAsRead($user, fromAdmin: false);

        return $this->render('admin/chat/show.html.twig', [
            'chatUser' => $user,
            'messages' => $messages,
        ]);
    }

    #[Route('/{id}', name: 'app_admin_chat_reply', methods: ['POST'])]
    public function reply(User $user, Request $request, EntityManagerInterface $entityManager): Response
    {
        if (!$this->isCsrfTokenValid('chat-admin', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $content = trim((string) $request->request->get('content'));
        if ($content === '' || mb_strlen($content) > ChatController::MAX_LENGTH) {
            $this->addFlash('error', 'Le message doit faire entre 1 et 2000 caractères.');

            return $this->redirectToRoute('app_admin_chat_show', ['id' => $user->getId()]);
        }

        $message = new ChatMessage();
        $message->setUser($user);
        $message->setFromAdmin(true);
        $message->setContent($content);
        $entityManager->persist($message);
        $entityManager->flush();

        return $this->redirectToRoute('app_admin_chat_show', ['id' => $user->getId()]);
    }
}

<?php

namespace App\Repository;

use App\Entity\ChatMessage;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ChatMessage>
 *
 * @method ChatMessage|null find($id, $lockMode = null, $lockVersion = null)
 * @method ChatMessage|null findOneBy(array $criteria, array $orderBy = null)
 * @method ChatMessage[]    findAll()
 * @method ChatMessage[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class ChatMessageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ChatMessage::class);
    }

    /**
     * @return ChatMessage[] les 50 derniers messages du fil, du plus ancien au plus récent
     */
    public function findByUser(User $user, int $limit = 50): array
    {
        $messages = $this->createQueryBuilder('m')
            ->andWhere('m.user = :user')
            ->setParameter('user', $user)
            ->orderBy('m.createdAt', 'DESC')
            ->addOrderBy('m.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return array_reverse($messages);
    }

    /**
     * Messages de l'admin non lus par l'utilisateur (badge du widget).
     */
    public function countUnreadForUser(User $user): int
    {
        return (int) $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->andWhere('m.user = :user')
            ->andWhere('m.fromAdmin = true')
            ->andWhere('m.readAt IS NULL')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Messages utilisateur non lus par l'admin, toutes conversations confondues (badge nav admin).
     */
    public function countUnreadForAdmin(): int
    {
        return (int) $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->andWhere('m.fromAdmin = false')
            ->andWhere('m.readAt IS NULL')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Liste des conversations pour l'admin : une ligne par utilisateur ayant au
     * moins un message, avec le dernier message et le compteur de non-lus,
     * triée par dernier message décroissant.
     *
     * @return array<array{user: User, lastMessage: ChatMessage, unreadCount: int}>
     */
    public function findConversations(): array
    {
        $rows = $this->createQueryBuilder('m')
            ->select('IDENTITY(m.user) AS userId', 'MAX(m.createdAt) AS lastCreatedAt')
            ->addSelect("SUM(CASE WHEN m.fromAdmin = false AND m.readAt IS NULL THEN 1 ELSE 0 END) AS unreadCount")
            ->groupBy('m.user')
            ->orderBy('lastCreatedAt', 'DESC')
            ->getQuery()
            ->getArrayResult();

        if ($rows === []) {
            return [];
        }

        $lastMessages = $this->createQueryBuilder('m')
            ->join('m.user', 'u')->addSelect('u')
            ->andWhere('m.createdAt = (SELECT MAX(m2.createdAt) FROM App\Entity\ChatMessage m2 WHERE m2.user = m.user)')
            ->getQuery()
            ->getResult();

        $lastByUserId = [];
        foreach ($lastMessages as $message) {
            $lastByUserId[$message->getUser()->getId()] = $message;
        }

        $conversations = [];
        foreach ($rows as $row) {
            $lastMessage = $lastByUserId[$row['userId']] ?? null;
            if ($lastMessage === null) {
                continue;
            }
            $conversations[] = [
                'user' => $lastMessage->getUser(),
                'lastMessage' => $lastMessage,
                'unreadCount' => (int) $row['unreadCount'],
            ];
        }

        return $conversations;
    }

    /**
     * Marque comme lus les messages non lus d'un fil dans le sens donné
     * ($fromAdmin = true : l'utilisateur lit les réponses admin ;
     *  $fromAdmin = false : l'admin lit les messages de l'utilisateur).
     */
    public function markAsRead(User $user, bool $fromAdmin): void
    {
        $this->createQueryBuilder('m')
            ->update()
            ->set('m.readAt', ':now')
            ->andWhere('m.user = :user')
            ->andWhere('m.fromAdmin = :fromAdmin')
            ->andWhere('m.readAt IS NULL')
            ->setParameter('now', new \DateTimeImmutable())
            ->setParameter('user', $user)
            ->setParameter('fromAdmin', $fromAdmin)
            ->getQuery()
            ->execute();
    }
}

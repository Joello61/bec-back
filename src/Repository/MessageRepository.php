<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Conversation;
use App\Entity\Message;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class MessageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Message::class);
    }

    /**
     * Récupère les messages d'une conversation spécifique
     */
    public function findByConversation(Conversation $conversation, int $limit = 50, int $offset = 0): array
    {
        return $this->createQueryBuilder('m')
            ->leftJoin('m.expediteur', 'e')
            ->leftJoin('m.destinataire', 'd')
            ->addSelect('e', 'd')
            ->where('m.conversation = :conversation')
            ->setParameter('conversation', $conversation)
            ->orderBy('m.createdAt', 'ASC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Récupère les messages d'une conversation avec pagination
     */
    public function findByConversationPaginated(Conversation $conversation, int $page = 1, int $limit = 50): array
    {
        $offset = ($page - 1) * $limit;

        $messages = $this->findByConversation($conversation, $limit, $offset);

        $total = $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->where('m.conversation = :conversation')
            ->setParameter('conversation', $conversation)
            ->getQuery()
            ->getSingleScalarResult();

        return [
            'data' => $messages,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => (int)ceil($total / $limit),
            ],
        ];
    }

    /**
     * Compte les messages non lus d'une conversation pour un utilisateur
     */
    public function countUnreadInConversation(Conversation $conversation, User $user): int
    {
        return (int) $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->where('m.conversation = :conversation')
            ->andWhere('m.destinataire = :user')
            ->andWhere('m.lu = :false')
            ->setParameter('conversation', $conversation)
            ->setParameter('user', $user)
            ->setParameter('false', false)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Compte le nombre total de messages non lus pour un utilisateur
     */
    public function countUnread(int $userId): int
    {
        return (int) $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->where('m.destinataire = :userId')
            ->andWhere('m.lu = :lu')
            ->setParameter('userId', $userId)
            ->setParameter('lu', false)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Marque tous les messages d'une conversation comme lus pour un utilisateur
     */
    public function markConversationAsRead(Conversation $conversation, User $user): int
    {
        return $this->createQueryBuilder('m')
            ->update()
            ->set('m.lu', ':true')
            ->set('m.luAt', ':now')
            ->where('m.conversation = :conversation')
            ->andWhere('m.destinataire = :user')
            ->andWhere('m.lu = :false')
            ->setParameter('true', true)
            ->setParameter('false', false)
            ->setParameter('now', new \DateTime())
            ->setParameter('conversation', $conversation)
            ->setParameter('user', $user)
            ->getQuery()
            ->execute();
    }

    /**
     * Récupère le dernier message d'une conversation
     */
    public function findLastMessageInConversation(Conversation $conversation): ?Message
    {
        return $this->createQueryBuilder('m')
            ->where('m.conversation = :conversation')
            ->setParameter('conversation', $conversation)
            ->orderBy('m.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Récupère les N derniers messages d'une conversation
     */
    public function findRecentMessagesInConversation(Conversation $conversation, int $limit = 20): array
    {
        $messages = $this->createQueryBuilder('m')
            ->leftJoin('m.expediteur', 'e')
            ->leftJoin('m.destinataire', 'd')
            ->addSelect('e', 'd')
            ->where('m.conversation = :conversation')
            ->setParameter('conversation', $conversation)
            ->orderBy('m.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        // Inverser l'ordre pour avoir du plus ancien au plus récent
        return array_reverse($messages);
    }

    /**
     * Recherche dans les messages d'une conversation
     */
    public function searchInConversation(Conversation $conversation, string $query): array
    {
        return $this->createQueryBuilder('m')
            ->leftJoin('m.expediteur', 'e')
            ->leftJoin('m.destinataire', 'd')
            ->addSelect('e', 'd')
            ->where('m.conversation = :conversation')
            ->andWhere('m.contenu LIKE :query')
            ->setParameter('conversation', $conversation)
            ->setParameter('query', '%' . $query . '%')
            ->orderBy('m.createdAt', 'DESC')
            ->setMaxResults(50)
            ->getQuery()
            ->getResult();
    }

    /**
     * Supprime les anciens messages (plus de X jours)
     */
    public function deleteOldMessages(int $daysOld = 365): int
    {
        $date = new \DateTime();
        $date->modify("-{$daysOld} days");

        return $this->createQueryBuilder('m')
            ->delete()
            ->where('m.createdAt < :date')
            ->setParameter('date', $date)
            ->getQuery()
            ->execute();
    }
}

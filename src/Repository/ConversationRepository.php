<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Conversation;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class ConversationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Conversation::class);
    }

    /**
     * Trouve une conversation entre deux utilisateurs
     */
    public function findBetweenUsers(User $user1, User $user2): ?Conversation
    {
        return $this->createQueryBuilder('c')
            ->where('(c.participant1 = :user1 AND c.participant2 = :user2) OR (c.participant1 = :user2 AND c.participant2 = :user1)')
            ->setParameter('user1', $user1)
            ->setParameter('user2', $user2)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Trouve ou crée une conversation entre deux utilisateurs
     */
    public function findOrCreateBetweenUsers(User $user1, User $user2): Conversation
    {
        $conversation = $this->findBetweenUsers($user1, $user2);

        if ($conversation === null) {
            $conversation = new Conversation();
            $conversation->setParticipant1($user1);
            $conversation->setParticipant2($user2);

            $em = $this->getEntityManager();
            $em->persist($conversation);
            $em->flush();
        }

        return $conversation;
    }

    /**
     * Récupère toutes les conversations d'un utilisateur avec leur dernier message et le nombre de non lus
     */
    public function findByUserWithDetails(User $user): array
    {
        // Récupérer les conversations de l'utilisateur avec les messages
        $conversations = $this->createQueryBuilder('c')
            ->leftJoin('c.participant1', 'p1')
            ->leftJoin('c.participant2', 'p2')
            ->leftJoin('c.messages', 'm')
            ->addSelect('p1', 'p2', 'm')
            ->where('c.participant1 = :user OR c.participant2 = :user')
            ->setParameter('user', $user)
            ->orderBy('c.updatedAt', 'DESC')
            ->getQuery()
            ->getResult();

        // Enrichir chaque conversation avec les détails
        foreach ($conversations as $conversation) {
            // Définir le dernier message
            $messages = $conversation->getMessages()->toArray();
            if (!empty($messages)) {
                $dernierMessage = end($messages);
                $conversation->setDernierMessage($dernierMessage);
            }

            // Compter les messages non lus pour cet utilisateur
            $nonLus = $conversation->countMessagesNonLusPour($user);
            $conversation->setMessagesNonLus($nonLus);
        }

        return $conversations;
    }

    /**
     * Compte le nombre total de messages non lus pour un utilisateur (toutes conversations confondues)
     */
    public function countTotalUnreadMessagesForUser(User $user): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(m.id)')
            ->leftJoin('c.messages', 'm')
            ->where('c.participant1 = :user OR c.participant2 = :user')
            ->andWhere('m.destinataire = :user')
            ->andWhere('m.lu = :false')
            ->setParameter('user', $user)
            ->setParameter('false', false)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Récupère une conversation avec ses messages chargés
     */
    public function findOneWithMessages(int $conversationId): ?Conversation
    {
        return $this->createQueryBuilder('c')
            ->leftJoin('c.messages', 'm')
            ->leftJoin('m.expediteur', 'e')
            ->leftJoin('m.destinataire', 'd')
            ->leftJoin('c.participant1', 'p1')
            ->leftJoin('c.participant2', 'p2')
            ->addSelect('m', 'e', 'd', 'p1', 'p2')
            ->where('c.id = :id')
            ->setParameter('id', $conversationId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Supprime les conversations sans messages (nettoyage)
     */
    public function deleteEmptyConversations(): int
    {
        return $this->createQueryBuilder('c')
            ->delete()
            ->where('SIZE(c.messages) = 0')
            ->getQuery()
            ->execute();
    }
}

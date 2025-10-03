<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Message;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class MessageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Message::class);
    }

    public function findConversation(int $userId1, int $userId2): array
    {
        return $this->createQueryBuilder('m')
            ->leftJoin('m.expediteur', 'e')
            ->leftJoin('m.destinataire', 'd')
            ->addSelect('e', 'd')
            ->where('(m.expediteur = :user1 AND m.destinataire = :user2) OR (m.expediteur = :user2 AND m.destinataire = :user1)')
            ->setParameter('user1', $userId1)
            ->setParameter('user2', $userId2)
            ->orderBy('m.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findConversationsList(int $userId): array
    {
        $messages = $this->createQueryBuilder('m')
            ->leftJoin('m.expediteur', 'e')
            ->leftJoin('m.destinataire', 'd')
            ->addSelect('e', 'd')
            ->where('m.expediteur = :userId OR m.destinataire = :userId')
            ->setParameter('userId', $userId)
            ->orderBy('m.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        $conversations = [];
        $seen = [];

        foreach ($messages as $message) {
            $otherId = $message->getExpediteur()->getId() === $userId
                ? $message->getDestinataire()->getId()
                : $message->getExpediteur()->getId();

            if (!in_array($otherId, $seen)) {
                $seen[] = $otherId;
                $conversations[] = [
                    'user' => $message->getExpediteur()->getId() === $userId
                        ? $message->getDestinataire()
                        : $message->getExpediteur(),
                    'lastMessage' => $message,
                ];
            }
        }

        return $conversations;
    }

    public function countUnread(int $userId): int
    {
        return $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->where('m.destinataire = :userId')
            ->andWhere('m.lu = :lu')
            ->setParameter('userId', $userId)
            ->setParameter('lu', false)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function markAsRead(int $userId1, int $userId2): void
    {
        $this->createQueryBuilder('m')
            ->update()
            ->set('m.lu', ':lu')
            ->where('m.expediteur = :user2 AND m.destinataire = :user1')
            ->setParameter('lu', true)
            ->setParameter('user1', $userId1)
            ->setParameter('user2', $userId2)
            ->getQuery()
            ->execute();
    }
}

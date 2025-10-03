<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Message;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

class MessageVoter extends Voter
{
    public const string VIEW = 'MESSAGE_VIEW';
    public const string DELETE = 'MESSAGE_DELETE';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::DELETE])
            && $subject instanceof Message;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        /** @var Message $message */
        $message = $subject;

        return match($attribute) {
            self::VIEW => $this->canView($message, $user),
            self::DELETE => $this->canDelete($message, $user),
            default => false,
        };
    }

    private function canView(Message $message, User $user): bool
    {
        // L'expéditeur et le destinataire peuvent voir le message
        return $message->getExpediteur() === $user
            || $message->getDestinataire() === $user
            || in_array('ROLE_ADMIN', $user->getRoles());
    }

    private function canDelete(Message $message, User $user): bool
    {
        // Seul l'expéditeur peut supprimer son message
        return $message->getExpediteur() === $user
            || in_array('ROLE_ADMIN', $user->getRoles());
    }
}

<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\User;
use App\Entity\UserSubscription;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @extends Voter<string, UserSubscription|null>
 */
class SubscriptionVoter extends Voter
{
    public const VIEW = 'SUBSCRIPTION_VIEW';
    public const CANCEL = 'SUBSCRIPTION_CANCEL';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::CANCEL], true)
            && $subject instanceof UserSubscription;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User || !$subject instanceof UserSubscription) {
            return false;
        }

        if ($subject->getUser() === $user) {
            return true;
        }

        return in_array('ROLE_ADMIN', $user->getRoles());
    }
}

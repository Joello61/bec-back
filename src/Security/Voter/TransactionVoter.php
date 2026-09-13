<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Transaction;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Ownership d'une transaction (Lot N4) - obligatoire avant tout telechargement de
 * facture (CLAUDE.md section 9 : toute ressource appartenant a un utilisateur doit
 * etre verifiee via un Voter dedie, jamais une condition ad hoc dans le controleur).
 *
 * @extends Voter<string, Transaction>
 */
class TransactionVoter extends Voter
{
    public const VIEW = 'TRANSACTION_VIEW';
    public const DOWNLOAD_INVOICE = 'TRANSACTION_DOWNLOAD_INVOICE';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::DOWNLOAD_INVOICE], true)
            && $subject instanceof Transaction;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        if (in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            return true;
        }

        return $subject->getUser() === $user;
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Jeton de sécurité minimal pour tester un Voter isolément, sans authentifier
 * réellement un client HTTP - $voter->vote($token, $subject, [$attribute])
 * est l'API publique documentée par Symfony (Voter::vote(), héritée) pour ça.
 */
trait MockTokenTrait
{
    private function tokenFor(?UserInterface $user): TokenInterface
    {
        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        return $token;
    }
}

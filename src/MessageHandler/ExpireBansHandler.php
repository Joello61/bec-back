<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\ExpireBansMessage;
use App\Repository\UserRepository;
use App\Service\Admin\ModerationService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Nettoyage asynchrone de isBanned pour les bannissements temporaires arrives a
 * echeance. L'effet cote utilisateur est deja immediat (BannedUserListener verifie
 * User::isBanExpired() a chaque requete) - ce handler ne fait que remettre la base a
 * jour, pas de contrainte de latence forte contrairement a ExpireVoyagesHandler/
 * ExpireDemandesHandler.
 */
#[AsMessageHandler]
final readonly class ExpireBansHandler
{
    public function __construct(
        private UserRepository $userRepository,
        private ModerationService $moderationService,
        private LoggerInterface $logger,
    ) {}

    public function __invoke(ExpireBansMessage $message): void
    {
        $expiredUsers = $this->userRepository->findExpiredBans();

        if (empty($expiredUsers)) {
            $this->logger->info('Aucun bannissement temporaire à lever');
            return;
        }

        $this->logger->info(sprintf('Levée de %d bannissement(s) temporaire(s) arrivé(s) à échéance', count($expiredUsers)));

        foreach ($expiredUsers as $user) {
            $this->moderationService->expireBan($user);
        }
    }
}

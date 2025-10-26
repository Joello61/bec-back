<?php

namespace App\Service;

use App\Entity\User;
use Firebase\JWT\JWT;

/**
 * Génère les JWT pour Mercure, afin de contrôler les accès
 * aux topics privés, publics ou de groupe.
 */
readonly class MercureTokenService
{
    // 1. Définir les groupes qui ont un canal
    private const SPECIAL_GROUPS = ['admin', 'moderator'];

    public function __construct(
        private string $mercureSubscriberKey,
        private TopicBuilder $topicBuilder,
    ) {}

    /**
     * Génère un JWT Mercure pour un utilisateur donné.
     * @param User|null $user (null = utilisateur non connecté)
     */
    public function generate(?User $user = null, array $extraTopics = []): string
    {
        $subscribe = [];

        // Canal public disponible pour tous
        $subscribe[] = $this->topicBuilder->forPublic();

        if ($user) {
            // Canal privé de l’utilisateur
            $subscribe[] = $this->topicBuilder->forUser($user);

            // 2. Filtrer pour n'inclure que les groupes spéciaux
            foreach ($user->getRoles() as $role) {
                $group = strtolower(str_replace('ROLE_', '', $role));

                if (in_array($group, self::SPECIAL_GROUPS, true)) {
                    $subscribe[] = $this->topicBuilder->forGroup($group);
                }
            }
        }

        // ➕ Ajout des topics personnalisés
        $subscribe = array_merge($subscribe, $extraTopics);

        // 3. Assurer l'unicité des topics
        $subscribe = array_values(array_unique($subscribe));

        // Payload du JWT Mercure
        $payload = [
            'mercure' => [
                'subscribe' => $subscribe,
                'publish' => [],
            ],
            'iat' => time(),
            'exp' => time() + 3600, // 1h
        ];

        try {
            return JWT::encode($payload, $this->mercureSubscriberKey, 'HS256');
        } catch (\Throwable $e) {
            throw new \RuntimeException('Erreur lors de la génération du token Mercure : ' . $e->getMessage());
        }
    }
}

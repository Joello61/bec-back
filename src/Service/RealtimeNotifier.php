<?php

namespace App\Service;

use App\Entity\User;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * Service centralisé de publication d’événements Mercure.
 *
 * - Simplifie la logique : User / Group / Public / Topic-type (voyages, demandes…)
 * - Évite la création de milliers de topics individuels.
 */
readonly class RealtimeNotifier
{
    public function __construct(
        private HubInterface $hub,
        private TopicBuilder $topicBuilder
    ) {}

    /**
     * Publication générique.
     *
     * @throws \JsonException
     */
    private function publish(string $topic, array $data, string $eventType, bool $private = false): void
    {
        $payload = [
            'eventType' => $eventType,
            'timestamp' => (new \DateTimeImmutable())->format('c'),
            'data' => $data,
        ];

        $update = new Update(
            $topic,
            json_encode($payload, JSON_THROW_ON_ERROR),
            $private,
            $eventType
        );

        $this->hub->publish($update);
    }

    /**
     * Notifie un utilisateur spécifique.
     * @throws \JsonException
     */
    public function publishToUser(User $user, array $data, string $eventType): void
    {
        $this->publish(
            $this->topicBuilder->forUser($user),
            $data,
            $eventType,
            true
        );
    }

    /**
     * Notifie un groupe spécifique (ex: admin, modérateurs...).
     * @throws \JsonException
     */
    public function publishToGroup(string $group, array $data, string $eventType): void
    {
        $this->publish(
            $this->topicBuilder->forGroup($group),
            $data,
            $eventType,
            true
        );
    }

    /**
     * Publication sur le flux public global (news, alertes, etc.)
     * @throws \JsonException
     */
    public function publishPublic(array $data, string $eventType): void
    {
        $this->publish(
            $this->topicBuilder->forPublic(),
            $this->enrichPayload($data),
            $eventType,
            false
        );
    }

    /**
     * Publication sur le flux public des DEMANDES.
     * @throws \JsonException
     */
    public function publishDemandes(array $data, string $eventType): void
    {
        $this->publish(
            $this->topicBuilder->forDemandes(),
            $this->enrichPayload($data),
            $eventType,
            false
        );
    }

    /**
     * Publication sur le flux public des VOYAGES.
     * @throws \JsonException
     */
    public function publishVoyages(array $data, string $eventType): void
    {
        $this->publish(
            $this->topicBuilder->forVoyages(),
            $this->enrichPayload($data),
            $eventType,
            false
        );
    }


    /**
     * Ajoute des métadonnées standard à chaque payload
     * (utile pour les filtres côté frontend).
     */
    private function enrichPayload(array $data): array
    {
        return array_merge([
            'serverTime' => (new \DateTimeImmutable())->format('c'),
        ], $data);
    }
}

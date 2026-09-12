<?php

declare(strict_types=1);

namespace App\DTO;

use App\Service\BoostService;
use App\Service\SubscriptionService;
use Symfony\Component\Validator\Constraints as Assert;

class CheckoutBoostDTO
{
    #[Assert\NotBlank(message: 'Le type de cible est obligatoire')]
    #[Assert\Choice(choices: [BoostService::TARGET_VOYAGE, BoostService::TARGET_DEMANDE], message: 'Type de cible invalide')]
    public string $targetType;

    #[Assert\NotBlank(message: "L'identifiant de la cible est obligatoire")]
    #[Assert\Positive(message: "L'identifiant de la cible doit être positif")]
    public int $targetId;

    #[Assert\NotBlank(message: "L'offre de boost est obligatoire")]
    #[Assert\Positive(message: "L'identifiant de l'offre doit être positif")]
    public int $offerId;

    #[Assert\NotBlank(message: 'Le moyen de paiement est obligatoire')]
    #[Assert\Choice(choices: [SubscriptionService::PAYMENT_METHOD_CARD, SubscriptionService::PAYMENT_METHOD_MOBILE_MONEY], message: 'Moyen de paiement invalide')]
    public string $paymentMethod;

    /**
     * Consentement exprès à un accès immédiat au service (art. L.221-28 13° Code
     * conso). Revalidé côté backend - jamais une confiance dans le frontend.
     */
    #[Assert\IsTrue(message: 'L\'accord pour un accès immédiat est obligatoire')]
    public bool $accessImmediateConsent = false;

    /**
     * Renonciation expresse et séparée au droit de rétractation de 14 jours - doit
     * rester une case distincte du consentement ci-dessus (jamais fusionnées).
     */
    #[Assert\IsTrue(message: 'La renonciation au droit de rétractation est obligatoire')]
    public bool $withdrawalWaiverConsent = false;
}

<?php

declare(strict_types=1);

namespace App\DTO;

use Symfony\Component\Validator\Constraints as Assert;

class CheckoutSubscriptionDTO
{
    #[Assert\NotBlank(message: 'Le plan est obligatoire')]
    public string $planCode;

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

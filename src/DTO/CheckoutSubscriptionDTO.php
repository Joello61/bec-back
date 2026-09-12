<?php

declare(strict_types=1);

namespace App\DTO;

use App\Entity\UserSubscription;
use App\Service\SubscriptionService;
use Symfony\Component\Validator\Constraints as Assert;

class CheckoutSubscriptionDTO
{
    #[Assert\NotBlank(message: 'Le plan est obligatoire')]
    public string $planCode;

    #[Assert\NotBlank(message: 'Le moyen de paiement est obligatoire')]
    #[Assert\Choice(choices: [SubscriptionService::PAYMENT_METHOD_CARD, SubscriptionService::PAYMENT_METHOD_MOBILE_MONEY], message: 'Moyen de paiement invalide')]
    public string $paymentMethod;

    /**
     * Cadence de facturation (Lot 6.3) - choisie au checkout, jamais figee sur le plan
     * (cf. SubscriptionPlan::billingPeriod, vestige non exploite).
     */
    #[Assert\NotBlank(message: 'La cadence de facturation est obligatoire')]
    #[Assert\Choice(choices: [UserSubscription::BILLING_PERIOD_MONTHLY, UserSubscription::BILLING_PERIOD_YEARLY], message: 'Cadence de facturation invalide')]
    public string $billingPeriod = UserSubscription::BILLING_PERIOD_MONTHLY;

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

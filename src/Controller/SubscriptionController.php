<?php

declare(strict_types=1);

namespace App\Controller;

use App\DTO\CheckoutSubscriptionDTO;
use App\Entity\User;
use App\Repository\DemandeRepository;
use App\Repository\SubscriptionPlanRepository;
use App\Repository\VoyageRepository;
use App\Service\SubscriptionService;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/subscriptions', name: 'api_subscriptions_')]
#[OA\Tag(name: 'Subscriptions')]
class SubscriptionController extends AbstractController
{
    public function __construct(
        private readonly SubscriptionService $subscriptionService,
        private readonly SubscriptionPlanRepository $subscriptionPlanRepository,
        private readonly VoyageRepository $voyageRepository,
        private readonly DemandeRepository $demandeRepository,
        private readonly RateLimiterFactoryInterface $paymentCheckoutLimiter,
        private readonly string $frontendUrl,
    ) {}

    #[Route('/plans', name: 'plans', methods: ['GET'])]
    #[OA\Get(path: '/api/subscriptions/plans', summary: 'Liste des plans proposés à la souscription')]
    #[OA\Response(response: 200, description: 'Liste des plans')]
    public function plans(): JsonResponse
    {
        $plans = $this->subscriptionPlanRepository->findAllActive();

        return $this->json($plans, Response::HTTP_OK, [], ['groups' => ['subscription_plan:list']]);
    }

    #[Route('/me', name: 'me', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    #[OA\Get(
        path: '/api/subscriptions/me',
        summary: 'Abonnement effectif et usage du quota freemium de l\'utilisateur courant',
        security: [['cookieAuth' => []]]
    )]
    #[OA\Response(response: 200, description: 'Abonnement courant')]
    public function me(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $plan = $this->subscriptionService->getEffectivePlan($user);
        $subscription = $this->subscriptionService->getActiveSubscription($user);

        return $this->json([
            'plan' => $plan,
            'subscription' => $subscription,
            'usage' => [
                'activeVoyages' => $this->voyageRepository->countActiveByUser($user),
                'maxActiveVoyages' => $plan->getMaxActiveVoyages(),
                'activeDemandes' => $this->demandeRepository->countActiveByUser($user),
                'maxActiveDemandes' => $plan->getMaxActiveDemandes(),
            ],
        ], Response::HTTP_OK, [], ['groups' => ['subscription_plan:read', 'user_subscription:read']]);
    }

    #[Route('/checkout', name: 'checkout', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    #[OA\Post(
        path: '/api/subscriptions/checkout',
        summary: 'Démarre un checkout Stripe pour un plan payant',
        security: [['cookieAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: new Model(type: CheckoutSubscriptionDTO::class))
        )
    )]
    #[OA\Response(response: 200, description: 'URL de checkout Stripe')]
    #[OA\Response(response: 429, description: 'Trop de tentatives')]
    public function checkout(
        #[MapRequestPayload] CheckoutSubscriptionDTO $dto,
        Request $request,
    ): JsonResponse {
        /** @var User $user */
        $user = $this->getUser();

        $limiter = $this->paymentCheckoutLimiter->create((string) $user->getId());

        if (false === $limiter->consume(1)->isAccepted()) {
            return $this->json(
                ['message' => 'Trop de tentatives de paiement. Réessayez plus tard.'],
                Response::HTTP_TOO_MANY_REQUESTS
            );
        }

        $result = $this->subscriptionService->checkout(
            $user,
            $dto->planCode,
            $dto->paymentMethod,
            sprintf('%s/dashboard/settings/subscription/success', $this->frontendUrl),
            sprintf('%s/dashboard/settings/subscription/cancel', $this->frontendUrl),
        );

        return $this->json(['checkoutUrl' => $result->checkoutUrl], Response::HTTP_OK);
    }

    #[Route('/cancel', name: 'cancel', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    #[OA\Post(
        path: '/api/subscriptions/cancel',
        summary: 'Résilie l\'abonnement actif à la fin de la période en cours',
        security: [['cookieAuth' => []]]
    )]
    #[OA\Response(response: 200, description: 'Résiliation programmée')]
    public function cancel(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $subscription = $this->subscriptionService->getActiveSubscription($user);

        if ($subscription !== null) {
            $this->denyAccessUnlessGranted('SUBSCRIPTION_CANCEL', $subscription);
        }

        $this->subscriptionService->cancelSubscription($user);

        return $this->json(['message' => 'Résiliation programmée à la fin de la période en cours'], Response::HTTP_OK);
    }
}

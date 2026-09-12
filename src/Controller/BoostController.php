<?php

declare(strict_types=1);

namespace App\Controller;

use App\DTO\CheckoutBoostDTO;
use App\Entity\Demande;
use App\Entity\User;
use App\Entity\Voyage;
use App\Repository\BoostOfferRepository;
use App\Repository\DemandeRepository;
use App\Repository\VoyageRepository;
use App\Service\BoostService;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/boosts', name: 'api_boosts_')]
#[OA\Tag(name: 'Boosts')]
class BoostController extends AbstractController
{
    public function __construct(
        private readonly BoostService $boostService,
        private readonly BoostOfferRepository $boostOfferRepository,
        private readonly VoyageRepository $voyageRepository,
        private readonly DemandeRepository $demandeRepository,
        // Meme limiteur que l'abonnement (payment_checkout) : pas de nouveau
        // rate-limiter, la cle reste l'id utilisateur.
        private readonly RateLimiterFactoryInterface $paymentCheckoutLimiter,
        private readonly string $frontendUrl,
    ) {}

    #[Route('/offers', name: 'offers', methods: ['GET'])]
    #[OA\Get(path: '/api/boosts/offers', summary: 'Liste des offres de boost proposées à l\'achat')]
    #[OA\Response(response: 200, description: 'Liste des offres')]
    public function offers(): JsonResponse
    {
        $offers = $this->boostOfferRepository->findAllActive();

        return $this->json($offers, Response::HTTP_OK, [], ['groups' => ['boost_offer:list']]);
    }

    #[Route('/checkout', name: 'checkout', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    #[OA\Post(
        path: '/api/boosts/checkout',
        summary: 'Démarre un checkout Stripe pour booster un voyage ou une demande',
        security: [['cookieAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: new Model(type: CheckoutBoostDTO::class))
        )
    )]
    #[OA\Response(response: 200, description: 'URL de checkout Stripe')]
    #[OA\Response(response: 403, description: 'Cible non détenue par l\'utilisateur')]
    #[OA\Response(response: 429, description: 'Trop de tentatives')]
    public function checkout(#[MapRequestPayload] CheckoutBoostDTO $dto): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $target = $this->resolveTarget($dto->targetType, $dto->targetId);
        $this->denyAccessUnlessGranted('BOOST_CREATE', $target);

        $limiter = $this->paymentCheckoutLimiter->create((string) $user->getId());

        if (false === $limiter->consume(1)->isAccepted()) {
            return $this->json(
                ['message' => 'Trop de tentatives de paiement. Réessayez plus tard.'],
                Response::HTTP_TOO_MANY_REQUESTS
            );
        }

        $detailsPath = $dto->targetType === BoostService::TARGET_VOYAGE
            ? sprintf('/dashboard/mes-voyages/%d', $dto->targetId)
            : sprintf('/dashboard/mes-demandes/%d', $dto->targetId);

        $result = $this->boostService->checkout(
            $user,
            $dto->targetType,
            $dto->targetId,
            $dto->offerId,
            $dto->paymentMethod,
            $this->frontendUrl . $detailsPath,
            $this->frontendUrl . $detailsPath,
        );

        return $this->json(['checkoutUrl' => $result->checkoutUrl], Response::HTTP_OK);
    }

    private function resolveTarget(string $targetType, int $targetId): Voyage|Demande
    {
        $target = match ($targetType) {
            BoostService::TARGET_VOYAGE => $this->voyageRepository->find($targetId),
            BoostService::TARGET_DEMANDE => $this->demandeRepository->find($targetId),
            default => null,
        };

        if ($target === null) {
            throw new NotFoundHttpException('Cible du boost introuvable');
        }

        return $target;
    }
}

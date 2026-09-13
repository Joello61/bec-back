<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\User;
use App\Repository\DemandeRepository;
use App\Repository\VoyageRepository;
use App\Service\SubscriptionService;
use Symfony\Component\DependencyInjection\Attribute\Lazy;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Psr\Log\LoggerInterface;

readonly class ExceptionListener
{
    public function __construct(
        private LoggerInterface $logger,
        private string $environment,
        private bool $debug,
        // #[Lazy] obligatoire : SubscriptionService entraine desormais (Lots N4/N5)
        // PaymentService -> InvoiceService -> invoices.storage -> app.r2_client, qui
        // resout des variables d'environnement R2_* au moment de la CONSTRUCTION (pas de
        // l'appel). Sans #[Lazy], la moindre exception geree par ce listener - meme sans
        // aucun rapport avec le quota - forcerait la construction de toute cette chaine et
        // ferait echouer la gestion de l'exception elle-meme si R2_* est absent/mal
        // configure (constate en session : /api/health, /api/register plantaient tous les
        // deux en 500 sans lien avec leur propre logique). Un service lazy ne construit la
        // vraie instance qu'au premier appel de methode - ici, uniquement pour un
        // VOYAGE_CREATE/DEMANDE_CREATE refuse, jamais pour les autres exceptions.
        #[Lazy]
        private ?SubscriptionService $subscriptionService = null,
        private ?VoyageRepository $voyageRepository = null,
        private ?DemandeRepository $demandeRepository = null,
    ) {}

    public function onKernelException(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();
        $request = $event->getRequest();

        // Ne gérer que les routes API
        if (!str_starts_with($request->getPathInfo(), '/api')) {
            return;
        }

        $statusCode = JsonResponse::HTTP_INTERNAL_SERVER_ERROR;
        $message = 'Une erreur est survenue';
        $errors = [];

        // Gestion des exceptions HTTP
        if ($exception instanceof HttpExceptionInterface) {
            $statusCode = $exception->getStatusCode();
            $message = $exception->getMessage();
        }

        // Gestion des erreurs d'authentification
        if ($exception instanceof AuthenticationException) {
            $statusCode = Response::HTTP_UNAUTHORIZED;
            $message = 'Authentification requise';
        }

        // Gestion des erreurs d'autorisation
        if ($exception instanceof AccessDeniedException) {
            $statusCode = Response::HTTP_FORBIDDEN;
            $message = 'Accès refusé';
        }

        // En développement OU si APP_DEBUG=1, ajouter plus de détails
        if ($this->environment === 'dev' || $this->debug) {
            $message = $exception->getMessage();
            $errors = [
                'exception' => get_class($exception),
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => explode("\n", $exception->getTraceAsString()),
            ];
        }

        // ==================== ACCESS DENIED (Voters) ====================
        if ($exception instanceof AccessDeniedException || $exception instanceof AccessDeniedHttpException) {
            $user = $request->attributes->get('_security_user');

            // Vérifier si c'est un problème de profil incomplet
            if ($user && method_exists($user, 'isProfileComplete') && !$user->isProfileComplete()) {
                $response = new JsonResponse([
                    'success' => false,
                    'error' => 'PROFILE_INCOMPLETE',
                    'message' => 'Vous devez compléter votre profil pour effectuer cette action',
                    'profileComplete' => false,
                    'details' => $this->getProfileMissingFields($user)
                ], Response::HTTP_FORBIDDEN);

                $event->setResponse($response);
                return;
            }

            // ==================== QUOTA FREEMIUM DÉPASSÉ (monétisation, Partie A Lot N1) ====================
            // Recalcule la même vérification que VoyageVoter::canCreate()/DemandeVoter::canCreate()
            // pour distinguer un quota dépassé d'un ACCESS_DENIED générique, et donner un message
            // actionnable au frontend. Fail-open : cf. Voters, une erreur ici ne doit jamais faire
            // planter la gestion de l'exception elle-même - on retombe simplement sur ACCESS_DENIED.
            $quotaAttribute = $this->getQuotaCreateAttribute($exception);
            if ($user instanceof User && $quotaAttribute !== null) {
                $quotaExceededResponse = $this->buildQuotaExceededResponseIfApplicable($user, $quotaAttribute);
                if ($quotaExceededResponse !== null) {
                    $event->setResponse($quotaExceededResponse);
                    return;
                }
            }

            // Autres cas d'access denied
            $response = new JsonResponse([
                'success' => false,
                'error' => 'ACCESS_DENIED',
                'message' => 'Vous n\'avez pas les permissions nécessaires pour effectuer cette action'
            ], Response::HTTP_FORBIDDEN);

            $event->setResponse($response);
            return;
        }

        // Logger l'erreur
        $this->logger->error('API Exception', [
            'exception' => get_class($exception),
            'message' => $exception->getMessage(),
            'code' => $statusCode,
            'path' => $request->getPathInfo(),
            'method' => $request->getMethod(),
        ]);

        // Créer la réponse JSON
        $response = new JsonResponse([
            'success' => false,
            'message' => $message,
            'errors' => $errors,
        ], $statusCode);

        $event->setResponse($response);
    }

    /**
     * Retourne les champs manquants du profil
     *
     * @return string[]
     */
    private function getProfileMissingFields(User $user): array
    {
        $missing = [];

        if (!$user->isEmailVerifie()) {
            $missing[] = 'email_verification';
        }

        if (!$user->getTelephone()) {
            $missing[] = 'telephone';
        }

        if (!$user->isTelephoneVerifie() && $user->getTelephone()) {
            $missing[] = 'telephone_verification';
        }

        $address = $user->getAddress();

        if (!$address || !$address->getPays() || !$address->getVille()) {
            $missing[] = 'location';
        }

        $africanFormat = $address?->getQuartier() !== null;
        $diasporaFormat = $address?->getAdresseLigne1() !== null && $address->getCodePostal() !== null;

        if (!$africanFormat && !$diasporaFormat) {
            $missing[] = 'address';
        }

        return $missing;
    }

    /**
     * Retourne 'VOYAGE_CREATE'/'DEMANDE_CREATE' si l'AccessDeniedException porte l'un de ces
     * deux attributs (setAttributes() appelé par denyAccessUnlessGranted(), cf.
     * AbstractController::denyAccessUnlessGranted()), sinon null.
     */
    private function getQuotaCreateAttribute(\Throwable $exception): ?string
    {
        if (!$exception instanceof AccessDeniedException) {
            return null;
        }

        foreach (['VOYAGE_CREATE', 'DEMANDE_CREATE'] as $attribute) {
            if (in_array($attribute, $exception->getAttributes(), true)) {
                return $attribute;
            }
        }

        return null;
    }

    private function buildQuotaExceededResponseIfApplicable(User $user, string $quotaAttribute): ?JsonResponse
    {
        if ($this->subscriptionService === null || $this->voyageRepository === null || $this->demandeRepository === null) {
            return null;
        }

        try {
            $plan = $this->subscriptionService->getEffectivePlan($user);

            if ($quotaAttribute === 'VOYAGE_CREATE') {
                $limit = $plan->getMaxActiveVoyages();
                $current = $this->voyageRepository->countActiveByUser($user);
            } else {
                $limit = $plan->getMaxActiveDemandes();
                $current = $this->demandeRepository->countActiveByUser($user);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Quota freemium indisponible pour enrichir le message 403 (catalogue d\'abonnement non seede ?)', [
                'exception' => $e->getMessage(),
            ]);

            return null;
        }

        if ($limit === null || $current < $limit) {
            return null;
        }

        return new JsonResponse([
            'success' => false,
            'error' => 'QUOTA_EXCEEDED',
            'message' => 'Vous avez atteint la limite de votre plan actuel. Passez à un plan supérieur pour continuer.',
            'currentPlan' => $plan->getCode(),
            'limit' => $limit,
        ], Response::HTTP_FORBIDDEN);
    }
}

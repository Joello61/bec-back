<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\User;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Listener qui bloque automatiquement les utilisateurs bannis
 * sauf sur les routes de logout
 *
 * Bug decouvert en session (Phase 7b-B, e2e/admin.spec.ts) : ce listener etait accroche sur
 * KernelEvents::REQUEST (via un tag manuel dans config/services.yaml, en plus de l'attribut
 * ci-dessous - double enregistrement redondant, retire) et lisait TokenStorageInterface a ce
 * moment-la. Or pour le firewall "api" (stateless, authentification JWT), le token n'est
 * authentifie que lazily, au moment ou quelque chose le consulte reellement pour la premiere
 * fois - constate empiriquement (log temporaire) : token toujours NULL sur kernel.request,
 * quelle que soit la priorite testee (10, 7, -100), et encore NULL sur kernel.controller. Le
 * premier point ou le token est garanti authentifie est KernelEvents::CONTROLLER_ARGUMENTS
 * (declenche par la resolution des arguments du controleur, notamment les attributs
 * #[IsGranted]/#[CurrentUser] qui forcent l'authentification - IsGrantedAttributeListener
 * s'y accroche a priority: 20, ce listener-ci a une priorite plus basse pour s'executer
 * apres). Consequence concrete du bug : un utilisateur banni continuait de repondre 200 sur
 * /api/me indefiniment - seul le test unitaire dedie (mock direct de TokenStorageInterface,
 * sans dispatch reel du cycle kernel/firewall) le masquait. Regression couverte par
 * tests/Functional/BannedUserAccessTest.php (dispatch reel, pas de mock du firewall).
 */
#[AsEventListener(event: KernelEvents::CONTROLLER_ARGUMENTS, priority: 0)]
class BannedUserListener
{
    public function __construct(
        private readonly TokenStorageInterface $tokenStorage,
    ) {}

    public function __invoke(ControllerArgumentsEvent $event): void
    {
        // Ne traiter que les requêtes principales
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $token = $this->tokenStorage->getToken();

        // Pas de token = pas d'utilisateur connecté
        if (!$token) {
            return;
        }

        $user = $token->getUser();

        // Pas un objet User
        if (!$user instanceof User) {
            return;
        }

        // L'utilisateur n'est pas banni
        if (!$user->isBanned()) {
            return;
        }

        // Bannissement temporaire arrive a echeance : effet immediat ici (avant meme le
        // nettoyage asynchrone de isBanned par ModerationService::expireBan(), planifie
        // via ExpirationScheduleProvider) - ne jamais faire attendre un utilisateur jusqu'au
        // prochain passage du scheduler pour recuperer l'acces.
        if ($user->isBanExpired()) {
            return;
        }

        // Autoriser l'accès à la route de logout
        $path = $request->getPathInfo();
        if (str_contains($path, '/logout') || str_contains($path, '/api/logout')) {
            return;
        }

        // Autoriser l'accès aux routes publiques
        $publicRoutes = [
            '/api/login',
            '/api/register',
            '/api/forgot-password',
            '/api/reset-password',
        ];

        foreach ($publicRoutes as $publicRoute) {
            if (str_starts_with($path, $publicRoute)) {
                return;
            }
        }

        // Bloquer l'accès avec un message personnalisé
        $bannedAt = $user->getBannedAt();
        $banReason = $user->getBanReason();

        $message = 'Votre compte a été suspendu.';

        if ($bannedAt) {
            $message .= sprintf(' Date : %s.', $bannedAt->format('d/m/Y à H:i'));
        }

        if ($banReason) {
            $message .= sprintf(' Raison : %s', $banReason);
        }

        $message .= ' Veuillez contacter le support si vous pensez qu\'il s\'agit d\'une erreur.';

        $response = new JsonResponse([
            'error' => 'account_banned',
            'message' => $message,
            'bannedAt' => $bannedAt?->format('c'),
            'reason' => $banReason,
        ], Response::HTTP_FORBIDDEN);

        // ControllerArgumentsEvent (contrairement a RequestEvent) n'a pas de setResponse() -
        // court-circuiter en remplaçant le controleur par une closure qui retourne directement
        // la reponse (patron documente pour ce type d'evenement), sans arguments a resoudre.
        $event->setController(fn () => $response);
        $event->setArguments([]);
    }
}

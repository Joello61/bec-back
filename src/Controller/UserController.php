<?php

declare(strict_types=1);

namespace App\Controller;

use App\DTO\CompleteProfileDTO;
use App\DTO\UpdateAddressDTO;
use App\DTO\UpdateUserDTO;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\AddressService;
use App\Service\UserStatsService;
use App\Service\VerificationService;
use Doctrine\ORM\EntityManagerInterface;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/users', name: 'api_user_')]
#[OA\Tag(name: 'Utilisateurs')]
class UserController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly UserStatsService $userStatsService,
        private readonly VerificationService $verificationService,
        private readonly AddressService $addressService
    ) {}

    // ==================== STATUT PROFIL ====================

    #[Route('/me/profile-status', name: 'profile_status', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    #[OA\Get(
        path: '/api/users/me/profile-status',
        summary: 'Vérifier si le profil est complet',
        security: [['cookieAuth' => []]]
    )]
    #[OA\Response(
        response: 200,
        description: 'Statut du profil',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'isComplete', type: 'boolean'),
                new OA\Property(property: 'missing', type: 'array', items: new OA\Items(type: 'string')),
                new OA\Property(property: 'emailVerifie', type: 'boolean'),
                new OA\Property(property: 'telephoneVerifie', type: 'boolean'),
                new OA\Property(property: 'hasAddress', type: 'boolean'),
            ]
        )
    )]
    public function profileStatus(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

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
        if (!$address) {
            $missing[] = 'address';
        } elseif (!$address->isValid()) {
            $missing[] = 'address_complete';
        }

        return $this->json([
            'isComplete' => $user->isProfileComplete(),
            'missing' => $missing,
            'emailVerifie' => $user->isEmailVerifie(),
            'telephoneVerifie' => $user->isTelephoneVerifie(),
            'hasAddress' => $address !== null,
        ]);
    }

    // ==================== COMPLÉTER PROFIL ====================

    #[Route('/me/complete-profile', name: 'complete_profile', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    #[OA\Post(
        path: '/api/users/me/complete-profile',
        summary: 'Compléter son profil',
        security: [['cookieAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: new Model(type: CompleteProfileDTO::class))
        )
    )]
    #[OA\Response(response: 200, description: 'Profil complété, code SMS envoyé')]
    #[OA\Response(response: 400, description: 'Données invalides')]
    public function completeProfile(
        #[MapRequestPayload] CompleteProfileDTO $dto
    ): JsonResponse {
        /** @var User $user */
        $user = $this->getUser();

        // Mettre à jour le téléphone
        $user->setTelephone($dto->telephone);

        // Champs optionnels
        if ($dto->bio) {
            $user->setBio($dto->bio);
        }
        if ($dto->photo) {
            $user->setPhoto($dto->photo);
        }

        // Créer l'adresse via AddressService
        try {
            $this->addressService->createAddress($user, $dto->toAddressArray());
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => $e->getMessage()
            ], Response::HTTP_BAD_REQUEST);
        }

        $this->entityManager->flush();

        // Envoyer code SMS pour vérification
        try {
            $this->verificationService->sendPhoneVerification($user);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => 'Profil mis à jour mais erreur lors de l\'envoi du SMS : ' . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return $this->json([
            'success' => true,
            'message' => 'Profil complété. Un code de vérification a été envoyé par SMS.',
            'user' => [
                'id' => $user->getId(),
                'telephone' => $user->getTelephone(),
                'address' => [
                    'pays' => $user->getAddress()->getPays(),
                    'ville' => $user->getAddress()->getVille(),
                    'quartier' => $user->getAddress()->getQuartier(),
                    'adresseLigne1' => $user->getAddress()->getAdresseLigne1(),
                    'codePostal' => $user->getAddress()->getCodePostal(),
                ],
                'isProfileComplete' => $user->isProfileComplete(),
            ]
        ]);
    }

    // ==================== INFO MODIFICATION ADRESSE ====================

    #[Route('/me/address/modification-info', name: 'address_modification_info', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    #[OA\Get(
        path: '/api/users/me/address/modification-info',
        summary: 'Informations sur la possibilité de modifier l\'adresse',
        security: [['cookieAuth' => []]]
    )]
    #[OA\Response(
        response: 200,
        description: 'Informations de modification',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'canModify', type: 'boolean'),
                new OA\Property(property: 'hasAddress', type: 'boolean'),
                new OA\Property(property: 'lastModifiedAt', type: 'string', nullable: true),
                new OA\Property(property: 'nextModificationDate', type: 'string', nullable: true),
                new OA\Property(property: 'daysRemaining', type: 'integer', nullable: true),
                new OA\Property(property: 'message', type: 'string'),
            ]
        )
    )]
    public function addressModificationInfo(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $info = $this->addressService->getModificationInfo($user);

        return $this->json($info);
    }

    // ==================== METTRE À JOUR ADRESSE ====================

    #[Route('/me/address', name: 'update_address', methods: ['PUT'])]
    #[IsGranted('ROLE_USER')]
    #[OA\Put(
        path: '/api/users/me/address',
        summary: 'Modifier son adresse (max 1 fois tous les 6 mois)',
        security: [['cookieAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: new Model(type: UpdateAddressDTO::class))
        )
    )]
    #[OA\Response(response: 200, description: 'Adresse mise à jour')]
    #[OA\Response(response: 400, description: 'Modification non autorisée (contrainte 6 mois)')]
    public function updateAddress(
        #[MapRequestPayload] UpdateAddressDTO $dto
    ): JsonResponse {
        /** @var User $user */
        $user = $this->getUser();

        $address = $user->getAddress();

        if (!$address) {
            return $this->json([
                'success' => false,
                'message' => 'Aucune adresse trouvée. Utilisez /complete-profile'
            ], Response::HTTP_BAD_REQUEST);
        }

        try {
            $addressData = [
                'pays' => $dto->pays,
                'ville' => $dto->ville,
            ];

            if ($dto->quartier !== null) {
                $addressData['quartier'] = $dto->quartier;
            }
            if ($dto->adresseLigne1 !== null) {
                $addressData['adresseLigne1'] = $dto->adresseLigne1;
            }
            if ($dto->adresseLigne2 !== null) {
                $addressData['adresseLigne2'] = $dto->adresseLigne2;
            }
            if ($dto->codePostal !== null) {
                $addressData['codePostal'] = $dto->codePostal;
            }

            $this->addressService->updateAddress($address, $addressData);

            return $this->json([
                'success' => true,
                'message' => 'Adresse mise à jour avec succès',
                'address' => $address,
                'nextModificationDate' => $address->getNextModificationDate()?->format('c')
            ], Response::HTTP_OK, [], ['groups' => ['address:read']]);

        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => $e->getMessage()
            ], Response::HTTP_BAD_REQUEST);
        }
    }

    // ==================== LISTER UTILISATEURS ====================

    #[Route('', name: 'list', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    #[OA\Get(
        path: '/api/users',
        summary: 'Liste des utilisateurs',
        security: [['cookieAuth' => []]]
    )]
    #[OA\Parameter(
        name: 'page',
        in: 'query',
        schema: new OA\Schema(type: 'integer', default: 1)
    )]
    #[OA\Parameter(
        name: 'limit',
        in: 'query',
        schema: new OA\Schema(type: 'integer', default: 10)
    )]
    #[OA\Response(response: 200, description: 'Liste paginée')]
    public function list(Request $request): JsonResponse
    {
        $page = $request->query->getInt('page', 1);
        $limit = $request->query->getInt('limit', 10);

        $result = $this->userRepository->findPaginated($page, $limit);

        return $this->json($result, Response::HTTP_OK, [], ['groups' => ['user:read']]);
    }

    // ==================== PROFIL UTILISATEUR ====================

    #[Route('/{id}', name: 'show', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    #[OA\Get(
    path: '/api/users/{id}',
    summary: 'Profil d\'un utilisateur',
    security: [['cookieAuth' => []]]
    )]
    #[OA\Parameter(
    name: 'id',
    in: 'path',
    required: true,
    schema: new OA\Schema(type: 'integer')
    )]
    #[OA\Response(response: 200, description: 'Détails utilisateur')]
    #[OA\Response(response: 404, description: 'Utilisateur non trouvé')]
    public function show(int $id): JsonResponse
    {
        $user = $this->userRepository->find($id);

        if (!$user) {
            return $this->json(['message' => 'Utilisateur non trouvé'], Response::HTTP_NOT_FOUND);
        }

        /** @var User|null $viewer */
        $viewer = $this->getUser();

        $profileData = $this->userStatsService->getVisibleProfileData($user, $viewer);

        return $this->json($profileData, Response::HTTP_OK);
    }

    // ==================== MODIFIER SON PROFIL ====================

    #[Route('/me', name: 'update_me', methods: ['PUT'])]
    #[IsGranted('ROLE_USER')]
    #[OA\Put(
        path: '/api/users/me',
        summary: 'Modifier son profil (hors adresse)',
        security: [['cookieAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: new Model(type: UpdateUserDTO::class))
        )
    )]
    #[OA\Response(response: 200, description: 'Profil mis à jour')]
    public function updateMe(
        #[MapRequestPayload] UpdateUserDTO $dto
    ): JsonResponse {
        /** @var User $user */
        $user = $this->getUser();

        if ($dto->nom !== null) {
            $user->setNom($dto->nom);
        }
        if ($dto->prenom !== null) {
            $user->setPrenom($dto->prenom);
        }
        if ($dto->telephone !== null) {
            $user->setTelephone($dto->telephone);
        }
        if ($dto->bio !== null) {
            $user->setBio($dto->bio);
        }
        if ($dto->photo !== null) {
            $user->setPhoto($dto->photo);
        }

        $this->entityManager->flush();

        return $this->json($user, Response::HTTP_OK, [], ['groups' => ['user:read']]);
    }

    // ==================== RECHERCHER UTILISATEURS ====================

    #[Route('/search', name: 'search', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    #[OA\Get(
        path: '/api/users/search',
        summary: 'Rechercher des utilisateurs',
        security: [['cookieAuth' => []]]
    )]
    #[OA\Parameter(
        name: 'q',
        in: 'query',
        required: true,
        schema: new OA\Schema(type: 'string')
    )]
    #[OA\Response(response: 200, description: 'Résultats de recherche')]
    public function search(Request $request): JsonResponse
    {
        $query = $request->query->get('q', '');

        if (strlen($query) < 2) {
            return $this->json(['message' => 'La recherche doit contenir au moins 2 caractères'], Response::HTTP_BAD_REQUEST);
        }

        $users = $this->userRepository->search($query);

        return $this->json($users, Response::HTTP_OK, [], ['groups' => ['user:read']]);
    }

    // ==================== DASHBOARD ====================

    #[Route('/me/dashboard', name: 'dashboard', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    #[OA\Get(
        path: '/api/users/me/dashboard',
        summary: 'Tableau de bord de l\'utilisateur connecté',
        security: [['cookieAuth' => []]]
    )]
    #[OA\Response(response: 200, description: 'Données du dashboard')]
    public function dashboard(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $dashboard = $this->userStatsService->getUserDashboard($user);

        return $this->json($dashboard, Response::HTTP_OK, [], ['groups' => ['dashboard:read']]);
    }
}

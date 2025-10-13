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
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
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
        private readonly AddressService $addressService,
        private readonly LoggerInterface $logger,
        private readonly bool $smsVerificationEnabled // ⬅️ INJECTION DU FLAG
    ) {}

    // ==================== COMPLÉTER PROFIL (MODIFIÉ) ====================

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
    #[OA\Response(response: 200, description: 'Profil complété')]
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

        // ==================== LOGIQUE DE SKIP SMS ====================

        if ($this->smsVerificationEnabled) {
            // MODE PRODUCTION : Envoyer le SMS
            try {
                $this->verificationService->sendPhoneVerification($user);
                $this->entityManager->flush();

                $this->logger->info('SMS envoyé avec succès', [
                    'user_id' => $user->getId(),
                    'phone' => $user->getTelephone()
                ]);

                return $this->json([
                    'success' => true,
                    'message' => 'Profil complété. Un code de vérification a été envoyé par SMS.',
                    'smsVerificationRequired' => true, // ⬅️ IMPORTANT pour le frontend
                    'user' => [
                        'id' => $user->getId(),
                        'telephone' => $user->getTelephone(),
                        'telephoneVerifie' => $user->isTelephoneVerifie(),
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

            } catch (\Exception $e) {
                $this->logger->error('Erreur envoi SMS', [
                    'user_id' => $user->getId(),
                    'error' => $e->getMessage()
                ]);

                return $this->json([
                    'success' => false,
                    'message' => 'Profil mis à jour mais erreur lors de l\'envoi du SMS : ' . $e->getMessage()
                ], Response::HTTP_INTERNAL_SERVER_ERROR);
            }

        } else {
            // MODE DEV/STAGING : Auto-vérifier le téléphone (SKIP SMS)
            $user->setTelephoneVerifie(true); // ⬅️ AUTO-VÉRIFICATION
            $this->entityManager->flush();

            $this->logger->info('SMS verification SKIPPED (dev mode)', [
                'user_id' => $user->getId(),
                'phone' => $user->getTelephone(),
                'auto_verified' => true
            ]);

            return $this->json([
                'success' => true,
                'message' => 'Profil complété avec succès.',
                'smsVerificationRequired' => false, // ⬅️ IMPORTANT pour le frontend
                'user' => [
                    'id' => $user->getId(),
                    'telephone' => $user->getTelephone(),
                    'telephoneVerifie' => $user->isTelephoneVerifie(), // ⬅️ true
                    'address' => [
                        'pays' => $user->getAddress()->getPays(),
                        'ville' => $user->getAddress()->getVille(),
                        'quartier' => $user->getAddress()->getQuartier(),
                        'adresseLigne1' => $user->getAddress()->getAdresseLigne1(),
                        'codePostal' => $user->getAddress()->getCodePostal(),
                    ],
                    'isProfileComplete' => $user->isProfileComplete(), // ⬅️ true
                ]
            ]);
        }
    }

    // ==================== AUTRES MÉTHODES (INCHANGÉES) ====================

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

    #[Route('/me/address/modification-info', name: 'address_modification_info', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function addressModificationInfo(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $info = $this->addressService->getModificationInfo($user);
        return $this->json($info);
    }

    #[Route('/me/address', name: 'update_address', methods: ['PUT'])]
    #[IsGranted('ROLE_USER')]
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

    #[Route('', name: 'list', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function list(Request $request): JsonResponse
    {
        $page = $request->query->getInt('page', 1);
        $limit = $request->query->getInt('limit', 10);
        $result = $this->userRepository->findPaginated($page, $limit);
        return $this->json($result, Response::HTTP_OK, [], ['groups' => ['user:read']]);
    }

    #[Route('/{id}', name: 'show', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
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

    #[Route('/me', name: 'update_me', methods: ['PUT'])]
    #[IsGranted('ROLE_USER')]
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

    #[Route('/search', name: 'search', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function search(Request $request): JsonResponse
    {
        $query = $request->query->get('q', '');

        if (strlen($query) < 2) {
            return $this->json(['message' => 'La recherche doit contenir au moins 2 caractères'], Response::HTTP_BAD_REQUEST);
        }

        $users = $this->userRepository->search($query);

        return $this->json($users, Response::HTTP_OK, [], ['groups' => ['user:read']]);
    }

    #[Route('/me/dashboard', name: 'dashboard', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function dashboard(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $dashboard = $this->userStatsService->getUserDashboard($user);

        return $this->json($dashboard, Response::HTTP_OK, [], ['groups' => ['dashboard:read']]);
    }
}

<?php

declare(strict_types=1);

namespace App\Controller;

use App\DTO\UpdateSettingsDTO;
use App\Entity\User;
use App\Service\SettingsService;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/settings', name: 'api_settings_')]
#[OA\Tag(name: 'Paramètres')]
class SettingsController extends AbstractController
{
    public function __construct(
        private readonly SettingsService $settingsService
    ) {}

    #[Route('', name: 'get', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    #[OA\Get(
        path: '/api/settings',
        summary: 'Récupérer les paramètres de l\'utilisateur connecté',
        security: [['cookieAuth' => []]]
    )]
    #[OA\Response(
        response: 200,
        description: 'Paramètres récupérés',
        content: new OA\JsonContent(ref: new Model(type: \App\Entity\UserSettings::class, groups: ['settings:read']))
    )]
    public function getSettings(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $settings = $this->settingsService->getUserSettings($user);

        return $this->json($settings, Response::HTTP_OK, [], ['groups' => ['settings:read']]);
    }

    #[Route('', name: 'update', methods: ['PATCH'])]
    #[IsGranted('ROLE_USER')]
    #[OA\Patch(
        path: '/api/settings',
        summary: 'Mettre à jour les paramètres',
        security: [['cookieAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: new Model(type: UpdateSettingsDTO::class))
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Paramètres mis à jour',
        content: new OA\JsonContent(ref: new Model(type: \App\Entity\UserSettings::class, groups: ['settings:read']))
    )]
    public function updateSettings(
        #[MapRequestPayload] UpdateSettingsDTO $dto
    ): JsonResponse {
        /** @var User $user */
        $user = $this->getUser();
        $settings = $this->settingsService->updateSettings($user, $dto);

        return $this->json($settings, Response::HTTP_OK, [], ['groups' => ['settings:read']]);
    }

    #[Route('/reset', name: 'reset', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    #[OA\Post(
        path: '/api/settings/reset',
        summary: 'Réinitialiser les paramètres par défaut',
        security: [['cookieAuth' => []]]
    )]
    #[OA\Response(
        response: 200,
        description: 'Paramètres réinitialisés',
        content: new OA\JsonContent(ref: new Model(type: \App\Entity\UserSettings::class, groups: ['settings:read']))
    )]
    public function resetSettings(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $settings = $this->settingsService->resetToDefaults($user);

        return $this->json($settings, Response::HTTP_OK, [], ['groups' => ['settings:read']]);
    }

    #[Route('/export', name: 'export', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    #[OA\Get(
        path: '/api/settings/export',
        summary: 'Exporter toutes les données utilisateur (RGPD)',
        security: [['cookieAuth' => []]]
    )]
    #[OA\Response(
        response: 200,
        description: 'Données exportées',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'user', type: 'object'),
                new OA\Property(property: 'settings', type: 'object'),
                new OA\Property(property: 'voyages', type: 'integer'),
                new OA\Property(property: 'demandes', type: 'integer'),
                new OA\Property(property: 'messages', type: 'integer'),
                new OA\Property(property: 'avis', type: 'integer'),
            ],
            type: 'object'
        )
    )]
    public function exportData(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $data = $this->settingsService->exportUserData($user);

        return $this->json($data, Response::HTTP_OK);
    }
}

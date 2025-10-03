<?php

declare(strict_types=1);

namespace App\Controller;

use App\DTO\UpdateUserDTO;
use App\Repository\UserRepository;
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
        private readonly EntityManagerInterface $entityManager
    ) {}

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

        return $this->json($user, Response::HTTP_OK, [], ['groups' => ['user:read']]);
    }

    #[Route('/me', name: 'update_me', methods: ['PUT'])]
    #[IsGranted('ROLE_USER')]
    #[OA\Put(
        path: '/api/users/me',
        summary: 'Modifier son profil',
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
}

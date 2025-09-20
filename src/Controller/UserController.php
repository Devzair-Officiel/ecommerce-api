<?php

namespace App\Controller;

use App\Service\UserService;
use App\Utils\ApiResponseUtils;
use App\DTO\User\UserUpdatedDTO;
use App\Utils\SerializationUtils;
use App\Utils\DeserializationUtils;
use App\DTO\User\UserRegistrationDTO;
use App\Controller\AbstractApiController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[Route(name: 'api_')]
class UserController extends AbstractApiController
{
    public function __construct(
        private UserService $userService,
        ApiResponseUtils $apiResponseUtils,
        SerializationUtils $serializationUtils,
        DeserializationUtils $deserializationUtils
    ) {
        parent::__construct($apiResponseUtils, $serializationUtils, $deserializationUtils);
    }

    /**
     * Récupérer tous les utilisateurs
     */
    #[Route('/users', name: 'get_all_users', methods: ['GET'])]
    public function getAllUsers(Request $request, UrlGeneratorInterface $urlGenerator): JsonResponse
    {
        return $this->handlePaginatedRequest(
            $request,
            fn(int $page, int $limit, array $filters) => $this->userService->getAllUsers($page, $limit, $filters),
            'api_get_all_users',
            $urlGenerator
        );
    }

    /**
     * Récupérer un utilisateur
     */
    #[Route('/users/{id}', name: 'get_user', methods: ['GET'])]
    public function getUsers(int $id, SerializationUtils $serializationUtils): JsonResponse
    {
        $user = $this->userService->getUserById($id);

        $serializedUsers = $serializationUtils->serialize($user, ['user_detail', 'user_list', 'date']);

        // Réponse JSON avec traduction
        return $this->apiResponseUtils->success(
            data: $serializedUsers,
            messageKey: 'entity.retrieved',
            entityKey: 'user'
        );
    }

    /**
     * Création d'un utilisateur
     */
    #[Route('/users', name: 'register_user', methods: ['POST'])]
    public function createUser(Request $request): JsonResponse
    {
        // Désérialisation et validation
        $userDTO = $this->deserializationUtils->deserializeAndValidate(
            $request->getContent(),
            UserRegistrationDTO::class
        );

        $jsonData = json_decode($request->getContent(), true);

        // Création de l'utilisateur
        $user = $this->userService->registerUser($userDTO, $jsonData);

        // Sérialisation et réponse
        $serializedUser = $this->serializationUtils->serialize($user, ['user_detail', 'user_list']);

        return $this->apiResponseUtils->success(
            data: $serializedUser,
            messageKey: 'entity.created',
            entityKey: 'user',
            status: Response::HTTP_CREATED
        );
    }

    /**
     * Mise à jour d'un utilisateur
     */
    #[Route('/users/{id}', name: 'updated_user', methods: ['PUT'])]
    public function updatedUser(int $id, Request $request): JsonResponse
    {
        // Désérialisation et validation
        $userDTO = $this->deserializationUtils->deserializeAndValidate(
            $request->getContent(),
            UserUpdatedDTO::class
        );

        $jsonData = json_decode($request->getContent(), true);

        $updatedUser = $this->userService->updatedUser($id, $userDTO, $jsonData);

        // Sérialisation directe de l'entité User avec les groupes
        $serializedUser = $this->serializationUtils->serialize($updatedUser, ['user_detail', 'user_list']);

        return $this->apiResponseUtils->success(
            data: $serializedUser,
            messageKey: 'entity.updated',
            entityKey: 'user',
            status: Response::HTTP_OK
        );
    }

    /**
     * Désactiver un utilisateur
     */
    #[Route('/users-disable/{id}', name: 'disable_user', methods: ['PUT'])]
    public function disableUser(int $id): JsonResponse
    {
        $this->userService->deactivateUser($id);

        return $this->apiResponseUtils->success(
            data: ['isValid' => false, 'userId' => $id],
            messageKey: 'user.desactivated',
            entityKey: 'user',
            status: Response::HTTP_OK
        );
    }

    /**
     * Désactiver un utilisateur
     */
    #[Route('/users-enable/{id}', name: 'enable_user', methods: ['PUT'])]
    public function enableUser(int $id): JsonResponse
    {
        $this->userService->activateUser($id);

        return $this->apiResponseUtils->success(
            data: ['isValid' => true, 'userId' => $id],
            messageKey: 'user.activated',
            entityKey: 'user',
            status: Response::HTTP_OK
        );
    }

    /**
     * Extraire les filtres pour les utilisateurs.
     */
    protected function extractFilters(array $queryParams): array
    {
        return [
            'email' => $queryParams['email'] ?? null,
            'firstname' => $queryParams['firstname'] ?? null,
            'lastname' => $queryParams['lastname'] ?? null,
            'isValid' => $queryParams['isValid'] ?? null,
        ];
    }

    protected function getAllowedFilterKeys(): array
    {
        return ['title', 'sortBy', 'sortOrder', 'valid'];
    }


    protected function getSerializationGroup(): string
    {
        return 'user_list';
    }

    protected function getEntityName(): string
    {
        return 'user';
    }
}

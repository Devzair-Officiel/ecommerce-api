<?php

declare(strict_types=1);

namespace App\Controller;

use App\Utils\ApiResponseUtils;
use App\Service\UserService;
use App\Exception\ValidationException;
use Symfony\Component\HttpFoundation\Request;
use App\Controller\Core\AbstractApiController;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;


/**
 * Contrôleur pour la gestion des utilisateurs via API REST.
 * 
 * Gère toutes les opérations CRUD et actions spécialisées pour les utilisateurs :
 * - Création, modification, suppression
 * - Recherche et filtrage
 * - Gestion des rôles et statuts
 * - Changement de mot de passe
 */
#[Route('/users', name: 'api_users_')]
class UserController extends AbstractApiController
{
    public function __construct(
        ApiResponseUtils $apiResponseUtils,
        SerializerInterface $serializer,
        private readonly UserService $userService
    ) {
        parent::__construct($apiResponseUtils, $serializer);
    }

    /**
     * Liste paginée des utilisateurs.
     */
    #[Route('', name: 'list', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function list(Request $request): JsonResponse
    {
        $pagination = $this->getPaginationParams($request);
        $filters = $this->extractFilters($request);

        $result = $this->userService->search(
            $pagination['page'],
            $pagination['limit'],
            $filters
        );

        return $this->listResponse(
            $result,
            ['user_detail', 'date', 'isValid'],
            'user',
            'api_users_list'
        );
    }

    /**
     * Détail d'un utilisateur.
     */
    #[Route('/{id}', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_ADMIN')]
    public function show(int $id): JsonResponse
    {
        $user = $this->userService->findEntityById($id);

        return $this->showResponse(
            $user,
            ['user_detail', 'date', 'user_supervisor', 'user_teams'],
            'user'
        );
    }

    /**
     * Création d'un utilisateur avec tokens JWT.
     */
    #[Route('', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function create(Request $request): JsonResponse
    {
        $data = $this->getJsonData($request);

        $result = $this->userService->createUserWithTokens($data);

        return $this->createResponse(
            $result['user'],
            ['user_detail', 'date'],
            'user',
            [
                'token' => $result['token'],
                'refresh_token' => $result['refresh_token']
            ]
        );
    }

    /**
     * Mise à jour du profil utilisateur.
     * Ne gère PAS : password, rôles, statut (endpoints dédiés).
     */
    #[Route('/{id}', methods: ['PUT', 'PATCH'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_ADMIN')]
    public function update(int $id, Request $request): JsonResponse
    {
        $data = $this->getJsonData($request);

        // Sécurité : empêcher changement de champs sensibles
        unset($data['password'], $data['plainPassword'], $data['roles'], $data['isValid']);

        $user = $this->userService->update($id, $data);

        return $this->updateResponse(
            $user,
            ['user_detail', 'date'],
            'user'
        );
    }

    /**
     * Suppression d'un utilisateur.
     */
    #[Route('/{id}', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(int $id): JsonResponse
    {
        $user = $this->userService->delete($id);

        return $this->deleteResponse(
            ['id' => $user->getId(), 'email' => $user->getEmail()],
            'user'
        );
    }

    /**
     * Changement de mot de passe (endpoint dédié).
     */
    #[Route('/{id}/password', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function changePassword(int $id, Request $request): JsonResponse
    {
        $data = $this->getJsonData($request);

        // Validation avec helper
        try {
            $this->requireField($data, 'newPassword', 'New password is required');
        } catch (\InvalidArgumentException $e) {
            return $this->missingFieldError('newPassword');
        }

        try {
            $user = $this->userService->changePassword($id, $data['newPassword']);

            return $this->apiResponseUtils->success(
                data: ['id' => $user->getId()],
                messageKey: 'user.password_changed',
                entityKey: 'user'
            );
        } catch (ValidationException $e) {
            return $this->validationError($e->getFormattedErrors());
        }
    }

    /**
     * Activation/désactivation (endpoint dédié).
     */
    #[Route('/{id}/status', methods: ['PATCH'], name: "toggle_status", requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_ADMIN')]
    public function toggleStatus(int $id, Request $request): JsonResponse
    {
        $data = $this->getJsonData($request);

        // Utilisation du helper pour boolean
        $isValid = $this->getBooleanValue($data, 'isValid');

        $user = $this->userService->toggleStatus($id, $isValid);

        return $this->statusReponse(
            data: ['id' => $id, 'email' => $user->getEmail(), 'isValid' => $user->isValid()],
            entityKey: 'user',
            isValid: $isValid
        );
    }

    /**
     * Ajout de rôle (endpoint dédié).
     */
    #[Route('/{id}/roles', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_ADMIN')]
    public function addRole(int $id, Request $request): JsonResponse
    {
        $data = $this->getJsonData($request);

        try {
            $this->requireField($data, 'role', null);
        } catch (\InvalidArgumentException $e) {
            return $this->missingFieldError('role');
        }

        $user = $this->userService->addRole($id, $data['role']);

        return $this->apiResponseUtils->success(
            data: ['id' => $user->getId(), 'roles' => $user->getRoles()],
            messageKey: 'user.role_added',
            entityKey: 'user'
        );
    }

    /**
     * Retrait de rôle (endpoint dédié).
     */
    #[Route('/{id}/roles/{role}', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_ADMIN')]
    public function removeRole(int $id, string $role): JsonResponse
    {
        $user = $this->userService->removeRole($id, $role);

        return $this->apiResponseUtils->success(
            data: ['id' => $user->getId(), 'roles' => $user->getRoles()],
            messageKey: 'user.role_removed',
            entityKey: 'user'
        );
    }
}

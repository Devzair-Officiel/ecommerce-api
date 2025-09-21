<?php

namespace App\Controller;

use App\Service\UserService;
use App\Utils\ApiResponseUtils;
use App\Utils\SerializationUtils;
use App\Exception\ValidationException;
use App\Service\Search\UserSearchService;
use App\Exception\EntityNotFoundException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

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
class UserController extends AbstractController
{
    public function __construct(
        private readonly UserService $userService,
        private readonly UserSearchService $searchService,
        private readonly SerializationUtils $serializer,
        private readonly ApiResponseUtils $apiResponse
    ) {}

    /**
     * Crée un nouvel utilisateur.
     * 
     * @param Request $request Données JSON : email, firstName, lastName, password, phone (optionnel)
     * @return JsonResponse Utilisateur créé avec ses détails
     */
    #[Route('', methods: ['POST'], name: 'create')]
    public function create(Request $request): JsonResponse
    {
        try {
            $user = $this->userService->createUser($request->getContent());

            $data = $this->serializer->serialize($user, ['user_detail', 'date']);

            return $this->apiResponse->created($data, 'user');
        } catch (ValidationException $e) {
            return $this->apiResponse->validationFailed($e->getErrors());
        } catch (\InvalidArgumentException $e) {
            return $this->apiResponse->error([['message' => $e->getMessage()]]);
        }
    }

    /**
     * Met à jour un utilisateur existant.
     * 
     * @param int $id ID de l'utilisateur à modifier
     * @param Request $request Données JSON partielles à modifier
     * @return JsonResponse Utilisateur mis à jour
     */
    #[Route('/{id}', methods: ['PUT'], requirements: ['id' => '\d+'], name: 'update')]
    public function update(int $id, Request $request): JsonResponse
    {
        try {
            $user = $this->userService->updateUser($id, $request->getContent());

            $data = $this->serializer->serialize($user, ['user_detail', 'date']);

            return $this->apiResponse->updated($data, 'user');
        } catch (EntityNotFoundException $e) {
            return $this->apiResponse->notFound('user', ['id' => $id]);
        } catch (ValidationException $e) {
            return $this->apiResponse->validationFailed($e->getErrors());
        } catch (\InvalidArgumentException $e) {
            return $this->apiResponse->error([['message' => $e->getMessage()]]);
        }
    }

    /**
     * Récupère un utilisateur par son ID.
     * 
     * @param int $id ID de l'utilisateur
     * @return JsonResponse Détails complets de l'utilisateur
     */
    #[Route('/{id}', methods: ['GET'], requirements: ['id' => '\d+'], name: 'show')]
    public function show(int $id): JsonResponse
    {
        try {
            $user = $this->userService->getUser($id);

            $data = $this->serializer->serialize($user, ['user_detail', 'date']);

            return $this->apiResponse->retrieved($data, 'user');
        } catch (EntityNotFoundException $e) {
            return $this->apiResponse->notFound('user', ['id' => $id]);
        }
    }

    /**
     * Liste les utilisateurs avec pagination et filtres.
     * 
     * Filtres disponibles : search, is_active, roles, email, sort_by, sort_order, page, limit
     * 
     * @param Request $request Paramètres de filtrage et pagination
     * @return JsonResponse Liste paginée des utilisateurs avec métadonnées
     */
    #[Route('', methods: ['GET'], name: 'list')]
    public function list(Request $request): JsonResponse
    {
        $filters = $request->query->all();
        $result = $this->searchService->searchUsers($filters);

        $result['items'] = $this->serializer->serialize($result['items'], ['user_list', 'date']);

        return $this->apiResponse->listRetrieved($result, 'user', 'api_users_list');
    }

    /**
     * Supprime un utilisateur.
     * 
     * Vérifie qu'aucune commande active n'est liée avant suppression.
     * 
     * @param int $id ID de l'utilisateur à supprimer
     * @return JsonResponse Utilisateur supprimé ou erreur si suppression impossible
     */
    #[Route('/{id}', methods: ['DELETE'], requirements: ['id' => '\d+'], name: 'delete')]
    public function delete(int $id): JsonResponse
    {
        try {
            $user = $this->userService->deleteUser($id);

            $data = $this->serializer->serialize($user, ['user_detail']);

            return $this->apiResponse->deleted($data, 'user');
        } catch (EntityNotFoundException $e) {
            return $this->apiResponse->notFound('user', ['id' => $id]);
        } catch (\DomainException $e) {
            return $this->apiResponse->error([['message' => $e->getMessage()]], status: 409);
        }
    }

    /**
     * Active ou désactive un utilisateur.
     * 
     * @param int $id ID de l'utilisateur
     * @param Request $request JSON : { "active": true|false }
     * @return JsonResponse Utilisateur avec nouveau statut
     */
    #[Route('/{id}/toggle-status', methods: ['PATCH'], requirements: ['id' => '\d+'], name: 'toggle_status')]
    public function toggleStatus(int $id, Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            $active = filter_var($data['active'] ?? true, FILTER_VALIDATE_BOOLEAN);

            $user = $this->userService->toggleUserStatus($id, $active);

            $responseData = $this->serializer->serialize($user, ['user_detail', 'admin_read']);

            return $this->apiResponse->updated($responseData, 'user');
        } catch (EntityNotFoundException $e) {
            return $this->apiResponse->notFound('user', ['id' => $id]);
        }
    }

    /**
     * Change le mot de passe d'un utilisateur.
     * 
     * @param int $id ID de l'utilisateur
     * @param Request $request JSON : { "password": "nouveau_mot_de_passe" }
     * @return JsonResponse Confirmation du changement
     */
    #[Route('/{id}/password', methods: ['PATCH'], requirements: ['id' => '\d+'], name: 'change_password')]
    public function changePassword(int $id, Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);

            if (empty($data['password'])) {
                return $this->apiResponse->error([['message' => 'Password is required']]);
            }

            $user = $this->userService->changePassword($id, $data['password']);

            $responseData = $this->serializer->serialize($user, ['user_detail']);

            return $this->apiResponse->success($responseData, 'success.password_changed');
        } catch (EntityNotFoundException $e) {
            return $this->apiResponse->notFound('user', ['id' => $id]);
        }
    }

    /**
     * Ajoute un rôle à un utilisateur.
     * 
     * @param int $id ID de l'utilisateur
     * @param Request $request JSON : { "role": "ROLE_ADMIN" }
     * @return JsonResponse Utilisateur avec nouveau rôle
     */
    #[Route('/{id}/roles', methods: ['POST'], requirements: ['id' => '\d+'], name: 'add_role')]
    public function addRole(int $id, Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);

            if (empty($data['role'])) {
                return $this->apiResponse->error([['message' => 'Role is required']]);
            }

            $user = $this->userService->addRole($id, $data['role']);

            $responseData = $this->serializer->serialize($user, ['user_detail', 'admin_read']);

            return $this->apiResponse->success($responseData, 'success.role_added');
        } catch (EntityNotFoundException $e) {
            return $this->apiResponse->notFound('user', ['id' => $id]);
        }
    }

    /**
     * Retire un rôle d'un utilisateur.
     * 
     * Note : ROLE_USER ne peut pas être retiré.
     * 
     * @param int $id ID de l'utilisateur
     * @param string $role Rôle à retirer (ex: ROLE_ADMIN)
     * @return JsonResponse Utilisateur sans le rôle retiré
     */
    #[Route('/{id}/roles/{role}', methods: ['DELETE'], requirements: ['id' => '\d+'], name: 'remove_role')]
    public function removeRole(int $id, string $role): JsonResponse
    {
        try {
            $user = $this->userService->removeRole($id, $role);

            $responseData = $this->serializer->serialize($user, ['user_detail', 'admin_read']);

            return $this->apiResponse->success($responseData, 'success.role_removed');
        } catch (EntityNotFoundException $e) {
            return $this->apiResponse->notFound('user', ['id' => $id]);
        } catch (\InvalidArgumentException $e) {
            return $this->apiResponse->error([['message' => $e->getMessage()]]);
        }
    }

    /**
     * Récupère tous les utilisateurs actifs.
     * 
     * @return JsonResponse Liste des utilisateurs avec isActive = true
     */
    #[Route('/active', methods: ['GET'], name: 'active')]
    public function activeUsers(): JsonResponse
    {
        $users = $this->searchService->getActiveUsers();

        $data = $this->serializer->serialize($users, ['user_list']);

        return $this->apiResponse->success($data);
    }

    /**
     * Recherche les utilisateurs par rôle avec pagination.
     * 
     * @param string $role Rôle recherché (ex: ROLE_ADMIN)
     * @param Request $request Paramètres de pagination et filtres additionnels
     * @return JsonResponse Liste paginée des utilisateurs ayant ce rôle
     */
    #[Route('/by-role/{role}', methods: ['GET'], name: 'by_role')]
    public function usersByRole(string $role, Request $request): JsonResponse
    {
        $filters = $request->query->all();
        $result = $this->searchService->searchUsersByRole($role, $filters);

        $result['items'] = $this->serializer->serialize($result['items'], ['user_list']);

        return $this->apiResponse->success($result);
    }
}

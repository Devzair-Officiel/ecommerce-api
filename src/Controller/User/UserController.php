<?php

declare(strict_types=1);

namespace App\Controller\User;

use App\Entity\Site\Site;
use App\Utils\ApiResponseUtils;
use App\Service\User\UserService;
use App\Exception\ValidationException;
use App\Repository\Site\SiteRepository;
use Symfony\Component\HttpFoundation\Request;
use App\Controller\Core\AbstractApiController;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Contrôleur pour la gestion des utilisateurs via API REST.
 * 
 * Endpoints :
 * - CRUD standard (admin uniquement)
 * - Endpoints spécialisés : password, status, roles
 * 
 * ⚠️ Note : L'inscription publique est dans AuthController
 */
#[Route('/users', name: 'api_users_')]
class UserController extends AbstractApiController
{
    public function __construct(
        ApiResponseUtils $apiResponseUtils,
        SerializerInterface $serializer,
        private readonly UserService $userService,
        private readonly SiteRepository $siteRepository
    ) {
        parent::__construct($apiResponseUtils, $serializer);
    }

    /**
     * Liste paginée des utilisateurs.
     * 
     * Filtres supportés :
     * - search : Recherche textuelle (email, username, nom)
     * - role : Filtrer par rôle
     * - is_verified : Comptes vérifiés uniquement
     * - active_only : Comptes actifs uniquement
     * - sortBy / sortOrder : Tri personnalisé
     */
    #[Route('', name: 'list', methods: ['GET'])]
    // #[IsGranted('ROLE_ADMIN')]
    public function list(Request $request): JsonResponse
    {
        $pagination = $this->getPaginationParams($request);
        $filters = $this->extractFilters($request);

        $site = $this->getSiteFromRequest($request);
        $filters['site'] = $site->getId();
        
        $result = $this->userService->search(
            $pagination['page'],
            $pagination['limit'],
            $filters
        );

        return $this->listResponse(
            $result,
            ['user:list', 'date', 'site'],
            'user',
            'api_users_list'
        );
    }

    /**
     * Détail d'un utilisateur.
     */
    #[Route('/{id}', name: 'show', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_ADMIN')]
    public function show(int $id): JsonResponse
    {
        $user = $this->userService->findEntityById($id);

        return $this->showResponse(
            $user,
            ['user:read', 'user:list', 'date', 'site', 'active_state', 'soft_delete'],
            'user'
        );
    }

    /**
     * Création d'un utilisateur (admin uniquement).
     * 
     * ⚠️ Ne retourne PAS de tokens JWT (contrairement à l'inscription publique).
     * Pour l'inscription publique, voir AuthController::register
     */
    #[Route('', name: 'create', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function create(Request $request): JsonResponse
    {
        $data = $this->getJsonData($request);

        try {
            // Validation des champs requis
            $this->requireFields($data, ['email', 'plainPassword'], 'Champs requis manquants');

            // Récupérer le site depuis le contexte (multi-tenant)
            // TODO: Implémenter la récupération du site depuis la requête
            // Pour l'instant, utiliser le premier site par défaut
            $site = $this->getSiteFromRequest($request);

            $user = $this->userService->createUser($data, $site);

            return $this->createResponse(
                $user,
                ['user:read', 'user:list', 'date'],
                'user'
            );
        } catch (ValidationException $e) {
            return $this->validationError($e->getFormattedErrors());
        }
    }

    /**
     * Mise à jour d'un utilisateur.
     * 
     * ⚠️ Ne gère PAS : password, roles, status (endpoints dédiés).
     * Champs mis à jour : email, firstName, lastName, phone, etc.
     */
    #[Route('/{id}', name: 'update', methods: ['PUT', 'PATCH'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_ADMIN')]
    public function update(int $id, Request $request): JsonResponse
    {
        $data = $this->getJsonData($request);

        try {
            $user = $this->userService->update($id, $data);

            return $this->updateResponse(
                $user,
                ['user:read', 'user:list', 'date'],
                'user'
            );
        } catch (ValidationException $e) {
            return $this->validationError($e->getFormattedErrors());
        }
    }

    /**
     * Suppression d'un utilisateur (soft delete).
     */
    #[Route('/{id}', name: 'delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(int $id): JsonResponse
    {
        $user = $this->userService->delete($id);

        return $this->deleteResponse(
            ['id' => $id, 'email' => $user->getEmail()],
            'user'
        );
    }

    // ===============================================
    // ENDPOINTS SPÉCIALISÉS
    // ===============================================

    /**
     * Changement de mot de passe (endpoint dédié).
     * 
     * Avantages :
     * - Validation spécifique (groupe user:password)
     * - Révocation des anciens tokens
     * - Peut être utilisé par l'utilisateur lui-même (PATCH /profile/password)
     */
    #[Route('/{id}/password', name: 'change_password', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_ADMIN')]
    public function changePassword(int $id, Request $request): JsonResponse
    {
        $data = $this->getJsonData($request);

        try {
            $this->requireField($data, 'newPassword', 'Le nouveau mot de passe est requis');

            $user = $this->userService->changePassword($id, $data['newPassword']);

            return $this->apiResponseUtils->success(
                data: ['id' => $user->getId(), 'email' => $user->getEmail()],
                messageKey: 'user.password_changed',
                entityKey: 'user'
            );
        } catch (\InvalidArgumentException $e) {
            return $this->missingFieldError('newPassword');
        } catch (ValidationException $e) {
            return $this->validationError($e->getFormattedErrors());
        }
    }

    /**
     * Activation/désactivation d'un utilisateur (endpoint dédié).
     * 
     * Utilise ActiveStateTrait (closedAt).
     * - active = true : closedAt = null (compte actif)
     * - active = false : closedAt = now (compte désactivé)
     */
    #[Route('/{id}/status', name: 'toggle_status', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_ADMIN')]
    public function toogleStatus(int $id, Request $request): JsonResponse
    {
        $data = $this->getJsonData($request);

        $active = $this->getBooleanValue($data, 'active', false);

        $user = $this->userService->toogleStatus($id, $active);

        return $this->apiResponseUtils->statusChanged(
            data: ['id' => $id, 'email' => $user->getEmail(), 'active' => $user->isActive()],
            entityKey: 'user',
            valid: $active
        );
    }

    /**
     * Bannissement d'un utilisateur.
     * Désactive le compte et révoque tous les tokens.
     */
    #[Route('/{id}/ban', name: 'ban', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_ADMIN')]
    public function ban(int $id): JsonResponse
    {
        $user = $this->userService->banUser($id);

        return $this->apiResponseUtils->success(
            data: ['id' => $id, 'email' => $user->getEmail(), 'banned' => true],
            messageKey: 'user.banned',
            entityKey: 'user'
        );
    }

    /**
     * Débannissement d'un utilisateur.
     */
    #[Route('/{id}/unban', name: 'unban', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_ADMIN')]
    public function unban(int $id): JsonResponse
    {
        $user = $this->userService->unbanUser($id);

        return $this->apiResponseUtils->success(
            data: ['id' => $id, 'email' => $user->getEmail(), 'banned' => false],
            messageKey: 'user.unbanned',
            entityKey: 'user'
        );
    }

    /**
     * Ajout d'un rôle (endpoint dédié).
     * 
     * Body : { "role": "ROLE_MODERATOR" }
     */
    #[Route('/{id}/roles', name: 'add_role', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_ADMIN')]
    public function addRole(int $id, Request $request): JsonResponse
    {
        $data = $this->getJsonData($request);

        try {
            $this->requireField($data, 'role', 'Le rôle est requis');

            $user = $this->userService->addRole($id, $data['role']);

            return $this->apiResponseUtils->success(
                data: ['id' => $id, 'roles' => $user->getRoles()],
                messageKey: 'user.role_added',
                entityKey: 'user'
            );
        } catch (\InvalidArgumentException $e) {
            return $this->apiResponseUtils->error(
                errors: [['field' => 'role', 'message' => $e->getMessage()]],
                messageKey: 'validation.invalid_role',
                status: 400
            );
        }
    }

    /**
     * Retrait d'un rôle (endpoint dédié).
     * 
     * URL : DELETE /users/{id}/roles/ROLE_MODERATOR
     */
    #[Route('/{id}/roles/{role}', name: 'remove_role', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_ADMIN')]
    public function removeRole(int $id, string $role): JsonResponse
    {
        try {
            $user = $this->userService->removeRole($id, $role);

            return $this->apiResponseUtils->success(
                data: ['id' => $id, 'roles' => $user->getRoles()],
                messageKey: 'user.role_removed',
                entityKey: 'user'
            );
        } catch (\InvalidArgumentException $e) {
            return $this->apiResponseUtils->error(
                errors: [['field' => 'role', 'message' => $e->getMessage()]],
                messageKey: 'validation.invalid_role',
                status: 400
            );
        }
    }

    // ===============================================
    // HELPERS
    // ===============================================

    /**
     * Récupère le site depuis la requête (multi-tenant).
     * 
     * TODO: Implémenter la détection automatique du site selon votre stratégie :
     * - Depuis le domaine (Header Host)
     * - Depuis un header X-Site-Code
     * - Depuis un paramètre dans le body
     */
    private function getSiteFromRequest(Request $request): Site
    {
        // Option 1 : Depuis le domaine (recommandé en production)
        // $domain = $request->getHost();
        // $site = $this->siteRepository->findByDomain($domain);

        // Option 2 : Depuis un header custom
        // $siteCode = $request->headers->get('X-Site-Code');
        // $site = $this->siteRepository->findByCode($siteCode);

        // Option 3 : Depuis le body (temporaire pour développement)
        $data = $this->getJsonData($request);
        if (isset($data['siteCode'])) {
            $site = $this->siteRepository->findByCode($data['siteCode']);
            if ($site) {
                return $site;
            }
        }

        // Fallback : Retourner le premier site (pour développement uniquement)
        $sites = $this->siteRepository->findAccessibleSites();
        if (empty($sites)) {
            throw new \RuntimeException('Aucun site disponible. Veuillez créer un site avant de vous inscrire.');
        }

        return $sites[0]; // Retourne le premier site actif
    }
}

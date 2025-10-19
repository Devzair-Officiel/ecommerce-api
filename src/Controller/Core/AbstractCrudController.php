<?php

declare(strict_types=1);

namespace App\Controller\Core;

use App\Service\Core\AbstractService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Controller CRUD générique pour éliminer la répétition de code.
 * 
 * Philosophie :
 * - Chaque controller enfant ne déclare QUE sa configuration
 * - Toutes les méthodes CRUD sont héritées automatiquement
 * - Possibilité de surcharger n'importe quelle méthode si besoin spécifique
 * - Groupes de sérialisation configurables par action
 * 
 * Avantages :
 * - DRY : Zéro répétition de code
 * - Maintenabilité : Modification centralisée des opérations CRUD
 * - Flexibilité : Override facile pour cas particuliers
 * - Cohérence : Comportement uniforme sur tous les endpoints
 * 
 * @example
 * ```php
 * #[Route('/divisions', name: 'api_divisions_')]
 * class DivisionController extends AbstractCrudController
 * {
 *     public function __construct(
 *         private DivisionService $divisionService,
 *         ApiResponseUtils $apiResponseUtils,
 *         SerializerInterface $serializer
 *     ) {
 *         parent::__construct($apiResponseUtils, $serializer);
 *     }
 * 
 *     protected function getService(): AbstractService { return $this->divisionService; }
 *     protected function getEntityKey(): string { return 'division'; }
 *     protected function getRouteNamePrefix(): string { return 'api_divisions_'; }
 *     protected function getListGroups(): array { return ['division_list', 'date']; }
 *     protected function getDetailGroups(): array { return ['division_detail', 'division_list', 'date']; }
 * }
 * ```
 */
abstract class AbstractCrudController extends AbstractApiController
{
    // ===============================================
    // CONFIGURATION À DÉFINIR DANS LES ENFANTS
    // ===============================================

    /**
     * Retourne le service CRUD utilisé par ce controller.
     * Le service doit hériter de AbstractService.
     */
    abstract protected function getService(): AbstractService;

    /**
     * Retourne la clé d'entité pour les messages de réponse.
     * Ex: 'user', 'division', 'laboratory'
     */
    abstract protected function getEntityKey(): string;

    /**
     * Retourne le préfixe du nom de route pour la pagination.
     * Ex: 'api_divisions_', 'api_users_'
     */
    abstract protected function getRouteNamePrefix(): string;

    /**
     * Groupes de sérialisation pour l'action list (GET collection).
     */
    abstract protected function getListGroups(): array;

    /**
     * Groupes de sérialisation pour l'action show (GET single).
     */
    abstract protected function getDetailGroups(): array;

    /**
     * Groupes de sérialisation pour l'action create (POST).
     * Par défaut, utilise les groupes de détail.
     */
    protected function getCreateGroups(): array
    {
        return $this->getDetailGroups();
    }

    /**
     * Groupes de sérialisation pour l'action update (PUT).
     * Par défaut, utilise les groupes de détail.
     */
    protected function getUpdateGroups(): array
    {
        return $this->getDetailGroups();
    }

    /**
     * Nom complet de la route pour list (pour la pagination).
     * Override si nécessaire.
     */
    protected function getListRouteName(): string
    {
        return $this->getRouteNamePrefix() . 'get_all';
    }

    /**
     * Champ utilisé pour identifier l'entité dans les réponses de delete/status.
     * Par défaut 'title', override si ton entité utilise un autre champ (ex: 'name', 'label').
     */
    protected function getEntityDisplayField(): string
    {
        return 'title';
    }

    // ===============================================
    // MÉTHODES CRUD GÉNÉRIQUES (HÉRITÉES PAR DÉFAUT)
    // ===============================================

    /**
     * GET /resource - Liste paginée avec filtres.
     * 
     * Query params:
     * - page: numéro de page (default: 1)
     * - limit: éléments par page (default: 20)
     * - tout autre paramètre sera considéré comme filtre métier
     */
    public function list(Request $request): JsonResponse
    {
        $pagination = $this->getPaginationParams($request);
        $filters = $this->extractFilters($request);

        $result = $this->getService()->search(
            $pagination['page'],
            $pagination['limit'],
            $filters
        );

        return $this->listResponse(
            $result,
            $this->getListGroups(),
            $this->getEntityKey(),
            $this->getListRouteName()
        );
    }

    /**
     * GET /resource/{id} - Récupérer une ressource par ID.
     */
    public function show(int $id): JsonResponse
    {
        $entity = $this->getService()->findEntityById($id);

        return $this->showResponse(
            $entity,
            $this->getDetailGroups(),
            $this->getEntityKey()
        );
    }

    /**
     * POST /resource - Créer une nouvelle ressource.
     * 
     * Body: JSON avec les données de l'entité
     */
    public function create(Request $request): JsonResponse
    {
        $data = $this->getJsonData($request);

        // Hook optionnel pour validation/transformation avant création
        $data = $this->beforeCreate($data, $request);

        $entity = $this->getService()->create($data);

        // Hook optionnel pour actions post-création
        $this->afterCreate($entity, $request);

        return $this->createResponse(
            $entity,
            $this->getCreateGroups(),
            $this->getEntityKey(),
            $this->getAdditionalCreateData($entity)
        );
    }

    /**
     * PUT /resource/{id} - Mettre à jour une ressource.
     * 
     * Body: JSON avec les données à modifier
     */
    public function update(int $id, Request $request): JsonResponse
    {
        $data = $this->getJsonData($request);

        // Hook optionnel pour validation/transformation avant mise à jour
        $data = $this->beforeUpdate($id, $data, $request);

        $entity = $this->getService()->update($id, $data);

        // Hook optionnel pour actions post-mise à jour
        $this->afterUpdate($entity, $request);

        return $this->updateResponse(
            $entity,
            $this->getUpdateGroups(),
            $this->getEntityKey()
        );
    }

    /**
     * DELETE /resource/{id} - Supprimer une ressource.
     * 
     * Effectue un soft delete si l'entité le supporte (setIsDeleted),
     * sinon un hard delete.
     */
    public function delete(int $id): JsonResponse
    {
        // Hook optionnel pour validation avant suppression
        $this->beforeDelete($id);

        $entity = $this->getService()->delete($id);

        // Hook optionnel pour actions post-suppression
        $this->afterDelete($entity);

        return $this->deleteResponse(
            $this->getDeleteResponseData($entity),
            $this->getEntityKey()
        );
    }

    /**
     * PUT/PATCH /resource/{id}/status - Changer le statut d'activation.
     * 
     * Body: { "isValid": true } ou { "isValid": false }
     */
    public function toggleStatus(int $id, Request $request): JsonResponse
    {
        $data = $this->getJsonData($request);
        $isValid = $this->getBooleanValue($data, 'isValid');

        // Hook optionnel pour validation avant changement de statut
        $this->beforeStatusChange($id, $isValid);

        $entity = $this->getService()->toggleStatus($id, $isValid);

        // Hook optionnel pour actions post-changement de statut
        $this->afterStatusChange($entity, $isValid);

        return $this->statusResponse(
            $this->getStatusResponseData($entity, $isValid),
            $this->getEntityKey(),
            $isValid
        );
    }

    // ===============================================
    // HOOKS OPTIONNELS (À SURCHARGER SI NÉCESSAIRE)
    // ===============================================

    /**
     * Hook appelé avant la création.
     * Permet de modifier les données ou effectuer des validations custom.
     * 
     * @param array $data Données envoyées par le client
     * @param Request $request Requête HTTP
     * @return array Données modifiées
     */
    protected function beforeCreate(array $data, Request $request): array
    {
        return $data;
    }

    /**
     * Hook appelé après la création réussie.
     * Utile pour déclencher des événements, notifications, etc.
     */
    protected function afterCreate(object $entity, Request $request): void
    {
        // Override si nécessaire
    }

    /**
     * Hook appelé avant la mise à jour.
     * Permet de modifier les données ou effectuer des validations custom.
     */
    protected function beforeUpdate(int $id, array $data, Request $request): array
    {
        return $data;
    }

    /**
     * Hook appelé après la mise à jour réussie.
     */
    protected function afterUpdate(object $entity, Request $request): void
    {
        // Override si nécessaire
    }

    /**
     * Hook appelé avant la suppression.
     * Permet d'effectuer des vérifications (ex: empêcher suppression si utilisé).
     */
    protected function beforeDelete(int $id): void
    {
        // Override si nécessaire
    }

    /**
     * Hook appelé après la suppression réussie.
     */
    protected function afterDelete(object $entity): void
    {
        // Override si nécessaire
    }

    /**
     * Hook appelé avant le changement de statut.
     */
    protected function beforeStatusChange(int $id, bool $isValid): void
    {
        // Override si nécessaire
    }

    /**
     * Hook appelé après le changement de statut.
     */
    protected function afterStatusChange(object $entity, bool $isValid): void
    {
        // Override si nécessaire
    }

    /**
     * Données additionnelles à inclure dans la réponse de création.
     * Utile pour ajouter des tokens JWT, URLs, etc.
     */
    protected function getAdditionalCreateData(object $entity): array
    {
        return [];
    }

    // ===============================================
    // MÉTHODES UTILITAIRES POUR FORMATER LES RÉPONSES
    // ===============================================

    /**
     * Construit les données de réponse pour delete.
     * Par défaut retourne ['id' => X, 'title' => Y].
     * Override pour personnaliser (ex: si pas de champ title).
     */
    protected function getDeleteResponseData(object $entity): array
    {
        $displayField = $this->getEntityDisplayField();
        $getter = 'get' . ucfirst($displayField);

        return [
            'id' => $entity->getId(),
            $displayField => method_exists($entity, $getter) ? $entity->$getter() : null
        ];
    }

    /**
     * Construit les données de réponse pour toggleStatus.
     * Par défaut retourne ['id' => X, 'title' => Y, 'isValid' => bool].
     */
    protected function getStatusResponseData(object $entity, bool $isValid): array
    {
        $displayField = $this->getEntityDisplayField();
        $getter = 'get' . ucfirst($displayField);

        return [
            'id' => $entity->getId(),
            $displayField => method_exists($entity, $getter) ? $entity->$getter() : null,
            'isValid' => $isValid
        ];
    }

    /**
     * Méthode helper pour la réponse de changement de statut.
     */
    protected function statusResponse(array $data, string $entityKey, bool $isValid): JsonResponse
    {
        return $this->apiResponseUtils->statusChanged($data, $entityKey, $isValid);
    }
}

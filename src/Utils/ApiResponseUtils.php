<?php

declare(strict_types=1);

namespace App\Utils;

use App\Utils\PaginationUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Utilitaire pour standardiser toutes les réponses API.
 * 
 * Responsabilités :
 * - Formatage uniforme des réponses JSON (succès/erreur)
 * - Gestion des messages traduits avec entités dynamiques
 * - Construction automatique des réponses paginées
 * - Méthodes spécialisées pour les opérations CRUD
 * 
 * Cette classe centralise toute la logique de construction des réponses
 * pour éviter la duplication entre les contrôleurs et services.
 */
final class ApiResponseUtils
{
    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly UrlGeneratorInterface $urlGenerator
    ) {}

    /**
     * Génère une réponse JSON standardisée.
     * 
     * Détecte automatiquement le type de données (simple, paginée avec critères, ou paginée complète)
     * et applique le formatage approprié.
     *
     * @param mixed $data Les données à inclure dans la réponse
     * @param bool $success Indique si l'opération a réussi
     * @param string $messageKey Clé de traduction du message
     * @param array $messageParams Paramètres pour la traduction
     * @param int $status Code de statut HTTP
     * @param array $errors Liste des erreurs en cas d'échec
     * @param string|null $routeName Nom de route pour génération des liens HATEOAS
     *
     * @return JsonResponse Réponse JSON formatée
     */
    public function create(
        mixed $data,
        bool $success,
        string $messageKey,
        array $messageParams = [],
        int $status = JsonResponse::HTTP_OK,
        array $errors = [],
        ?string $routeName = null
    ): JsonResponse {
        $message = $messageKey ? $this->translator->trans($messageKey, $messageParams) : null;

        $response = [
            'success' => $success,
            'message' => $message,
            'status' => $status,
            'errors' => $success ? null : $errors,
        ];

        // Détection automatique du type de réponse
        if ($this->isPaginatedWithCriteria($data)) {
            // Format : ['items' => [], 'total_items' => int, 'criteria' => object, 'filters' => []]
            $response += $this->buildPaginatedResponseFromCriteria($data, $routeName);
        } elseif ($this->isPaginatedComplete($data)) {
            // Format : ['items' => [], 'page' => int, 'total_items' => int, 'links' => []]
            $response += $this->extractPaginatedData($data);
        } elseif ($data !== null) {
            // Réponse simple
            $response['data'] = $data;
        }

        return new JsonResponse($response, $status);
    }

    /**
     * Réponse de succès générique.
     * 
     * @param mixed $data Données à retourner
     * @param string $messageKey Clé du message de succès
     * @param array $messageParams Paramètres du message
     * @param int $status Code HTTP (défaut: 200)
     * @param string|null $entityKey Clé de l'entité pour traduction
     * @param string|null $routeName Nom de route pour liens pagination
     */
    public function success(
        mixed $data = null,
        string $messageKey = 'success.default',
        array $messageParams = [],
        int $status = JsonResponse::HTTP_OK,
        ?string $entityKey = null,
        ?string $routeName = null
    ): JsonResponse {
        if ($entityKey) {
            $messageParams['%entity%'] = $this->translator->trans("entity_name.$entityKey");
        }

        return $this->create(
            data: $data,
            success: true,
            messageKey: $messageKey,
            messageParams: $messageParams,
            status: $status,
            routeName: $routeName
        );
    }

    /**
     * Réponse d'erreur générique.
     */
    public function error(
        array $errors = [],
        string $messageKey = 'error.default',
        array $messageParams = [],
        int $status = JsonResponse::HTTP_BAD_REQUEST,
        ?string $entityKey = null
    ): JsonResponse {
        if ($entityKey) {
            $messageParams['%entity%'] = $this->translator->trans("entity_name.$entityKey");
        }

        return $this->create(
            data: null,
            success: false,
            messageKey: $messageKey,
            messageParams: $messageParams,
            status: $status,
            errors: $errors
        );
    }

    // ===============================================
    // MÉTHODES CRUD SPÉCIALISÉES
    // ===============================================

    /**
     * Réponse pour une création d'entité (201 Created).
     */
    public function created(mixed $data, string $entityKey): JsonResponse
    {
        return $this->success(
            data: $data,
            messageKey: 'entity.created',
            status: Response::HTTP_CREATED,
            entityKey: $entityKey
        );
    }

    /**
     * Réponse pour une mise à jour d'entité (200 OK).
     */
    public function updated(mixed $data, string $entityKey): JsonResponse
    {
        return $this->success(
            data: $data,
            messageKey: 'entity.updated',
            entityKey: $entityKey
        );
    }

    /**
     * Réponse pour une suppression d'entité (200 OK).
     */
    public function deleted(mixed $data, string $entityKey): JsonResponse
    {
        return $this->success(
            data: $data,
            messageKey: 'entity.delete',
            entityKey: $entityKey
        );
    }

    /**
     * Réponse pour la récupération d'une entité (200 OK).
     */
    public function retrieved(mixed $data, string $entityKey): JsonResponse
    {
        return $this->success(
            data: $data,
            messageKey: 'entity.retrieved',
            entityKey: $entityKey
        );
    }

    /**
     * Réponse pour une liste paginée d'entités (200 OK).
     * 
     * @param mixed $data Structure de données de pagination
     * @param string $entityKey Clé de l'entité pour le message
     * @param string $routeName Nom de route pour les liens HATEOAS
     */
    public function listRetrieved(mixed $data, string $entityKey, string $routeName): JsonResponse
    {
        return $this->success(
            data: $data,
            messageKey: 'entity.list_retrieved',
            entityKey: $entityKey,
            routeName: $routeName
        );
    }

    /**
     * Réponse pour une entité non trouvée (404 Not Found).
     */
    public function notFound(string $entityKey, array $criteria = []): JsonResponse
    {
        return $this->error(
            errors: [['criteria' => $criteria]],
            messageKey: 'entity.not_found',
            status: Response::HTTP_NOT_FOUND,
            entityKey: $entityKey
        );
    }

    /**
     * Réponse pour une validation échouée (400 Bad Request).
     */
    public function validationFailed(array $errors): JsonResponse
    {
        return $this->error(
            errors: $errors,
            messageKey: 'validation.failed'
        );
    }

    /**
     * Réponse pour un accès refusé (403 Forbidden).
     */
    public function accessDenied(): JsonResponse
    {
        return $this->error(
            errors: [['message' => 'Access denied']],
            messageKey: 'error.access_denied',
            status: Response::HTTP_FORBIDDEN
        );
    }

    /**
     * Réponse pour un changement de statut d'entité.
     */
    public function statusChanged(mixed $data, string $entityKey, bool $activated): JsonResponse
    {
        $messageKey = $activated ? 'entity.activated' : 'entity.deactivated';

        return $this->success(
            data: $data,
            messageKey: $messageKey,
            entityKey: $entityKey
        );
    }

    // ===============================================
    // MÉTHODES PRIVÉES POUR GESTION PAGINATION
    // ===============================================

    /**
     * Vérifie si les données contiennent une pagination avec critères.
     * 
     * Format attendu : ['items' => [], 'total_items' => int, 'criteria' => object, 'filters' => []]
     */
    private function isPaginatedWithCriteria(mixed $data): bool
    {
        return is_array($data) &&
            isset($data['items'], $data['total_items'], $data['criteria']);
    }

    /**
     * Vérifie si les données contiennent une pagination complète.
     * 
     * Format attendu : ['items' => [], 'page' => int, 'total_items' => int]
     */
    private function isPaginatedComplete(mixed $data): bool
    {
        return is_array($data) &&
            isset($data['items'], $data['page'], $data['total_items']);
    }

    /**
     * Construit une réponse paginée à partir des critères de recherche.
     * 
     * Utilise les critères pour calculer la pagination et génère les liens HATEOAS.
     */
    private function buildPaginatedResponseFromCriteria(array $data, ?string $routeName): array
    {
        $criteria = $data['criteria'];
        $filters = $data['filters'] ?? [];

        $currentPage = (int) ceil($criteria->offset / $criteria->limit) + 1;
        $totalPages = (int) ceil($data['total_items'] / $criteria->limit);

        $response = [
            'page' => $currentPage,
            'limit' => $criteria->limit,
            'total_items' => $data['total_items'],
            'total_pages' => $totalPages,
            'total_items_found' => count($data['items']),
            'data' => $data['items'],
        ];

        // Génération des liens HATEOAS si route fournie
        if ($routeName) {
            $pagination = new PaginationUtils($currentPage, $criteria->limit, $data['total_items']);
            $response['links'] = $pagination->generateLinks($routeName, $this->urlGenerator, $filters);
        }

        return $response;
    }

    /**
     * Extrait les données d'une pagination déjà formatée.
     */
    private function extractPaginatedData(array $data): array
    {
        return [
            'page' => $data['page'],
            'limit' => $data['limit'],
            'total_items' => $data['total_items'],
            'total_pages' => $data['total_pages'],
            'total_items_found' => $data['total_items_found'] ?? count($data['items']),
            'data' => $data['items'],
            'links' => $data['links'] ?? null,
        ];
    }
}

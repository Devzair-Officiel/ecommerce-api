<?php

declare(strict_types=1);

namespace App\Controller\Core;

use App\Utils\ApiResponseUtils;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, JsonResponse};
use Symfony\Component\Serializer\SerializerInterface;

/**
 * Contrôleur abstrait pour standardiser les API REST.
 * 
 * Responsabilités :
 * - Gestion des données JSON (parsing, extraction)
 * - Sérialisation avec groupes
 * - Méthodes helper pour filtres et pagination
 * - Accès centralisé à ApiResponseUtils
 * 
 * Tous les controllers API doivent hériter de cette classe.
 */
abstract class AbstractApiController extends AbstractController
{
    public function __construct(
        protected readonly ApiResponseUtils $apiResponseUtils,
        protected readonly SerializerInterface $serializer
    ) {}

    // ===============================================
    // GESTION DES DONNÉES JSON
    // ===============================================

    /**
     * Extrait et décode le contenu JSON d'une requête.
     * 
     * @return array Données décodées ou tableau vide si erreur
     */
    protected function getJsonData(Request $request): array
    {
        $content = $request->getContent();

        if (empty($content)) {
            return [];
        }

        $data = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \InvalidArgumentException(
                'Invalid JSON: ' . json_last_error_msg()
            );
        }

        return $data ?? [];
    }

    /**
     * Extrait les filtres de recherche depuis les query parameters.
     * Enlève automatiquement 'page' et 'limit' pour ne garder que les filtres métier.
     * 
     * @return array Filtres nettoyés
     */
    protected function extractFilters(Request $request): array
    {
        $filters = $request->query->all();

        // Retirer les paramètres de pagination
        unset($filters['page'], $filters['limit']);

        // Filtrer les valeurs nulles ou vides
        return array_filter($filters, fn($value) => $value !== null && $value !== '');
    }

    /**
     * Extrait les paramètres de pagination depuis la requête.
     * 
     * @return array ['page' => int, 'limit' => int]
     */
    protected function getPaginationParams(Request $request, int $defaultLimit = 20, int $maxLimit = 100): array
    {
        $page = max(1, (int) $request->query->get('page', 1));
        $limit = min($maxLimit, max(1, (int) $request->query->get('limit', $defaultLimit)));

        return ['page' => $page, 'limit' => $limit];
    }

    // ===============================================
    // SÉRIALISATION
    // ===============================================

    /**
     * Sérialise une entité ou collection avec les groupes spécifiés.
     * 
     * @param mixed $data Données à sérialiser
     * @param array $groups Groupes de sérialisation
     * @param array $context Contexte additionnel
     * @return array Données sérialisées
     */
    protected function serialize(mixed $data, array $groups = [], array $context = []): array
    {
        if (empty($data)) {
            return [];
        }

        $serializationContext = array_merge([
            'enable_max_depth' => true,  // ✅ Important pour MaxDepth
        ], $context);

        if (!empty($groups)) {
            $serializationContext['groups'] = $groups;
        }

        $json = $this->serializer->serialize($data, 'json', $serializationContext);

        return json_decode($json, true);
    }

    /**
     * Nettoie les superviseurs pour ne garder que id, firstname, lastname.
     */
    protected function cleanSupervisors(array &$items): void
    {
        foreach ($items as &$item) {
            if (isset($item['supervisors']) && is_array($item['supervisors'])) {
                $item['supervisors'] = array_map(function ($supervisor) {
                    return [
                        'id' => $supervisor['id'] ?? null,
                        'firstname' => $supervisor['firstname'] ?? null,
                        'lastname' => $supervisor['lastname'] ?? null,
                    ];
                }, $item['supervisors']);
            }
        }
    }

    /**
     * Sérialise les items d'un résultat paginé.
     * Modifie directement le tableau $result en place.
     * 
     * @param array $result Résultat avec clé 'items'
     * @param array $groups Groupes de sérialisation
     * @return array Résultat avec items sérialisés
     */
    protected function serializePaginatedResult(array $result, array $groups = []): array
    {
        if (isset($result['items'])) {
            $result['items'] = $this->serialize($result['items'], $groups);

            // ✅ Nettoyer automatiquement les superviseurs si présents
            $this->cleanSupervisors($result['items']);
        }

        return $result;
    }

    // ===============================================
    // RÉPONSES STANDARDISÉES POUR CRUD
    // ===============================================

    /**
     * Réponse pour une liste paginée.
     */
    protected function listResponse(
        array $paginatedResult,
        array $serializationGroups,
        string $entityKey,
        string $routeName
    ): JsonResponse {
        $result = $this->serializePaginatedResult($paginatedResult, $serializationGroups);

        return $this->apiResponseUtils->listRetrieved($result, $entityKey, $routeName);
    }

    /**
     * Réponse pour la récupération d'une entité.
     */
    protected function showResponse(
        object $entity,
        array $serializationGroups,
        string $entityKey
    ): JsonResponse {
        $serialized = $this->serialize($entity, $serializationGroups);

        return $this->apiResponseUtils->retrieved($serialized, $entityKey);
    }

    /**
     * Réponse pour la création d'une entité.
     */
    protected function createResponse(
        object $entity,
        array $serializationGroups,
        string $entityKey,
        array $additionalData = []
    ): JsonResponse {
        $serialized = $this->serialize($entity, $serializationGroups);

        // Fusionner données additionnelles (ex: tokens JWT)
        if (!empty($additionalData)) {
            $serialized = array_merge($serialized, $additionalData);
        }

        return $this->apiResponseUtils->created($serialized, $entityKey);
    }

    /**
     * Réponse pour la mise à jour d'une entité.
     */
    protected function updateResponse(
        object $entity,
        array $serializationGroups,
        string $entityKey
    ): JsonResponse {
        $serialized = $this->serialize($entity, $serializationGroups);

        return $this->apiResponseUtils->updated($serialized, $entityKey);
    }

    /**
     * Réponse pour la suppression d'une entité.
     */
    protected function deleteResponse(
        array $data,
        string $entityKey
    ): JsonResponse {
        return $this->apiResponseUtils->deleted($data, $entityKey);
    }

    // ===============================================
    // VALIDATION DES DONNÉES D'ENTRÉE
    // ===============================================

    /**
     * Valide qu'un champ requis est présent dans les données.
     * 
     * @throws \InvalidArgumentException si le champ est manquant
     */
    protected function requireField(array $data, string $field, ?string $errorMessage): void
    {
        if (!isset($data[$field]) || (is_string($data[$field]) && trim($data[$field]) === '')) {
            $message = $errorMessage ?? "Field '{$field}' is required";
            throw new \InvalidArgumentException($message);
        }
    }

    /**
     * Valide plusieurs champs requis.
     * 
     * @param array $fields Liste des champs requis
     * @throws \InvalidArgumentException si un champ est manquant
     */
    protected function requireFields(array $data, array $fields, ?string $errorMessage): void
    {
        foreach ($fields as $field) {
            $this->requireField($data, $field, $errorMessage);
        }
    }

    /**
     * Extrait une valeur booléenne depuis les données.
     */
    protected function getBooleanValue(array $data, string $key, bool $default = false): bool
    {
        if (!isset($data[$key])) {
            return $default;
        }

        return filter_var($data[$key], FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Extrait une valeur entière depuis les données.
     */
    protected function getIntValue(array $data, string $key, ?int $default = null): ?int
    {
        if (!isset($data[$key])) {
            return $default;
        }

        return (int) $data[$key];
    }

    /**
     * Réponse pour le changement de statut d'une entité.
     */
    protected function statusReponse(mixed $data, string $entityKey, bool $isValid): JsonResponse
    {
        $serialized = $this->serialize($data);
        return $this->apiResponseUtils->statusChanged($serialized, $entityKey, $isValid);
    }

    // ===============================================
    // GESTION D'ERREURS SIMPLIFIÉE
    // ===============================================

    /**
     * Créer une réponse d'erreur pour un champ manquant.
     */
    protected function missingFieldError(string $field): JsonResponse
    {
        return $this->apiResponseUtils->error(
            errors: [['field' => $field, 'message' => "Field '{$field}' is required"]],
            messageKey: 'validation.required_field',
            status: 400
        );
    }

    /**
     * Créer une réponse d'erreur de validation.
     */
    protected function validationError(array $errors): JsonResponse
    {
        return $this->apiResponseUtils->validationFailed($errors);
    }

    /**
     * Créer une réponse pour une entité non trouvée.
     */
    protected function notFoundError(string $entityKey, array $criteria = []): JsonResponse
    {
        return $this->apiResponseUtils->notFound($entityKey, $criteria);
    }
}

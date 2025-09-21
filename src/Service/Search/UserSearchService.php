<?php


namespace App\Service\Search;

use App\ValueObject\UserSearchCriteria;
use App\Repository\Interface\UserRepositoryInterface;

/**
 * Service de recherche pour les utilisateurs.
 * 
 * Responsabilités :
 * - Construction des critères de recherche à partir des filtres
 * - Exécution des requêtes de recherche via le repository
 * - Formatage minimal des données pour ApiResponseUtils
 * 
 * Ce service ne gère PAS la construction des réponses HTTP complètes,
 * cette responsabilité étant déléguée à ApiResponseUtils.
 */
class UserSearchService
{
    public function __construct(
        private readonly UserRepositoryInterface $userRepository
    ) {}

    /**
     * Recherche des utilisateurs avec pagination et filtres.
     * 
     * Filtres supportés :
     * - search: Recherche textuelle dans nom, prénom, email
     * - is_active: Filtrage par statut actif/inactif
     * - roles: Filtrage par rôles (tableau)
     * - email: Recherche exacte par email
     * - sort_by: Champ de tri (défaut: createdAt)
     * - sort_order: Ordre de tri ASC/DESC (défaut: DESC)
     * - page: Numéro de page (défaut: 1)
     * - limit: Nombre d'éléments par page (défaut: 20, max: 100)
     * 
     * @param array $filters Filtres de recherche depuis les paramètres de requête
     * @return array Structure minimale pour ApiResponseUtils
     */
    public function searchUsers(array $filters): array
    {
        $criteria = $this->buildCriteria($filters);

        $users = $this->userRepository->findByCriteria($criteria);
        $totalItems = $this->userRepository->countByCriteria($criteria);

        // Structure minimale - ApiResponseUtils construira la réponse complète
        return [
            'items' => $users,
            'total_items' => $totalItems,
            'criteria' => $criteria,
            'filters' => $filters
        ];
    }

    /**
     * Récupère tous les utilisateurs actifs.
     * 
     * @return array Liste des utilisateurs avec isActive = true
     */
    public function getActiveUsers(): array
    {
        return $this->userRepository->findActiveUsers();
    }

    /**
     * Recherche des utilisateurs par rôle spécifique.
     * 
     * @param string $role Rôle à rechercher (ex: ROLE_ADMIN)
     * @param array $filters Filtres additionnels
     * @return array Résultats paginés des utilisateurs ayant ce rôle
     */
    public function searchUsersByRole(string $role, array $filters = []): array
    {
        $filters['roles'] = [$role];
        return $this->searchUsers($filters);
    }

    // ===============================================
    // MÉTHODES PRIVÉES
    // ===============================================

    /**
     * Construit les critères de recherche à partir des filtres HTTP.
     * 
     * Applique les validations et transformations nécessaires :
     * - Validation des limites de pagination
     * - Conversion des types (booléens, entiers)
     * - Application des valeurs par défaut
     * 
     * @param array $filters Filtres bruts depuis la requête HTTP
     * @return UserSearchCriteria Objet critères validé et typé
     */
    private function buildCriteria(array $filters): UserSearchCriteria
    {
        // Validation et normalisation de la pagination
        $page = max(1, (int) ($filters['page'] ?? 1));
        $limit = min(100, max(1, (int) ($filters['limit'] ?? 20)));

        return new UserSearchCriteria(
            search: $this->sanitizeString($filters['search'] ?? null),
            isActive: $this->parseBoolean($filters['is_active'] ?? null),
            roles: $this->parseRoles($filters['roles'] ?? null),
            email: $this->sanitizeString($filters['email'] ?? null),
            sortBy: $this->validateSortField($filters['sort_by'] ?? 'createdAt'),
            sortOrder: $this->validateSortOrder($filters['sort_order'] ?? 'DESC'),
            offset: ($page - 1) * $limit,
            limit: $limit
        );
    }

    /**
     * Nettoie et valide une chaîne de caractères.
     */
    private function sanitizeString(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    /**
     * Parse une valeur booléenne depuis les paramètres HTTP.
     */
    private function parseBoolean(mixed $value): ?bool
    {
        if ($value === null) {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    /**
     * Parse et valide les rôles depuis les paramètres.
     */
    private function parseRoles(mixed $roles): ?array
    {
        if ($roles === null) {
            return null;
        }

        // Conversion en tableau si nécessaire
        $rolesArray = is_array($roles) ? $roles : [$roles];

        // Filtrage des rôles valides
        $validRoles = array_filter($rolesArray, fn($role) => is_string($role) && str_starts_with($role, 'ROLE_'));

        return empty($validRoles) ? null : array_values($validRoles);
    }

    /**
     * Valide le champ de tri contre une liste blanche.
     */
    private function validateSortField(string $sortBy): string
    {
        $allowedFields = ['id', 'email', 'firstName', 'lastName', 'createdAt', 'isActive'];

        return in_array($sortBy, $allowedFields, true) ? $sortBy : 'createdAt';
    }

    /**
     * Valide l'ordre de tri.
     */
    private function validateSortOrder(string $sortOrder): string
    {
        $order = strtoupper($sortOrder);

        return in_array($order, ['ASC', 'DESC'], true) ? $order : 'DESC';
    }
}
<?php 

declare(strict_types=1);

namespace App\Utils;

/**
 * Constantes pour les filtres API standard.
 * Organisation par catégories et ajout de méthodes utilitaires.
 */
final class ApiFilterConstantsUtils
{
    // Filtres de pagination et tri
    public const PAGINATION_FILTERS = [
        'page',
        'limit',
        'sortBy',
        'sortOrder'
    ];

    // Filtres de dates
    public const DATE_FILTERS = [
        'createdAt',
        'updatedAt',
        'closedAt',
        'publishedAt'
    ];

    // Filtres de statut
    public const STATUS_FILTERS = [
        'isValid',
        'isActive',
        'isPublished',
        'status'
    ];

    // Filtres de localisation
    public const LOCALE_FILTERS = [
        'lang',
        'locale'
    ];

    // Tous les filtres génériques
    public const GENERIC_FILTERS = [
        ...self::PAGINATION_FILTERS,
        ...self::DATE_FILTERS,
        ...self::STATUS_FILTERS,
        ...self::LOCALE_FILTERS,
        'search'
    ];

    /**
     * Filtre les paramètres pour ne garder que ceux spécifiés.
     */
    public static function filterParams(array $params, array $allowedFilters): array
    {
        return array_intersect_key($params, array_flip($allowedFilters));
    }

    /**
     * Extrait les filtres métier en excluant les filtres génériques.
     */
    public static function extractBusinessFilters(array $params): array
    {
        return array_diff_key($params, array_flip(self::GENERIC_FILTERS));
    }

    /**
     * Extrait uniquement les filtres de pagination.
     */
    public static function extractPaginationParams(array $params): array
    {
        return self::filterParams($params, self::PAGINATION_FILTERS);
    }
}
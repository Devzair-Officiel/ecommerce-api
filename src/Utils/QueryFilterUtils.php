<?php

declare(strict_types=1);

namespace App\Utils;

use Doctrine\ORM\QueryBuilder;

/**
 * Classe utilitaire pour centraliser la gestion de :
 * - la pagination (offset, limit)
 * - le tri dynamique avec validation des champs autorisés
 * 
 * Utilisable dans tous les Repository pour simplifier la logique répétée.
 */
class QueryFilterUtils
{
    /**
     * Applique la pagination Doctrine à la requête.
     *
     * @param QueryBuilder $qb      Le QueryBuilder Doctrine.
     * @param int          $offset  L’offset de pagination (ex: 0 pour la première page).
     * @param int          $limit   Le nombre maximum de résultats à retourner.
     */
    public static function applyPagination(QueryBuilder $qb, int $offset, int $limit): void
    {
        $qb->setFirstResult($offset)
           ->setMaxResults($limit);
    }

    /**
     * Applique le tri dynamique à la requête à partir des filtres transmis.
     *
     * @param QueryBuilder $qb             Le QueryBuilder Doctrine.
     * @param array        $filters        Les paramètres reçus (GET ou filtrés dans le contrôleur).
     * @param array        $allowedFields  La liste des champs sur lesquels le tri est autorisé.
     * @param string       $alias          L’alias utilisé dans le DQL (ex: "e" ou "d").
     * @param string|null  $defaultSortBy  Champ à trier par défaut si non précisé (ex: 'id').
     * @param string       $defaultOrder   Ordre de tri par défaut (ASC ou DESC).
     */
    public static function applySorting(
        QueryBuilder $qb,
        array $filters,
        array $allowedFields,
        string $alias = 'e',
        ?string $defaultSortBy = 'id',
        string $defaultOrder = 'DESC'
    ): void {
        $sortField = $filters['sortBy'] ?? $defaultSortBy;
        $sortOrder = strtoupper($filters['sortOrder'] ?? $defaultOrder);

        // Vérifie si l’ordre est valide, sinon remet l’ordre par défaut
        if (!in_array($sortOrder, ['ASC', 'DESC'], true)) {
            $sortOrder = $defaultOrder;
        }

        // Si le champ est autorisé, on applique le tri
        if (in_array($sortField, $allowedFields, true)) {
            $qb->orderBy("$alias.$sortField", $sortOrder);
        }
    }
}

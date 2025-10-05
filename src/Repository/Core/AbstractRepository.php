<?php

declare(strict_types=1);

namespace App\Repository\Core;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Repository abstrait avec méthodes de filtrage réutilisables.
 * 
 * Responsabilités :
 * - Logique de pagination standardisée
 * - Méthodes de filtrage communes (tri, recherche, dates)
 * - Interface standard pour les recherches paginées
 * 
 * Chaque repository enfant définit ses propres champs autorisés et
 * peut surcharger/étendre les méthodes selon ses besoins spécifiques.
 */
abstract class AbstractRepository extends ServiceEntityRepository
{
    /** Champs autorisés pour le tri - à définir dans chaque repository enfant */
    protected array $sortableFields = ["id"];

    /** Champs dans lesquels effectuer la recherche textuelle - à définir dans chaque repository enfant */
    protected array $searchableFields = [];

    /** Alias par défaut pour les requêtes DQL */
    protected string $defaultalias = 'e';

    public function __construct(ManagerRegistry $registry, string $entityClass)
    {
        parent::__construct($registry, $entityClass);
    }

    /**
     * Méthode standard pour la recherche paginée avec filtres.
     * 
     * À implémenter dans chaque repository enfant avec la logique spécifique.
     */
    abstract public function findWithPagination(int $page, int $limit, array $filters = []): array;


    // ===============================================
    // MÉTHODES PROTÉGÉES RÉUTILISABLES
    // ===============================================

    /**
     * Crée un QueryBuilder de base avec l'alias par défaut.
     */
    protected function createBaseQueryBuilder(): QueryBuilder
    {
        return $this->createQueryBuilder($this->defaultalias);
    }

    /**
     * Applique la pagination à un QueryBuilder.
     */
    protected function applyPagination(QueryBuilder $qb, int $page, int $limit): void
    {
        $offset = ($page - 1) * $limit;
        $qb->setFirstResult($offset)->setMaxResults($limit);
    }

    /**
     * Applique le tri sécurisé avec whitelist des champs.
     */
    protected function applySorting(QueryBuilder $qb, array $filters): void
    {
        $sortField = $filters['sortBy'] ?? 'id';
        $sortOrder = strtoupper($filters['sortOrder'] ?? 'DESC');

        // Validation de l'ordre
        if(!in_array($sortOrder, ['ASC', 'DESC'], true)) {
            $sortOrder = 'DESC';
        }

        // Application du tri si le champ est autorisé
        if (in_array($sortField, $this->sortableFields, true)) {
            $qb->orderBy($this->defaultalias . '.' . $sortField, $sortOrder);
        } else {
            // Tri par defaut si champ non autorisé
            $qb->orderBy($this->defaultalias . '.id', 'DESC');
        }
    }

    /**
     * Applique la recherche textuelle sur les champs configurés.
     */
    protected function applyTextSearch(QueryBuilder $qb, array $filters): void
    {
        if(empty($filters['search']) || empty($this->searchableFields)) {
            return;
        }

        $serachTerm = '%' . $filters['search'] . '%';
        $conditions = [];

        foreach($this->searchableFields as $field) {
            $conditions[] = $this->defaultalias . '.' . $field . ' LIKE :searchTerm';
        }

        $qb->andWhere(implode(' OR ', $conditions))->setParameter('searchTerm', $serachTerm);
    }

    /**
     * Applique un filtre booléen simple.
     */
    protected function applyBooleanFilter(QueryBuilder $qb, array $filters, string $filterKey, string $fildName): void
    {
        if(!isset($filters[$filterKey])) {
            return;
        }

        $value = filter_var($filters[$filterKey], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        if ($value !== null) {
            $qb->andWhere($this->defaultalias . '.' . $fildName . ' = :' . $filterKey)->setParameter($filterKey, $value);
        }
    }

    /**
     * Applique un filtre de plage de dates.
     */
    protected function applyDateRangeFilter(QueryBuilder $qb, array $filters, string $fieldName): void
    {
        $fromKey = $fieldName . '_from';
        $toKey = $fieldName . '_to';

        if (isset($filters[$fromKey])) {
            $qb->andWhere($this->defaultAlias . '.' . $fieldName . ' >= :' . $fromKey)
                ->setParameter($fromKey, $filters[$fromKey]);
        }

        if (isset($filters[$toKey])) {
            $qb->andWhere($this->defaultAlias . '.' . $fieldName . ' <= :' . $toKey)
                ->setParameter($toKey, $filters[$toKey]);
        }
    }

    /**
     * Applique un filtre de valeurs multiples (IN).
     */
    protected function applyInFilter(QueryBuilder $qb, array $filters, string $filterKey, string $fieldName): void
    {
        if (empty($filters[$filterKey])) {
            return;
        }

        $values = is_array($filters[$filterKey]) ? $filters[$filterKey] : [$filters[$filterKey]];

        if (!empty($values)) {
            $qb->andWhere($this->defaultAlias . '.' . $fieldName . ' IN (:' . $filterKey . ')')
                ->setParameter($filterKey, $values);
        }
    }

    /**
     * Compte le nombre total d'éléments pour une requête donnée.
     */
    protected function countResults(QueryBuilder $qb): int
    {
        return (int) $qb->select('COUNT(' . $this->defaultalias . ' .id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Template method pour construire une réponse paginée standard.
     */
    protected function buildPaginatedResponse(QueryBuilder $qb, int $page, int $limit): array
    {
        // Clone le QB pour le count (avant pagination)
        $countQb = clone $qb;
        $total = $this->countResults($countQb);

        // Application de la pagination sur le QB principal
        $this->applyPagination($qb, $page, $limit);
        $items = $qb->getQuery()->getResult();

        return [
            'items' => $items,
            'total_items' => $total,
            'page' => $page,
            'limit' => $limit,
            'total_pages' => (int) ceil($total / $limit)
        ];
    }
}

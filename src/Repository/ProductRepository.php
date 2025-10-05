<?php

namespace App\Repository;

use App\Entity\Product;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use App\Repository\Core\AbstractRepository;

class ProductRepository extends AbstractRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Product::class);
    }


    /** Configuration des champs autorisés pour le tri */
    protected array $sortableFields = [
        'id',
        'createdAt',
        'isActive'
    ];

    /** Champs de recherche textuelle */
    protected array $searchableFields = [
        'title',
        'description',
    ];


    /**
     * Implémentation de la recherche paginée pour Product.
     */
    public function findWithPagination(int $page, int $limit, array $filters = []): array
    {
        $qb = $this->createBaseQueryBuilder();

        // Application des filtres génériques (hérités)
        $this->applyTextSearch($qb, $filters);
        $this->applySorting($qb, $filters);
        $this->applyBooleanFilter($qb, $filters, 'isActive', 'isActive');
        $this->applyDateRangeFilter($qb, $filters, 'createdAt');

        // Filtres spécifiques à Product (logique métier)
        $this->applyProductSpecificFilters($qb, $filters);

        return $this->buildPaginatedResponse($qb, $page, $limit);
    }

    /**
     * Entité actifs uniquement.
     */
    public function findValidProducts(): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.isValid = true')
            ->getQuery()
            ->getResult();
    }

    /**
     * Filtres spécifiques au domaine Product.
     */
    private function applyProductSpecificFilters(QueryBuilder $qb, array $filters): void
    {
        // Filtre par Titre
        if (!empty($filters['title'])) {
            $qb->andWhere('e.title LIKE :title')
                ->setParameter('title', '%' . $filters['title'] . '%');
        }

        // Filtre par Product (relation)
        if (isset($filters['laboratory_id'])) {
            $qb->join('e.laboratory', 'l')
                ->andWhere('d.id = :laboratoryId')
                ->setParameter('laboratoryId', $filters['laboratory_id']);
        }
    }
}

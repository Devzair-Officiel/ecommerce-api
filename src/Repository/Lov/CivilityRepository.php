<?php

namespace App\Repository\Lov;

use App\Entity\Lov\Civility;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\ORM\Tools\Pagination\Paginator as DoctrinePaginator;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;


/**
 * @extends ServiceEntityRepository<Civility>
 */
class CivilityRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Civility::class);
    }

    private function applyFilters(QueryBuilder $qb, array $filters): QueryBuilder
    {
        // Filtre par titre
        if (!empty($filters['title'])) {
            $qb->andWhere('c.title LIKE :title')
                ->setParameter('title', '%' . $filters['title'] . '%');
        }

        return $qb;
    }

    /**
     * Récupère les réponses avec pagination.
     *
     * @param int $offset L'offset pour la pagination.
     * @param int $limit  Le nombre d'éléments par page.
     * @return array
     */
    public function findWithPaginationAndFilters(int $offset, int $limit, array $filters): array
    {
        $qb = $this->createQueryBuilder('c');

        // Appliquer les filtres
        $this->applyFilters($qb, $filters);

        $qb->orderBy('c.id', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit);

        $query = $qb->getQuery();

        // Utilisation du Paginator pour gérer la pagination
        $paginator = new DoctrinePaginator($query, true);

        // Total des éléments trouvés avec les filtres
        $totalItemsFound = count($paginator);

        return [
            'items' => iterator_to_array($paginator),
            'totalItemsFound' => $totalItemsFound,
        ];
    }
}

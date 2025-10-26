<?php

declare(strict_types=1);

namespace App\Repository\Product;

use App\Entity\Product\Category;
use App\Entity\Site\Site;
use App\Repository\Core\AbstractRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Repository pour l'entité Category.
 * 
 * Responsabilités :
 * - Gestion hiérarchique (2 niveaux max)
 * - Arbre complet optimisé (JOIN FETCH pour éviter N+1)
 * - Navigation breadcrumb et chemins
 * - Statistiques produits par catégorie
 * 
 * Performance :
 * - findCompleteTree() : 1 seule requête pour tout l'arbre
 * - Eager loading des relations pour éviter N+1
 */
class CategoryRepository extends AbstractRepository
{
    protected array $sortableFields = ['id', 'name', 'slug', 'position', 'createdAt'];
    protected array $searchableFields = ['name', 'slug', 'description'];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Category::class);
    }

    /**
     * Recherche paginée avec filtres.
     * 
     * Filtres supportés :
     * - search : Recherche textuelle
     * - site_id : Filtrer par site
     * - locale : Filtrer par langue
     * - parent_id : Filtrer par parent (null pour racines)
     * - active_only : Uniquement actives (closedAt = null)
     * - with_products : Uniquement avec produits associés
     */
    public function findWithPagination(int $page, int $limit, array $filters = []): array
    {
        $qb = $this->createBaseQueryBuilder();

        $this->applyMultipleLikeFilters($qb, $filters, $this->searchableFields);
        $this->applyTextSearch($qb, $filters);
        $this->applySiteFilter($qb, $filters);
        $this->applyLocaleFilter($qb, $filters);
        $this->applyParentFilter($qb, $filters);
        $this->applyActiveOnlyFilter($qb, $filters);
        $this->applyWithProductsFilter($qb, $filters);
        $this->applySorting($qb, $filters);

        return $this->buildPaginatedResponse($qb, $page, $limit);
    }

    // ===============================================
    // FILTRES SPÉCIFIQUES
    // ===============================================

    private function applySiteFilter(QueryBuilder $qb, array $filters): void
    {
        if (empty($filters['site_id'])) {
            return;
        }

        $qb->andWhere($this->defaultalias . '.site = :siteId')
            ->setParameter('siteId', $filters['site_id']);
    }

    private function applyLocaleFilter(QueryBuilder $qb, array $filters): void
    {
        if (empty($filters['locale'])) {
            return;
        }

        $qb->andWhere($this->defaultalias . '.locale = :locale')
            ->setParameter('locale', $filters['locale']);
    }

    /**
     * Filtre par parent (supporte parent_id = null pour racines).
     */
    private function applyParentFilter(QueryBuilder $qb, array $filters): void
    {
        if (!array_key_exists('parent_id', $filters)) {
            return;
        }

        if ($filters['parent_id'] === null || $filters['parent_id'] === 'null') {
            $qb->andWhere($this->defaultalias . '.parent IS NULL');
        } else {
            $qb->andWhere($this->defaultalias . '.parent = :parentId')
                ->setParameter('parentId', $filters['parent_id']);
        }
    }

    private function applyActiveOnlyFilter(QueryBuilder $qb, array $filters): void
    {
        if (empty($filters['active_only']) || !filter_var($filters['active_only'], FILTER_VALIDATE_BOOLEAN)) {
            return;
        }

        $qb->andWhere($this->defaultalias . '.closedAt IS NULL')
            ->andWhere($this->defaultalias . '.isDeleted = false');
    }

    /**
     * Filtre pour ne garder que les catégories avec des produits.
     */
    private function applyWithProductsFilter(QueryBuilder $qb, array $filters): void
    {
        if (empty($filters['with_products']) || !filter_var($filters['with_products'], FILTER_VALIDATE_BOOLEAN)) {
            return;
        }

        $qb->innerJoin($this->defaultalias . '.products', 'p')
            ->andWhere('p.isDeleted = false')
            ->andWhere('p.closedAt IS NULL');
    }

    // ===============================================
    // MÉTHODES HIÉRARCHIQUES
    // ===============================================

    /**
     * Récupère les catégories racines (niveau 1, parent = null).
     * 
     * @param Site $site Site concerné
     * @param string $locale Langue
     * @param bool $activeOnly Uniquement actives ?
     * @return Category[]
     */
    public function findRootCategories(Site $site, string $locale, bool $activeOnly = true): array
    {
        $qb = $this->createQueryBuilder($this->defaultalias)
            ->where($this->defaultalias . '.site = :site')
            ->andWhere($this->defaultalias . '.locale = :locale')
            ->andWhere($this->defaultalias . '.parent IS NULL')
            ->andWhere($this->defaultalias . '.isDeleted = false')
            ->setParameter('site', $site)
            ->setParameter('locale', $locale)
            ->orderBy($this->defaultalias . '.position', 'ASC')
            ->addOrderBy($this->defaultalias . '.name', 'ASC');

        if ($activeOnly) {
            $qb->andWhere($this->defaultalias . '.closedAt IS NULL');
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Récupère l'arbre COMPLET des catégories (optimisé, 1 seule requête).
     * 
     * Performance :
     * - Utilise JOIN FETCH pour charger children en eager
     * - Évite le problème N+1
     * - Retourne un arbre structuré (racines avec enfants hydratés)
     * 
     * Utilisation :
     * ```php
     * $tree = $categoryRepo->findCompleteTree($site, 'fr');
     * foreach ($tree as $root) {
     *     echo $root->getName(); // Pas de requête SQL
     *     foreach ($root->getChildren() as $child) {
     *         echo $child->getName(); // Pas de requête SQL
     *     }
     * }
     * ```
     * 
     * @param Site $site Site concerné
     * @param string $locale Langue
     * @param bool $activeOnly Uniquement actives ?
     * @return Category[] Catégories racines avec children hydratés
     */
    public function findCompleteTree(Site $site, string $locale, bool $activeOnly = true): array
    {
        // 1. Récupérer toutes les catégories avec leurs enfants (JOIN FETCH)
        $qb = $this->createQueryBuilder('c')
            ->leftJoin('c.children', 'children')
            ->addSelect('children')
            ->where('c.site = :site')
            ->andWhere('c.locale = :locale')
            ->andWhere('c.isDeleted = false')
            ->setParameter('site', $site)
            ->setParameter('locale', $locale)
            ->orderBy('c.position', 'ASC')
            ->addOrderBy('children.position', 'ASC');

        if ($activeOnly) {
            $qb->andWhere('c.closedAt IS NULL');
        }

        $allCategories = $qb->getQuery()->getResult();

        // 2. Filtrer pour ne garder que les racines
        // Les enfants sont déjà hydratés via le JOIN FETCH
        return array_filter($allCategories, fn(Category $cat) => $cat->isRoot());
    }

    /**
     * Alternative simple pour petits catalogues.
     * Alias de findCompleteTree() pour compatibilité.
     */
    public function findCategoryTree(Site $site, string $locale, bool $activeOnly = true): array
    {
        return $this->findCompleteTree($site, $locale, $activeOnly);
    }

    /**
     * Récupère tous les enfants d'une catégorie (niveau 2 uniquement).
     * 
     * Cas d'usage : Afficher les sous-catégories d'une catégorie parent.
     * 
     * @param Category $parent Catégorie parent
     * @param bool $activeOnly Uniquement actives ?
     * @return Category[]
     */
    public function findChildren(Category $parent, bool $activeOnly = true): array
    {
        $qb = $this->createQueryBuilder($this->defaultalias)
            ->where($this->defaultalias . '.parent = :parent')
            ->andWhere($this->defaultalias . '.isDeleted = false')
            ->setParameter('parent', $parent)
            ->orderBy($this->defaultalias . '.position', 'ASC')
            ->addOrderBy($this->defaultalias . '.name', 'ASC');

        if ($activeOnly) {
            $qb->andWhere($this->defaultalias . '.closedAt IS NULL');
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Récupère tous les ancêtres d'une catégorie (remontée vers la racine).
     * 
     * Utilité : Breadcrumb, fil d'Ariane.
     * 
     * Note : L'entité Category a déjà getBreadcrumb() qui fait ça en mémoire,
     * mais cette méthode permet de le faire via requête SQL si besoin.
     * 
     * @param Category $category Catégorie de départ
     * @return Category[] Ancêtres ordonnés (racine → parent direct)
     */
    public function findAncestors(Category $category): array
    {
        // Avec 2 niveaux max, on peut le faire simplement
        $ancestors = [];
        $current = $category->getParent();

        while ($current !== null) {
            array_unshift($ancestors, $current);
            $current = $current->getParent();
        }

        return $ancestors;
    }

    // ===============================================
    // RECHERCHE PAR SLUG
    // ===============================================

    /**
     * Trouve une catégorie par slug (unique par site + locale).
     * 
     * @param string $slug Slug de la catégorie
     * @param Site $site Site concerné
     * @param string $locale Langue
     * @return Category|null
     */
    public function findBySlug(string $slug, Site $site, string $locale): ?Category
    {
        return $this->createQueryBuilder($this->defaultalias)
            ->where($this->defaultalias . '.slug = :slug')
            ->andWhere($this->defaultalias . '.site = :site')
            ->andWhere($this->defaultalias . '.locale = :locale')
            ->andWhere($this->defaultalias . '.isDeleted = false')
            ->setParameter('slug', $slug)
            ->setParameter('site', $site)
            ->setParameter('locale', $locale)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Trouve une catégorie par chemin complet (ex: "alimentaire/miels").
     * 
     * Cas d'usage : Résoudre l'URL /fr/categories/alimentaire/miels
     * 
     * @param string $path Chemin complet (segments séparés par /)
     * @param Site $site Site concerné
     * @param string $locale Langue
     * @return Category|null
     */
    public function findBySlugPath(string $path, Site $site, string $locale): ?Category
    {
        $slugs = array_filter(explode('/', trim($path, '/')));

        if (empty($slugs)) {
            return null;
        }

        // Chercher la catégorie racine
        $parent = $this->findBySlug($slugs[0], $site, $locale);

        if (!$parent) {
            return null;
        }

        // Si un seul niveau, retourner directement
        if (count($slugs) === 1) {
            return $parent;
        }

        // Chercher l'enfant (niveau 2)
        $childSlug = $slugs[1];
        foreach ($parent->getChildren() as $child) {
            if ($child->getSlug() === $childSlug && !$child->isDeleted()) {
                return $child;
            }
        }

        return null;
    }

    /**
     * Vérifie si un slug existe déjà (pour validation unicité).
     * 
     * @param string $slug Slug à vérifier
     * @param Site $site Site concerné
     * @param string $locale Langue
     * @param int|null $excludeId ID à exclure (pour update)
     * @return bool True si le slug existe déjà
     */
    public function isSlugTaken(string $slug, Site $site, string $locale, ?int $excludeId = null): bool
    {
        $qb = $this->createQueryBuilder($this->defaultalias)
            ->select('COUNT(' . $this->defaultalias . '.id)')
            ->where($this->defaultalias . '.slug = :slug')
            ->andWhere($this->defaultalias . '.site = :site')
            ->andWhere($this->defaultalias . '.locale = :locale')
            ->andWhere($this->defaultalias . '.isDeleted = false')
            ->setParameter('slug', $slug)
            ->setParameter('site', $site)
            ->setParameter('locale', $locale);

        if ($excludeId !== null) {
            $qb->andWhere($this->defaultalias . '.id != :excludeId')
                ->setParameter('excludeId', $excludeId);
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }

    // ===============================================
    // STATISTIQUES & COMPTAGES
    // ===============================================

    /**
     * Récupère les catégories avec le nombre de produits associés.
     * 
     * Utilité : Affichage menu avec compteurs (ex: "Miels (42)").
     * 
     * @param Site $site Site concerné
     * @param string $locale Langue
     * @return array Tableau avec [Category, productCount]
     */
    public function findWithProductCounts(Site $site, string $locale): array
    {
        return $this->createQueryBuilder('c')
            ->select('c', 'COUNT(p.id) as productCount')
            ->leftJoin('c.products', 'p')
            ->where('c.site = :site')
            ->andWhere('c.locale = :locale')
            ->andWhere('c.isDeleted = false')
            ->andWhere('c.closedAt IS NULL')
            ->groupBy('c.id')
            ->orderBy('c.position', 'ASC')
            ->setParameter('site', $site)
            ->setParameter('locale', $locale)
            ->getQuery()
            ->getResult();
    }

    /**
     * Récupère les catégories les plus populaires (avec le plus de produits).
     * 
     * Utilité : Homepage "Catégories tendances".
     * 
     * @param Site $site Site concerné
     * @param string $locale Langue
     * @param int $limit Nombre max de résultats
     * @return Category[]
     */
    public function findPopularCategories(Site $site, string $locale, int $limit = 10): array
    {
        return $this->createQueryBuilder('c')
            ->select('c', 'COUNT(p.id) as HIDDEN productCount')
            ->innerJoin('c.products', 'p')
            ->where('c.site = :site')
            ->andWhere('c.locale = :locale')
            ->andWhere('c.isDeleted = false')
            ->andWhere('c.closedAt IS NULL')
            ->andWhere('p.isDeleted = false')
            ->andWhere('p.closedAt IS NULL')
            ->groupBy('c.id')
            ->orderBy('productCount', 'DESC')
            ->addOrderBy('c.name', 'ASC')
            ->setMaxResults($limit)
            ->setParameter('site', $site)
            ->setParameter('locale', $locale)
            ->getQuery()
            ->getResult();
    }

    /**
     * Compte le nombre de catégories actives d'un site.
     * 
     * @param Site $site Site concerné
     * @param string $locale Langue
     * @return int
     */
    public function countActiveBySite(Site $site, string $locale): int
    {
        return (int) $this->createQueryBuilder($this->defaultalias)
            ->select('COUNT(' . $this->defaultalias . '.id)')
            ->where($this->defaultalias . '.site = :site')
            ->andWhere($this->defaultalias . '.locale = :locale')
            ->andWhere($this->defaultalias . '.closedAt IS NULL')
            ->andWhere($this->defaultalias . '.isDeleted = false')
            ->setParameter('site', $site)
            ->setParameter('locale', $locale)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Trouve les catégories vides (sans produits associés).
     * 
     * Utilité : Nettoyage admin, alerte catégories inutilisées.
     * 
     * @param Site $site Site concerné
     * @param string $locale Langue
     * @return Category[]
     */
    public function findEmptyCategories(Site $site, string $locale): array
    {
        return $this->createQueryBuilder('c')
            ->leftJoin('c.products', 'p')
            ->where('c.site = :site')
            ->andWhere('c.locale = :locale')
            ->andWhere('c.isDeleted = false')
            ->groupBy('c.id')
            ->having('COUNT(p.id) = 0')
            ->setParameter('site', $site)
            ->setParameter('locale', $locale)
            ->getQuery()
            ->getResult();
    }

    // ===============================================
    // MÉTHODES AVEC EAGER LOADING
    // ===============================================

    /**
     * Trouve une catégorie par ID avec ses enfants pré-chargés.
     * 
     * Performance : Évite le N+1 si on parcourt les enfants après.
     * 
     * @param int $id ID de la catégorie
     * @return Category|null
     */
    public function findWithChildren(int $id): ?Category
    {
        return $this->createQueryBuilder('c')
            ->leftJoin('c.children', 'children')
            ->addSelect('children')
            ->where('c.id = :id')
            ->andWhere('c.isDeleted = false')
            ->setParameter('id', $id)
            ->orderBy('children.position', 'ASC')
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Trouve une catégorie par slug avec ses enfants pré-chargés.
     * 
     * Cas d'usage : Page catégorie avec liste des sous-catégories.
     * 
     * @param string $slug Slug de la catégorie
     * @param Site $site Site concerné
     * @param string $locale Langue
     * @return Category|null
     */
    public function findBySlugWithChildren(string $slug, Site $site, string $locale): ?Category
    {
        return $this->createQueryBuilder('c')
            ->leftJoin('c.children', 'children')
            ->addSelect('children')
            ->where('c.slug = :slug')
            ->andWhere('c.site = :site')
            ->andWhere('c.locale = :locale')
            ->andWhere('c.isDeleted = false')
            ->setParameter('slug', $slug)
            ->setParameter('site', $site)
            ->setParameter('locale', $locale)
            ->orderBy('children.position', 'ASC')
            ->getQuery()
            ->getOneOrNullResult();
    }

    // ===============================================
    // MÉTHODES UTILITAIRES
    // ===============================================

    /**
     * Récupère toutes les catégories d'un site (plat, sans hiérarchie).
     * 
     * Utilité : Export, sitemap XML, admin liste complète.
     * 
     * @param Site $site Site concerné
     * @param string $locale Langue
     * @param bool $activeOnly Uniquement actives ?
     * @return Category[]
     */
    public function findAllFlat(Site $site, string $locale, bool $activeOnly = true): array
    {
        $qb = $this->createQueryBuilder($this->defaultalias)
            ->where($this->defaultalias . '.site = :site')
            ->andWhere($this->defaultalias . '.locale = :locale')
            ->andWhere($this->defaultalias . '.isDeleted = false')
            ->setParameter('site', $site)
            ->setParameter('locale', $locale)
            ->orderBy($this->defaultalias . '.parent', 'ASC') // Racines d'abord
            ->addOrderBy($this->defaultalias . '.position', 'ASC');

        if ($activeOnly) {
            $qb->andWhere($this->defaultalias . '.closedAt IS NULL');
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Vérifie si une catégorie a des produits associés.
     * 
     * Utilité : Validation avant suppression.
     * 
     * @param int $categoryId ID de la catégorie
     * @return bool True si la catégorie a au moins 1 produit
     */
    public function hasProducts(int $categoryId): bool
    {
        $count = (int) $this->createQueryBuilder('c')
            ->select('COUNT(p.id)')
            ->leftJoin('c.products', 'p')
            ->where('c.id = :id')
            ->andWhere('p.isDeleted = false')
            ->setParameter('id', $categoryId)
            ->getQuery()
            ->getSingleScalarResult();

        return $count > 0;
    }

    /**
     * Réorganise les positions des catégories.
     * 
     * Utilité : Drag & drop dans l'admin pour réordonner.
     * 
     * @param array $positions Tableau [id => position]
     */
    public function updatePositions(array $positions): void
    {
        $conn = $this->getEntityManager()->getConnection();

        foreach ($positions as $id => $position) {
            $conn->executeStatement(
                'UPDATE category SET position = :position WHERE id = :id',
                ['position' => $position, 'id' => $id]
            );
        }
    }
}

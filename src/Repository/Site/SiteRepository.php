<?php

declare(strict_types=1);

namespace App\Repository\Site;

use App\Entity\Site\Site;
use App\Enum\Site\SiteStatus;
use App\Repository\Core\AbstractRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Repository pour l'entité Site.
 * 
 * Responsabilités :
 * - Recherche paginée avec filtres (statut, devise, actif uniquement)
 * - Méthodes spécifiques multi-tenant (findByDomain, findByCode)
 * - Vérifications d'unicité pour validation métier
 */
class SiteRepository extends AbstractRepository
{
    /** Champs autorisés pour le tri */
    protected array $sortableFields = ['id', 'code', 'name', 'domain', 'currency', 'createdAt', 'status'];

    /** Champs pour la recherche textuelle */
    protected array $searchableFields = ['name', 'code', 'domain'];

    protected string $defaultalias = 's';

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Site::class);
    }

    /**
     * Recherche paginée avec filtres spécifiques aux sites.
     * 
     * Filtres supportés :
     * - search : Recherche textuelle sur name, code, domain
     * - status : Filtrer par statut (active, maintenance, archived)
     * - currency : Filtrer par devise (EUR, USD, etc.)
     * - active_only : Uniquement les sites accessibles publiquement
     * - sortBy / sortOrder : Tri personnalisé
     */
    public function findWithPagination(int $page, int $limit, array $filters = []): array
    {
        $qb = $this->createBaseQueryBuilder();

        // Recherche textuelle globale (name, code, domain)
        $this->applyTextSearch($qb, $filters);

        // Filtres spécifiques
        $this->applyStatusFilter($qb, $filters);
        $this->applyCurrencyFilter($qb, $filters);
        $this->applyActiveOnlyFilter($qb, $filters);

        // Tri (utilise la whitelist de sortableFields)
        $this->applySorting($qb, $filters);

        // Pagination et comptage automatique
        return $this->buildPaginatedResponse($qb, $page, $limit);
    }

    // ===============================================
    // FILTRES SPÉCIFIQUES
    // ===============================================

    /**
     * Filtre par statut (enum SiteStatus).
     */
    private function applyStatusFilter(QueryBuilder $qb, array $filters): void
    {
        if (empty($filters['status'])) {
            return;
        }

        // Support de plusieurs statuts (ex: ?status=active,maintenance)
        $statuses = is_array($filters['status'])
            ? $filters['status']
            : explode(',', $filters['status']);

        // Validation : uniquement les valeurs de l'enum
        $validStatuses = array_filter($statuses, function ($status) {
            return SiteStatus::tryFrom($status) !== null;
        });

        if (!empty($validStatuses)) {
            $qb->andWhere($this->defaultalias . '.status IN (:statuses)')
                ->setParameter('statuses', $validStatuses);
        }
    }

    /**
     * Filtre par devise.
     */
    private function applyCurrencyFilter(QueryBuilder $qb, array $filters): void
    {
        if (empty($filters['currency'])) {
            return;
        }

        $qb->andWhere($this->defaultalias . '.currency = :currency')
            ->setParameter('currency', strtoupper($filters['currency']));
    }

    /**
     * Filtre pour ne retourner que les sites accessibles publiquement.
     * (status = active, closedAt = null, isDeleted = false)
     */
    private function applyActiveOnlyFilter(QueryBuilder $qb, array $filters): void
    {
        if (empty($filters['active_only']) || !filter_var($filters['active_only'], FILTER_VALIDATE_BOOLEAN)) {
            return;
        }

        $qb->andWhere($this->defaultalias . '.status = :activeStatus')
            ->andWhere($this->defaultalias . '.closedAt IS NULL')
            ->andWhere($this->defaultalias . '.isDeleted = false')
            ->setParameter('activeStatus', SiteStatus::ACTIVE->value);
    }

    // ===============================================
    // MÉTHODES MÉTIER SPÉCIFIQUES
    // ===============================================

    /**
     * Trouve un site par son domaine.
     * Utilisé pour le routing multi-tenant (identifier le site depuis l'URL).
     * 
     * @param string $domain Le domaine à rechercher (ex: boutique-bio.fr)
     * @return Site|null Le site trouvé ou null
     */
    public function findByDomain(string $domain): ?Site
    {
        return $this->createQueryBuilder($this->defaultalias)
            ->where($this->defaultalias . '.domain = :domain')
            ->andWhere($this->defaultalias . '.isDeleted = false')
            ->setParameter('domain', strtolower($domain))
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Trouve un site par son code unique.
     * 
     * @param string $code Le code du site (ex: FR, BE, PRO)
     * @return Site|null Le site trouvé ou null
     */
    public function findByCode(string $code): ?Site
    {
        return $this->createQueryBuilder($this->defaultalias)
            ->where($this->defaultalias . '.code = :code')
            ->andWhere($this->defaultalias . '.isDeleted = false')
            ->setParameter('code', strtoupper($code))
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Retourne tous les sites accessibles publiquement.
     * Utilisé pour la sélection de site côté front, sitemap, etc.
     * 
     * @return Site[] Liste des sites actifs et accessibles
     */
    public function findAccessibleSites(): array
    {
        return $this->createQueryBuilder($this->defaultalias)
            ->where($this->defaultalias . '.status = :status')
            ->andWhere($this->defaultalias . '.closedAt IS NULL')
            ->andWhere($this->defaultalias . '.isDeleted = false')
            ->setParameter('status', SiteStatus::ACTIVE)
            ->orderBy($this->defaultalias . '.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Compte le nombre de sites actifs.
     * Utile pour les statistiques admin.
     * 
     * @return int Nombre de sites actifs
     */
    public function countActiveSites(): int
    {
        return (int) $this->createQueryBuilder($this->defaultalias)
            ->select('COUNT(' . $this->defaultalias . '.id)')
            ->where($this->defaultalias . '.status = :status')
            ->andWhere($this->defaultalias . '.closedAt IS NULL')
            ->andWhere($this->defaultalias . '.isDeleted = false')
            ->setParameter('status', SiteStatus::ACTIVE)
            ->getQuery()
            ->getSingleScalarResult();
    }

    // ===============================================
    // VÉRIFICATIONS D'UNICITÉ (pour validation métier)
    // ===============================================

    /**
     * Vérifie si un domaine est déjà utilisé par un autre site.
     * Utilisé lors de la création/modification de site pour éviter les doublons.
     * 
     * @param string $domain Le domaine à vérifier
     * @param int|null $excludeId ID du site à exclure (pour update)
     * @return bool True si le domaine est déjà pris
     */
    public function isDomainTaken(string $domain, ?int $excludeId = null): bool
    {
        $qb = $this->createQueryBuilder($this->defaultalias)
            ->select('COUNT(' . $this->defaultalias . '.id)')
            ->where($this->defaultalias . '.domain = :domain')
            ->andWhere($this->defaultalias . '.isDeleted = false')
            ->setParameter('domain', strtolower($domain));

        if ($excludeId !== null) {
            $qb->andWhere($this->defaultalias . '.id != :excludeId')
                ->setParameter('excludeId', $excludeId);
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }

    /**
     * Vérifie si un code est déjà utilisé par un autre site.
     * 
     * @param string $code Le code à vérifier
     * @param int|null $excludeId ID du site à exclure (pour update)
     * @return bool True si le code est déjà pris
     */
    public function isCodeTaken(string $code, ?int $excludeId = null): bool
    {
        $qb = $this->createQueryBuilder($this->defaultalias)
            ->select('COUNT(' . $this->defaultalias . '.id)')
            ->where($this->defaultalias . '.code = :code')
            ->andWhere($this->defaultalias . '.isDeleted = false')
            ->setParameter('code', strtoupper($code));

        if ($excludeId !== null) {
            $qb->andWhere($this->defaultalias . '.id != :excludeId')
                ->setParameter('excludeId', $excludeId);
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }

    /**
     * Trouve les sites par devise.
     * Utile pour des traitements par lots (exports, conversions...).
     * 
     * @param string $currency Code devise ISO (EUR, USD...)
     * @return Site[] Liste des sites avec cette devise
     */
    public function findByCurrency(string $currency): array
    {
        return $this->createQueryBuilder($this->defaultalias)
            ->where($this->defaultalias . '.currency = :currency')
            ->andWhere($this->defaultalias . '.isDeleted = false')
            ->setParameter('currency', strtoupper($currency))
            ->orderBy($this->defaultalias . '.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Trouve les sites supportant une locale donnée.
     * Utile pour le routage multilingue.
     * 
     * @param string $locale Code locale (fr, en, es...)
     * @return Site[] Liste des sites supportant cette locale
     */
    public function findByLocale(string $locale): array
    {
        return $this->createQueryBuilder($this->defaultalias)
            ->where('JSON_CONTAINS(' . $this->defaultalias . '.locales, :locale) = 1')
            ->andWhere($this->defaultalias . '.isDeleted = false')
            ->setParameter('locale', json_encode($locale))
            ->orderBy($this->defaultalias . '.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}

<?php

declare(strict_types=1);

namespace App\Repository\Cart;

use App\Entity\Cart\Coupon;
use App\Entity\Site\Site;
use App\Entity\User\User;
use App\Repository\Core\AbstractRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Repository pour l'entité Coupon.
 * 
 * Responsabilités :
 * - Recherche par code
 * - Validation d'éligibilité
 * - Statistiques d'utilisation
 * - Nettoyage coupons expirés
 */
class CouponRepository extends AbstractRepository
{
    protected array $sortableFields = ['id', 'code', 'createdAt', 'validFrom', 'validUntil', 'usageCount'];
    protected array $searchableFields = ['code', 'publicMessage', 'internalNote'];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Coupon::class);
    }

    /**
     * Recherche paginée avec filtres.
     */
    public function findWithPagination(int $page, int $limit, array $filters = []): array
    {
        $qb = $this->createBaseQueryBuilder();

        $this->applyMultipleLikeFilters($qb, $filters, $this->searchableFields);
        $this->applyTextSearch($qb, $filters);
        $this->applySiteFilter($qb, $filters);
        $this->applyTypeFilter($qb, $filters);
        $this->applyActiveOnlyFilter($qb, $filters);
        $this->applyValidityFilter($qb, $filters);
        $this->applySorting($qb, $filters);

        return $this->buildPaginatedResponse($qb, $page, $limit);
    }

    // ===============================================
    // RECHERCHE PAR CODE
    // ===============================================

    /**
     * Trouve un coupon par code (insensible à la casse).
     * 
     * @param string $code Code promo
     * @param Site $site Site concerné
     * @return Coupon|null
     */
    public function findByCode(string $code, Site $site): ?Coupon
    {
        return $this->createQueryBuilder('c')
            ->where('UPPER(c.code) = UPPER(:code)')
            ->andWhere('c.site = :site')
            ->andWhere('c.isDeleted = false')
            ->setParameter('code', $code)
            ->setParameter('site', $site)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Trouve un coupon valide par code.
     * 
     * Critères :
     * - Existe
     * - Actif (closedAt = null)
     * - Non supprimé
     * - Dans période de validité
     * - Non épuisé
     * 
     * @param string $code Code promo
     * @param Site $site Site concerné
     * @return Coupon|null
     */
    public function findValidByCode(string $code, Site $site): ?Coupon
    {
        $now = new \DateTimeImmutable();

        return $this->createQueryBuilder('c')
            ->where('UPPER(c.code) = UPPER(:code)')
            ->andWhere('c.site = :site')
            ->andWhere('c.isDeleted = false')
            ->andWhere('c.closedAt IS NULL')
            ->andWhere('(c.validFrom IS NULL OR c.validFrom <= :now)')
            ->andWhere('(c.validUntil IS NULL OR c.validUntil >= :now)')
            ->andWhere('(c.maxUsages IS NULL OR c.usageCount < c.maxUsages)')
            ->setParameter('code', $code)
            ->setParameter('site', $site)
            ->setParameter('now', $now)
            ->getQuery()
            ->getOneOrNullResult();
    }

    // ===============================================
    // VALIDATION D'ÉLIGIBILITÉ
    // ===============================================

    /**
     * Compte le nombre d'utilisations d'un coupon par un utilisateur.
     * 
     * @param Coupon $coupon Coupon concerné
     * @param User $user Utilisateur
     * @return int Nombre d'utilisations
     */
    public function countUsagesByUser(Coupon $coupon, User $user): int
    {
        // TODO: Après création de Order
        // return (int) $this->getEntityManager()
        //     ->createQueryBuilder()
        //     ->select('COUNT(o.id)')
        //     ->from('App\Entity\Order\Order', 'o')
        //     ->where('o.coupon = :coupon')
        //     ->andWhere('o.user = :user')
        //     ->setParameter('coupon', $coupon)
        //     ->setParameter('user', $user)
        //     ->getQuery()
        //     ->getSingleScalarResult();

        // Pour l'instant, compter via Cart
        $conn = $this->getEntityManager()->getConnection();
        $sql = 'SELECT COUNT(*) FROM cart WHERE coupon_id = :couponId AND user_id = :userId';
        $stmt = $conn->prepare($sql);
        $result = $stmt->executeQuery([
            'couponId' => $coupon->getId(),
            'userId' => $user->getId()
        ]);

        return (int) $result->fetchOne();
    }

    /**
     * Vérifie si un utilisateur peut utiliser un coupon.
     * 
     * @param Coupon $coupon Coupon à vérifier
     * @param User $user Utilisateur
     * @param string $customerType Type de client (B2C/B2B)
     * @return array ['can_use' => bool, 'reason' => string|null]
     */
    public function checkUserEligibility(Coupon $coupon, User $user, string $customerType): array
    {
        if (!$coupon->isValid()) {
            return [
                'can_use' => false,
                'reason' => 'Coupon invalide ou expiré.'
            ];
        }

        if (!$coupon->isValidForCustomerType($customerType)) {
            return [
                'can_use' => false,
                'reason' => 'Coupon non valable pour votre type de compte.'
            ];
        }

        $userUsages = $this->countUsagesByUser($coupon, $user);

        if (!$coupon->canUserUse($userUsages)) {
            return [
                'can_use' => false,
                'reason' => 'Vous avez déjà utilisé ce coupon le nombre maximum de fois.'
            ];
        }

        // TODO: Vérifier firstOrderOnly après création de Order
        // if ($coupon->isFirstOrderOnly() && $user->getOrdersCount() > 0) {
        //     return ['can_use' => false, 'reason' => 'Réservé première commande uniquement.'];
        // }

        return [
            'can_use' => true,
            'reason' => null
        ];
    }

    // ===============================================
    // STATISTIQUES
    // ===============================================

    /**
     * Récupère les coupons actifs d'un site.
     * 
     * @param Site $site Site concerné
     * @return Coupon[]
     */
    public function findActiveBySite(Site $site): array
    {
        $now = new \DateTimeImmutable();

        return $this->createQueryBuilder('c')
            ->where('c.site = :site')
            ->andWhere('c.isDeleted = false')
            ->andWhere('c.closedAt IS NULL')
            ->andWhere('(c.validFrom IS NULL OR c.validFrom <= :now)')
            ->andWhere('(c.validUntil IS NULL OR c.validUntil >= :now)')
            ->andWhere('(c.maxUsages IS NULL OR c.usageCount < c.maxUsages)')
            ->setParameter('site', $site)
            ->setParameter('now', $now)
            ->orderBy('c.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Récupère les coupons les plus utilisés.
     * 
     * @param Site $site Site concerné
     * @param int $limit Nombre de résultats
     * @return Coupon[]
     */
    public function findMostUsed(Site $site, int $limit = 10): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.site = :site')
            ->andWhere('c.isDeleted = false')
            ->andWhere('c.usageCount > 0')
            ->setParameter('site', $site)
            ->orderBy('c.usageCount', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Compte les coupons expirés non utilisés.
     * 
     * @param Site $site Site concerné
     * @return int
     */
    public function countExpiredUnused(Site $site): int
    {
        $now = new \DateTimeImmutable();

        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where('c.site = :site')
            ->andWhere('c.isDeleted = false')
            ->andWhere('c.validUntil < :now')
            ->andWhere('c.usageCount = 0')
            ->setParameter('site', $site)
            ->setParameter('now', $now)
            ->getQuery()
            ->getSingleScalarResult();
    }

    // ===============================================
    // FILTRES SPÉCIFIQUES
    // ===============================================

    private function applySiteFilter($qb, array $filters): void
    {
        if (empty($filters['site_id'])) {
            return;
        }

        $qb->andWhere('c.site = :siteId')
            ->setParameter('siteId', $filters['site_id']);
    }

    private function applyTypeFilter($qb, array $filters): void
    {
        if (empty($filters['type'])) {
            return;
        }

        $qb->andWhere('c.type = :type')
            ->setParameter('type', $filters['type']);
    }

    private function applyActiveOnlyFilter($qb, array $filters): void
    {
        if (empty($filters['active_only']) || !filter_var($filters['active_only'], FILTER_VALIDATE_BOOLEAN)) {
            return;
        }

        $qb->andWhere('c.closedAt IS NULL')
            ->andWhere('c.isDeleted = false');
    }

    private function applyValidityFilter($qb, array $filters): void
    {
        if (!isset($filters['valid_only']) || !filter_var($filters['valid_only'], FILTER_VALIDATE_BOOLEAN)) {
            return;
        }

        $now = new \DateTimeImmutable();

        $qb->andWhere('(c.validFrom IS NULL OR c.validFrom <= :now)')
            ->andWhere('(c.validUntil IS NULL OR c.validUntil >= :now)')
            ->andWhere('(c.maxUsages IS NULL OR c.usageCount < c.maxUsages)')
            ->setParameter('now', $now);
    }
}

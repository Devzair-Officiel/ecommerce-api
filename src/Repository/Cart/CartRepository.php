<?php

declare(strict_types=1);

namespace App\Repository\Cart;

use App\Entity\Cart\Cart;
use App\Entity\Site\Site;
use App\Entity\User\User;
use App\Repository\Core\AbstractRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Repository pour l'entité Cart.
 * 
 * Responsabilités :
 * - Recherche par user/sessionToken
 * - Nettoyage automatique paniers expirés
 * - Fusion paniers invités → utilisateurs
 * - Statistiques abandons/conversion
 */
class CartRepository extends AbstractRepository
{
    protected array $sortableFields = ['id', 'createdAt', 'updatedAt', 'expiresAt'];
    protected array $searchableFields = [];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Cart::class);
    }

    /**
     * Recherche paginée avec filtres admin.
     */
    public function findWithPagination(int $page, int $limit, array $filters = []): array
    {
        $qb = $this->createBaseQueryBuilder();

        $this->applySiteFilter($qb, $filters);
        $this->applyUserFilter($qb, $filters);
        $this->applyGuestOnlyFilter($qb, $filters);
        $this->applyExpiredFilter($qb, $filters);
        $this->applyEmptyFilter($qb, $filters);
        $this->applySorting($qb, $filters);

        return $this->buildPaginatedResponse($qb, $page, $limit);
    }

    // ===============================================
    // RECHERCHE PAR UTILISATEUR / TOKEN
    // ===============================================

    /**
     * Trouve le panier d'un utilisateur connecté.
     * 
     * @param User $user Utilisateur
     * @param Site $site Site concerné
     * @return Cart|null
     */
    public function findByUser(User $user, Site $site): ?Cart
    {
        return $this->createQueryBuilder('c')
            ->where('c.user = :user')
            ->andWhere('c.site = :site')
            ->setParameter('user', $user)
            ->setParameter('site', $site)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Trouve un panier invité par token de session.
     * 
     * @param string $sessionToken Token UUID
     * @param Site $site Site concerné
     * @return Cart|null
     */
    public function findBySessionToken(string $sessionToken, Site $site): ?Cart
    {
        return $this->createQueryBuilder('c')
            ->where('c.sessionToken = :token')
            ->andWhere('c.site = :site')
            ->andWhere('c.expiresAt > :now')
            ->setParameter('token', $sessionToken)
            ->setParameter('site', $site)
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Trouve ou crée un panier (utilisateur ou invité).
     * 
     * Workflow :
     * - Si utilisateur : cherche son panier existant
     * - Si invité : cherche par sessionToken
     * - Si aucun : retourne null (création gérée par CartService)
     * 
     * @param Site $site Site concerné
     * @param User|null $user Utilisateur connecté (null si invité)
     * @param string|null $sessionToken Token invité (null si utilisateur)
     * @return Cart|null
     */
    public function findOrNull(Site $site, ?User $user = null, ?string $sessionToken = null): ?Cart
    {
        if ($user !== null) {
            return $this->findByUser($user, $site);
        }

        if ($sessionToken !== null) {
            return $this->findBySessionToken($sessionToken, $site);
        }

        return null;
    }

    // ===============================================
    // GESTION EXPIRATION
    // ===============================================

    /**
     * Trouve tous les paniers expirés.
     * 
     * @return Cart[]
     */
    public function findExpired(): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.expiresAt < :now')
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->getResult();
    }

    /**
     * Supprime les paniers expirés (nettoyage automatique).
     * 
     * Recommandation : Exécuter via CRON toutes les heures.
     * 
     * @return int Nombre de paniers supprimés
     */
    public function deleteExpired(): int
    {
        return $this->createQueryBuilder('c')
            ->delete()
            ->where('c.expiresAt < :now')
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->execute();
    }

    /**
     * Supprime les paniers vides expirés depuis X jours.
     * 
     * Plus agressif que deleteExpired() :
     * - Cible uniquement paniers vides
     * - Garde un historique court (1 jour par défaut)
     * 
     * @param int $days Nombre de jours depuis expiration
     * @return int Nombre de paniers supprimés
     */
    public function deleteEmptyExpiredOlderThan(int $days = 1): int
    {
        $threshold = (new \DateTimeImmutable())->modify("-{$days} days");

        // Sous-requête pour trouver les paniers avec au moins 1 item
        $subQuery = $this->getEntityManager()->createQueryBuilder()
            ->select('IDENTITY(ci.cart)')
            ->from('App\Entity\Cart\CartItem', 'ci')
            ->getDQL();

        return $this->createQueryBuilder('c')
            ->delete()
            ->where('c.expiresAt < :threshold')
            ->andWhere('c.id NOT IN (' . $subQuery . ')')
            ->setParameter('threshold', $threshold)
            ->getQuery()
            ->execute();
    }

    // ===============================================
    // FUSION PANIERS (INVITÉ → UTILISATEUR)
    // ===============================================

    /**
     * Fusionne un panier invité dans le panier d'un utilisateur.
     * 
     * Workflow lors de l'inscription/connexion :
     * 1. Utilisateur avait un panier invité (sessionToken)
     * 2. Utilisateur s'inscrit/se connecte
     * 3. On cherche si l'utilisateur a déjà un panier
     * 4. Si oui : fusionner items du panier invité
     * 5. Si non : attacher le panier invité à l'utilisateur
     * 6. Supprimer le panier invité si fusion
     * 
     * @param Cart $guestCart Panier invité à fusionner
     * @param User $user Utilisateur cible
     * @return Cart Panier final (existant ou converti)
     */
    public function mergeGuestCartIntoUser(Cart $guestCart, User $user): Cart
    {
        $userCart = $this->findByUser($user, $guestCart->getSite());

        if (!$userCart) {
            // Pas de panier existant → attacher le panier invité
            $guestCart->attachToUser($user);
            $this->getEntityManager()->flush();
            return $guestCart;
        }

        // Panier existant → fusionner les items
        foreach ($guestCart->getItems() as $guestItem) {
            $existingItem = $userCart->findItemByVariant($guestItem->getVariant()?->getId());

            if ($existingItem) {
                // Item déjà présent → additionner quantités
                $newQty = $existingItem->getQuantity() + $guestItem->getQuantity();
                $existingItem->setQuantity($newQty);
            } else {
                // Nouvel item → transférer
                $guestItem->setCart($userCart);
                $userCart->addItem($guestItem);
            }
        }

        // Supprimer le panier invité (items transférés)
        $this->getEntityManager()->remove($guestCart);
        $this->getEntityManager()->flush();

        return $userCart;
    }

    // ===============================================
    // STATISTIQUES & ANALYTICS
    // ===============================================

    /**
     * Compte les paniers actifs (non expirés).
     * 
     * @param Site $site Site concerné
     * @return int
     */
    public function countActive(Site $site): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where('c.site = :site')
            ->andWhere('c.expiresAt > :now')
            ->setParameter('site', $site)
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Compte les paniers abandonnés (avec items, expirés).
     * 
     * Utilité : Analytics taux d'abandon.
     * 
     * @param Site $site Site concerné
     * @param int $days Abandonné depuis X jours
     * @return int
     */
    public function countAbandoned(Site $site, int $days = 7): int
    {
        $threshold = (new \DateTimeImmutable())->modify("-{$days} days");

        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(DISTINCT c.id)')
            ->innerJoin('c.items', 'ci')
            ->where('c.site = :site')
            ->andWhere('c.lastActivityAt < :threshold')
            ->setParameter('site', $site)
            ->setParameter('threshold', $threshold)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Calcule la valeur totale des paniers actifs.
     * 
     * @param Site $site Site concerné
     * @return float Montant total
     */
    public function getTotalActiveValue(Site $site): float
    {
        $result = $this->createQueryBuilder('c')
            ->select('SUM(ci.priceAtAdd * ci.quantity) as total')
            ->innerJoin('c.items', 'ci')
            ->where('c.site = :site')
            ->andWhere('c.expiresAt > :now')
            ->setParameter('site', $site)
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->getSingleScalarResult();

        return (float) ($result ?? 0);
    }

    /**
     * Récupère les paniers abandonnés avec valeur > seuil.
     * 
     * Utilité : Campagnes email de relance.
     * 
     * @param Site $site Site concerné
     * @param float $minValue Valeur minimale du panier
     * @param int $daysAgo Abandonné depuis X jours
     * @return Cart[]
     */
    public function findHighValueAbandoned(Site $site, float $minValue = 50.0, int $daysAgo = 3): array
    {
        $threshold = (new \DateTimeImmutable())->modify("-{$daysAgo} days");

        return $this->createQueryBuilder('c')
            ->innerJoin('c.items', 'ci')
            ->where('c.site = :site')
            ->andWhere('c.lastActivityAt < :threshold')
            ->andWhere('c.expiresAt > :now')
            ->groupBy('c.id')
            ->having('SUM(ci.priceAtAdd * ci.quantity) >= :minValue')
            ->setParameter('site', $site)
            ->setParameter('threshold', $threshold)
            ->setParameter('now', new \DateTimeImmutable())
            ->setParameter('minValue', $minValue)
            ->getQuery()
            ->getResult();
    }

    /**
     * Statistiques globales paniers par site.
     */
    public function getStatsBySite(Site $site): array
    {
        $conn = $this->getEntityManager()->getConnection();

        $sql = '
            SELECT 
                COUNT(DISTINCT c.id) as total_carts,
                COUNT(DISTINCT CASE WHEN c.user_id IS NOT NULL THEN c.id END) as user_carts,
                COUNT(DISTINCT CASE WHEN c.user_id IS NULL THEN c.id END) as guest_carts,
                COUNT(DISTINCT CASE WHEN c.expires_at > NOW() THEN c.id END) as active_carts,
                COUNT(DISTINCT CASE WHEN c.expires_at <= NOW() THEN c.id END) as expired_carts,
                COUNT(ci.id) as total_items,
                SUM(ci.quantity) as total_quantity,
                SUM(ci.price_at_add * ci.quantity) as total_value
            FROM cart c
            LEFT JOIN cart_item ci ON ci.cart_id = c.id
            WHERE c.site_id = :siteId
        ';

        $stmt = $conn->prepare($sql);
        $result = $stmt->executeQuery(['siteId' => $site->getId()]);

        return $result->fetchAssociative() ?: [];
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

    private function applyUserFilter($qb, array $filters): void
    {
        if (empty($filters['user_id'])) {
            return;
        }

        $qb->andWhere('c.user = :userId')
            ->setParameter('userId', $filters['user_id']);
    }

    private function applyGuestOnlyFilter($qb, array $filters): void
    {
        if (empty($filters['guest_only']) || !filter_var($filters['guest_only'], FILTER_VALIDATE_BOOLEAN)) {
            return;
        }

        $qb->andWhere('c.user IS NULL')
            ->andWhere('c.sessionToken IS NOT NULL');
    }

    private function applyExpiredFilter($qb, array $filters): void
    {
        if (!isset($filters['expired'])) {
            return;
        }

        $isExpired = filter_var($filters['expired'], FILTER_VALIDATE_BOOLEAN);

        if ($isExpired) {
            $qb->andWhere('c.expiresAt < :now');
        } else {
            $qb->andWhere('c.expiresAt >= :now');
        }

        $qb->setParameter('now', new \DateTimeImmutable());
    }

    private function applyEmptyFilter($qb, array $filters): void
    {
        if (empty($filters['empty']) || !filter_var($filters['empty'], FILTER_VALIDATE_BOOLEAN)) {
            return;
        }

        $subQuery = $this->getEntityManager()->createQueryBuilder()
            ->select('IDENTITY(ci2.cart)')
            ->from('App\Entity\Cart\CartItem', 'ci2')
            ->getDQL();

        $qb->andWhere('c.id NOT IN (' . $subQuery . ')');
    }
}

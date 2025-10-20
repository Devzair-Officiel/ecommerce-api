<?php

declare(strict_types=1);

namespace App\Repository\User;

use App\Entity\Site\Site;
use App\Entity\User\User;
use App\Enum\User\UserRole;
use App\Repository\Core\AbstractRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;

/**
 * Repository pour l'entité User.
 * 
 * Implémente PasswordUpgraderInterface pour le rehashing automatique des passwords.
 */
class UserRepository extends AbstractRepository implements PasswordUpgraderInterface
{
    protected array $sortableFields = ['id', 'email', 'username', 'firstName', 'lastName', 'createdAt', 'lastLoginAt'];
    protected array $searchableFields = ['email', 'username', 'firstName', 'lastName'];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    /**
     * Recherche paginée avec filtres.
     * 
     * Filtres supportés :
     * - search : Recherche textuelle
     * - site_id : Filtrer par site
     * - role : Filtrer par rôle
     * - is_verified : Comptes vérifiés uniquement
     * - active_only : Comptes actifs uniquement
     */
    public function findWithPagination(int $page, int $limit, array $filters = []): array
    {
        $qb = $this->createBaseQueryBuilder();

        $this->applyTextSearch($qb, $filters);
        $this->applySiteFilter($qb, $filters);
        $this->applyRoleFilter($qb, $filters);
        $this->applyVerifiedFilter($qb, $filters);
        $this->applyActiveOnlyFilter($qb, $filters);
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

    private function applyRoleFilter(QueryBuilder $qb, array $filters): void
    {
        if (empty($filters['role'])) {
            return;
        }

        $qb->andWhere('JSON_CONTAINS(' . $this->defaultalias . '.roles, :role) = 1')
            ->setParameter('role', json_encode($filters['role']));
    }

    private function applyVerifiedFilter(QueryBuilder $qb, array $filters): void
    {
        if (!isset($filters['is_verified'])) {
            return;
        }

        $isVerified = filter_var($filters['is_verified'], FILTER_VALIDATE_BOOLEAN);
        $qb->andWhere($this->defaultalias . '.isVerified = :isVerified')
            ->setParameter('isVerified', $isVerified);
    }

    private function applyActiveOnlyFilter(QueryBuilder $qb, array $filters): void
    {
        if (empty($filters['active_only']) || !filter_var($filters['active_only'], FILTER_VALIDATE_BOOLEAN)) {
            return;
        }

        $qb->andWhere($this->defaultalias . '.closedAt IS NULL')
            ->andWhere($this->defaultalias . '.isDeleted = false')
            ->andWhere($this->defaultalias . '.isVerified = true');
    }

    // ===============================================
    // MÉTHODES MÉTIER
    // ===============================================

    /**
     * Trouve un utilisateur par email (tous sites confondus).
     * ⚠️ Attention : Ne pas utiliser pour l'authentification (utiliser findByEmailAndSite).
     * Usage : Vérifications globales, admin multi-site.
     */
    public function findByEmail(string $email): ?User
    {
        return $this->createQueryBuilder($this->defaultalias)
            ->where($this->defaultalias . '.email = :email')
            ->andWhere($this->defaultalias . '.isDeleted = false')
            ->setParameter('email', strtolower($email))
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Trouve un utilisateur par email sur un site donné.
     * Utilisé pour la connexion JWT (multi-tenant).
     */
    public function findByEmailAndSite(string $email, Site $site): ?User
    {
        return $this->createQueryBuilder($this->defaultalias)
            ->where($this->defaultalias . '.email = :email')
            ->andWhere($this->defaultalias . '.site = :site')
            ->andWhere($this->defaultalias . '.isDeleted = false')
            ->setParameter('email', strtolower($email))
            ->setParameter('site', $site)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Trouve un utilisateur par token de vérification.
     */
    public function findByVerificationToken(string $token): ?User
    {
        return $this->createQueryBuilder($this->defaultalias)
            ->where($this->defaultalias . '.verificationToken = :token')
            ->andWhere($this->defaultalias . '.isDeleted = false')
            ->setParameter('token', $token)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Trouve tous les admins d'un site.
     */
    public function findAdminsBySite(Site $site): array
    {
        $adminRoles = array_map(fn($role) => $role->value, UserRole::adminRoles());

        $qb = $this->createQueryBuilder($this->defaultalias)
            ->where($this->defaultalias . '.site = :site')
            ->andWhere($this->defaultalias . '.isDeleted = false')
            ->setParameter('site', $site);

        // Condition OR pour chaque rôle admin
        $orX = $qb->expr()->orX();
        foreach ($adminRoles as $index => $role) {
            $orX->add('JSON_CONTAINS(' . $this->defaultalias . '.roles, :role' . $index . ') = 1');
            $qb->setParameter('role' . $index, json_encode($role));
        }
        $qb->andWhere($orX);

        return $qb->getQuery()->getResult();
    }

    /**
     * Compte les utilisateurs actifs d'un site.
     */
    public function countActiveBySite(Site $site): int
    {
        return (int) $this->createQueryBuilder($this->defaultalias)
            ->select('COUNT(' . $this->defaultalias . '.id)')
            ->where($this->defaultalias . '.site = :site')
            ->andWhere($this->defaultalias . '.closedAt IS NULL')
            ->andWhere($this->defaultalias . '.isDeleted = false')
            ->andWhere($this->defaultalias . '.isVerified = true')
            ->setParameter('site', $site)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Vérifie si un email est déjà utilisé sur un site.
     */
    public function isEmailTaken(string $email, Site $site, ?int $excludeId = null): bool
    {
        $qb = $this->createQueryBuilder($this->defaultalias)
            ->select('COUNT(' . $this->defaultalias . '.id)')
            ->where($this->defaultalias . '.email = :email')
            ->andWhere($this->defaultalias . '.site = :site')
            ->andWhere($this->defaultalias . '.isDeleted = false')
            ->setParameter('email', strtolower($email))
            ->setParameter('site', $site);

        if ($excludeId !== null) {
            $qb->andWhere($this->defaultalias . '.id != :excludeId')
                ->setParameter('excludeId', $excludeId);
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }

    /**
     * Trouve les utilisateurs inactifs depuis X jours.
     */
    public function findInactiveUsers(int $daysInactive, ?Site $site = null): array
    {
        $thresholdDate = new \DateTimeImmutable("-{$daysInactive} days");

        $qb = $this->createQueryBuilder($this->defaultalias)
            ->where($this->defaultalias . '.lastLoginAt < :threshold')
            ->andWhere($this->defaultalias . '.isDeleted = false')
            ->setParameter('threshold', $thresholdDate)
            ->orderBy($this->defaultalias . '.lastLoginAt', 'ASC');

        if ($site !== null) {
            $qb->andWhere($this->defaultalias . '.site = :site')
                ->setParameter('site', $site);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Trouve les utilisateurs ayant opté pour la newsletter.
     */
    public function findNewsletterSubscribers(?Site $site = null): array
    {
        $qb = $this->createQueryBuilder($this->defaultalias)
            ->where($this->defaultalias . '.newsletterOptIn = true')
            ->andWhere($this->defaultalias . '.isDeleted = false')
            ->andWhere($this->defaultalias . '.closedAt IS NULL')
            ->orderBy($this->defaultalias . '.email', 'ASC');

        if ($site !== null) {
            $qb->andWhere($this->defaultalias . '.site = :site')
                ->setParameter('site', $site);
        }

        return $qb->getQuery()->getResult();
    }

    // ===============================================
    // PASSWORD UPGRADER INTERFACE
    // ===============================================

    /**
     * Upgrade automatique du password hash si l'algorithme change.
     */
    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User) {
            throw new \InvalidArgumentException('Expected instance of User.');
        }

        $user->setPassword($newHashedPassword);
        $this->getEntityManager()->flush();
    }
}

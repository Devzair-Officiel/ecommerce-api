<?php

declare(strict_types=1);

namespace App\Service\User;

use App\Entity\Site\Site;
use App\Entity\User\User;
use App\Enum\User\UserRole;
use App\Exception\ConflictException;
use App\Exception\ValidationException;
use App\Repository\User\UserRepository;
use App\Service\Core\AbstractService;
use App\Service\Core\RelationProcessor;
use App\Utils\ValidationUtils;
use Doctrine\ORM\EntityManagerInterface;
use Gesdinet\JWTRefreshTokenBundle\Entity\RefreshToken;
use Gesdinet\JWTRefreshTokenBundle\Model\RefreshTokenManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Service métier pour la gestion des utilisateurs.
 * 
 * Responsabilités :
 * - Création/modification de comptes (avec tokens JWT)
 * - Vérification email
 * - Changement de mot de passe
 * - Gestion des rôles et statuts
 * 
 * ⚠️ Note : Le hashage du plainPassword est automatique via UserPasswordSubscriber.
 */
class UserService extends AbstractService
{
    public function __construct(
        EntityManagerInterface $em,
        SerializerInterface $serializer,
        ValidatorInterface $validator,
        RelationProcessor $relationProcessor,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly UserRepository $userRepository,
        private readonly JWTTokenManagerInterface $jwtManager,
        private readonly RefreshTokenManagerInterface $refreshTokenManager,
    ) {
        parent::__construct($em, $serializer, $validator, $relationProcessor);
    }

    protected function getEntityClass(): string
    {
        return User::class;
    }

    protected function getRepository(): UserRepository
    {
        return $this->userRepository;
    }

    // ===============================================
    // CRÉATION AVEC TOKENS JWT
    // ===============================================

    /**
     * Crée un utilisateur et retourne les tokens JWT.
     * Utilisé pour l'inscription (endpoint public).
     * 
     * @param array $data Données utilisateur (doit contenir plainPassword)
     * @param Site $site Site auquel rattacher l'utilisateur
     * @return array ['user' => User, 'token' => string, 'refresh_token' => string]
     * @throws ConflictException Si l'email existe déjà sur ce site
     * @throws ValidationException Si les données sont invalides
     */
    public function createUserWithTokens(array $data, Site $site): array
    {
        // Vérifier que l'email n'est pas déjà pris sur ce site
        if ($this->userRepository->isEmailTaken($data['email'], $site)) {
            throw new ConflictException('User', 'email', $data['email']);
        }

        // Créer l'utilisateur avec validation groupe 'user:create'
        $user = $this->createWithHooks($data, [
            'site' => $site,
            'validation_groups' => ['Default', 'user:create']
        ]);

        // Générer les tokens JWT (après que le user ait son ID)
        $token = $this->jwtManager->create($user);
        $refreshToken = $this->createRefreshToken($user);

        return [
            'user' => $user,
            'token' => $token,
            'refresh_token' => $refreshToken
        ];
    }

    /**
     * Crée un utilisateur sans tokens (endpoint admin).
     */
    public function createUser(array $data, Site $site): User
    {
        if ($this->userRepository->isEmailTaken($data['email'], $site)) {
            throw new ConflictException('User', 'email', $data['email']);
        }

        return $this->createWithHooks($data, [
            'site' => $site,
            'validation_groups' => ['Default', 'user:create']
        ]);
    }

    // ===============================================
    // HOOKS MÉTIER
    // ===============================================

    protected function beforeCreate(object $entity, array $data, array $context): void
    {
        /** @var User $entity */

        // Assigner le site depuis le contexte
        if (isset($context['site'])) {
            $entity->setSite($context['site']);
        }

        // ⚠️ Le plainPassword sera hashé automatiquement par UserPasswordSubscriber
        // Pas besoin de le faire ici

        // Générer le token de vérification
        $entity->setVerificationToken($this->generateVerificationToken());

        // Rôle par défaut si non spécifié
        if (empty($entity->getRoles()) || $entity->getRoles() === [UserRole::ROLE_USER->value]) {
            $entity->setRoles([UserRole::ROLE_USER->value]);
        }
    }

    protected function beforeUpdate(object $entity, array $data, array $context): void
    {
        /** @var User $entity */

        // Si un nouveau plainPassword est fourni, re-valider avec groupe 'user:password'
        if (!empty($data['plainPassword'])) {
            $violations = $this->validator->validate($entity, null, ['Default', 'user:password']);
            if (count($violations) > 0) {
                $errors = ValidationUtils::formatValidationErrors($violations);
                throw new ValidationException($errors);
            }
            // ⚠️ Le hashage sera fait automatiquement par UserPasswordSubscriber
        }

        // Vérifier l'unicité de l'email si modifié
        if (isset($data['email']) && $data['email'] !== $entity->getEmail()) {
            if ($this->userRepository->isEmailTaken($data['email'], $entity->getSite(), $entity->getId())) {
                throw new ConflictException('User', 'email', $data['email']);
            }
        }
    }

    // ===============================================
    // SURCHARGE DES MÉTHODES CRUD STANDARDS
    // ===============================================

    /**
     * Méthode create standard (compatible avec AbstractService).
     * Utilisée par le controller admin.
     */
    public function create(array $data, array $context = []): object
    {
        if (!isset($context['site'])) {
            throw new \InvalidArgumentException('Site context is required for user creation.');
        }

        return $this->createUser($data, $context['site']);
    }

    /**
     * Mise à jour d'un utilisateur.
     * ⚠️ Ne gère PAS : password, rôles, statut (endpoints dédiés).
     */
    public function update(int $id, array $data, array $context = []): object
    {
        // Sécurité : retirer les champs sensibles si présents
        unset($data['password'], $data['roles'], $data['isDeleted'], $data['verificationToken']);

        return $this->updateWithHooks($id, $data, $context);
    }

    /**
     * Suppression d'un utilisateur (soft delete).
     */
    public function delete(int $id, array $context = []): object
    {
        return $this->deleteWithHooks($id, $context);
    }

    // ===============================================
    // VÉRIFICATION EMAIL
    // ===============================================

    /**
     * Vérifie l'email d'un utilisateur via son token.
     */
    public function verifyEmail(string $token): User
    {
        $user = $this->userRepository->findByVerificationToken($token);

        if (!$user) {
            throw new \InvalidArgumentException('Token de vérification invalide ou expiré.');
        }

        if ($user->isVerified()) {
            throw new \LogicException('Ce compte est déjà vérifié.');
        }

        $user->setIsVerified(true);
        $user->setVerificationToken(null);

        $this->em->flush();

        return $user;
    }

    /**
     * Régénère un token de vérification.
     */
    public function regenerateVerificationToken(User $user): User
    {
        if ($user->isVerified()) {
            throw new \LogicException('Ce compte est déjà vérifié.');
        }

        $user->setVerificationToken($this->generateVerificationToken());
        $this->em->flush();

        return $user;
    }

    // ===============================================
    // CHANGEMENT DE MOT DE PASSE
    // ===============================================

    /**
     * Change le mot de passe d'un utilisateur.
     * 
     * @param int $id ID de l'utilisateur
     * @param string $newPlainPassword Nouveau mot de passe en clair
     * @return User Utilisateur mis à jour
     * @throws ValidationException Si le nouveau mot de passe est invalide
     */
    public function changePassword(int $id, string $newPlainPassword): User
    {
        /** @var User $user */
        $user = $this->findEntityById($id);

        // 1) Valider avec le groupe 'user:password'
        $user->setPlainPassword($newPlainPassword);
        $violations = $this->validator->validate($user, null, ['user:password']);
        if (count($violations) > 0) {
            $errors = ValidationUtils::formatValidationErrors($violations);
            throw new ValidationException($errors);
        }

        // 2) Hasher et enregistrer
        $hash = $this->passwordHasher->hashPassword($user, $newPlainPassword);
        $user->setPassword($hash);
        $user->eraseCredentials();

        // 3) Optionnel : marquer updatedAt pour audit
        if (method_exists($user, 'setUpdatedAt')) {
            $user->setUpdatedAt(new \DateTimeImmutable());
        }

        $this->em->flush();

        // 4) (optionnel) Révoquer les refresh tokens existants
        // Minimal : invalide les tokens expirés (déjà présent chez toi)
        $this->refreshTokenManager->revokeAllInvalid();
        // Si tu as une méthode pour supprimer ceux du user, utilise-la ici.

        return $user;
    }

    /**
     * Vérifie si un mot de passe est correct.
     */
    public function isPasswordValid(User $user, string $plainPassword): bool
    {
        return $this->passwordHasher->isPasswordValid($user, $plainPassword);
    }

    // ===============================================
    // GESTION DES RÔLES
    // ===============================================

    /**
     * Ajoute un rôle à un utilisateur.
     */
    public function addRole(int $id, string $role): User
    {
        $user = $this->findEntityById($id);

        // Valider que le rôle existe
        $roleEnum = UserRole::tryFrom($role);
        if (!$roleEnum) {
            throw new \InvalidArgumentException("Invalid role: {$role}");
        }

        $user->addRole($roleEnum);
        $this->em->flush();

        return $user;
    }

    /**
     * Retire un rôle d'un utilisateur.
     */
    public function removeRole(int $id, string $role): User
    {
        if ($role === UserRole::ROLE_USER->value) {
            throw new \InvalidArgumentException('Cannot remove ROLE_USER');
        }

        $user = $this->findEntityById($id);

        $roleEnum = UserRole::tryFrom($role);
        if ($roleEnum) {
            $user->removeRole($roleEnum);
            $this->em->flush();
        }

        return $user;
    }

    // ===============================================
    // GESTION DU STATUT (ACTIVE/INACTIVE)
    // ===============================================

    /**
     * Active/désactive un utilisateur (toggle status).
     * Utilise ActiveStateTrait (closedAt).
     */
    public function toggleStatus(int $id, bool $active): User
    {
        $user = $this->findEntityById($id);

        if ($active) {
            $user->activate();
        } else {
            $user->deactivate();
            // Révoquer les tokens si désactivation
            $this->refreshTokenManager->revokeAllInvalid();
        }

        $this->em->flush();

        return $user;
    }

    /**
     * Bannir un utilisateur (désactivation).
     */
    public function banUser(int $id): User
    {
        $user = $this->findEntityById($id);
        $user->ban();
        $this->em->flush();

        // Révoquer tous les tokens
        $this->refreshTokenManager->revokeAllInvalid();

        return $user;
    }

    /**
     * Débannir un utilisateur.
     */
    public function unbanUser(int $id): User
    {
        $user = $this->findEntityById($id);
        $user->unban();
        $this->em->flush();

        return $user;
    }

    // ===============================================
    // RECHERCHE
    // ===============================================

    /**
     * Trouve un utilisateur par email (tous sites).
     */
    public function findByEmail(string $email): ?User
    {
        return $this->userRepository->findByEmail($email);
    }

    /**
     * Trouve un utilisateur par email et site (multi-tenant).
     */
    public function findByEmailAndSite(string $email, Site $site): ?User
    {
        return $this->userRepository->findByEmailAndSite($email, $site);
    }

    // ===============================================
    // HELPERS PRIVÉS
    // ===============================================

    /**
     * Génère un token de vérification sécurisé.
     */
    private function generateVerificationToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Crée un refresh token JWT.
     */
    private function createRefreshToken(User $user): string
    {
        $refreshToken = new RefreshToken();
        $refreshToken->setRefreshToken(bin2hex(random_bytes(32)));
        $refreshToken->setUsername($user->getUserIdentifier());
        $refreshToken->setValid((new \DateTime())->modify('+30 days'));

        $this->refreshTokenManager->save($refreshToken);

        return $refreshToken->getRefreshToken();
    }

    /**
     * Configuration des relations (aucune pour User actuellement).
     */
    protected function getRelationConfig(): array
    {
        return [
            // Relations futures :
            // - 'addresses' => OneToMany
            // - 'orders' => OneToMany
            // - 'reviews' => OneToMany
        ];
    }
}

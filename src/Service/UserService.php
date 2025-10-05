<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Entity\User\Team;
use App\Entity\Lov\Country;
use App\Entity\User\Division;
use App\Entity\Lov\MailLocale;
use App\Entity\User\Laboratory;
use App\Entity\Planning\Session;
use App\Repository\UserRepository;
use App\Service\Core\AbstractService;
use App\Exception\ValidationException;
use App\Service\Core\RelationProcessor;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Serializer\SerializerInterface;

use Gesdinet\JWTRefreshTokenBundle\Entity\RefreshToken;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Gesdinet\JWTRefreshTokenBundle\Model\RefreshTokenManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;

class UserService extends AbstractService
{
    public function __construct(
        EntityManagerInterface $em,
        SerializerInterface $serializer,
        ValidatorInterface $validator,
        RelationProcessor $relationProcessor,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly UserRepository $userRepository,
        private JWTTokenManagerInterface $jwtManager,
        private RefreshTokenManagerInterface $refreshTokenManager,
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

    /**
     * Création avec hooks + retourne les tokens JWT.
     * 
     * @return array ['user' => User, 'token' => string, 'refresh_token' => string]
     */
    public function createUserWithTokens(array $data, array $context = []): array
    {
        $user = $this->em->wrapInTransaction(function () use ($data, $context) {
            $entity = $this->deserializeToEntity($data);

            // Validation avec groupe 'create' pour plainPassword
            $this->validateEntity($entity, ['Default', 'create']);

            $this->beforeCreate($entity, $data, $context);
            $this->processRelations($entity, $data, 'create');

            $this->em->persist($entity);
            $this->em->flush();

            return $entity;
        });

        // Génération des tokens APRÈS la transaction (user a son ID)
        $token = $this->jwtManager->create($user);

        $refreshToken = new RefreshToken();
        $refreshToken->setRefreshToken(bin2hex(random_bytes(32)));
        $refreshToken->setUsername($user->getUserIdentifier());
        $refreshToken->setValid((new \DateTime())->modify('+30 days'));
        $this->refreshTokenManager->save($refreshToken);

        return [
            'user' => $user,
            'token' => $token,
            'refresh_token' => $refreshToken->getRefreshToken()
        ];
    }

    /**
     * Méthode create standard sans tokens (pour compatibilité).
     */
    public function create(array $data, array $context = []): object
    {
        return $this->createUserWithTokens($data, $context)['user'];
    }

    public function update(int $id, array $data, array $context = []): object
    {
        return $this->updateWithHooks($id, $data, $context);
    }

    public function delete(int $id, array $context = []): object
    {
        return $this->deleteWithHooks($id, $context);
    }

    // ===============================================
    // HOOKS MÉTIER
    // ===============================================

    protected function beforeCreate(object $entity, array $data, array $context): void
    {
        /** @var User $entity */

        $existing = $this->userRepository->findByEmail($entity->getEmail());
        $this->throwConflictIfExists($existing, 'email', $entity->getEmail());

        // Hash du password depuis plainPassword
        $plainPassword = $entity->getPlainPassword();
        if ($plainPassword) {
            $hashedPassword = $this->passwordHasher->hashPassword($entity, $plainPassword);
            $entity->setPassword($hashedPassword);
            $entity->setPlainPassword(null);
        }

        // Rôle par défaut
        if (empty($entity->getRoles())) {
            $entity->setRoles(['ROLE_USER']);
        }

        // Statut valid par défaut
        if ($entity->isValid() === null) {
            $entity->setValid(true);
        }
    }

    protected function beforeUpdate(object $entity, array $data, array $context): void
    {
        // Validation avec groupe approprié si password change
        $plainPassword = $entity->getPlainPassword();
        if ($plainPassword) {
            // Re-valider avec groupe update_password
            $violations = $this->validator->validate($entity, null, ['Default', 'update_password']);
            if (count($violations) > 0) {
                $errors = \App\Utils\ValidationUtils::formatValidationErrors($violations);
                throw new ValidationException($errors);
            }

            $hashedPassword = $this->passwordHasher->hashPassword($entity, $plainPassword);
            $entity->setPassword($hashedPassword);
            $entity->setPlainPassword(null);
        }
    }

    // ===============================================
    // MÉTHODES MÉTIER DÉDIÉES (ENDPOINTS SÉPARÉS)
    // ===============================================

    /**
     * Trouve un utilisateur par email.
     * Utilisé pour la validation et l'authentification.
     */
    public function findByEmail(string $email): ?User
    {
        return $this->userRepository->findByEmail($email);
    }

    /**
     * Change le mot de passe d'un utilisateur.
     * Endpoint dédié : PATCH /users/{id}/password
     * 
     * Avantages :
     * - Validation spécifique (groupe update_password)
     * - Sécurité : peut être restreint (user change son propre password)
     * - Révocation des anciens tokens
     */
    public function changePassword(int $id, string $newPlainPassword): User
    {
        $user = $this->findEntityById($id);
        $user->setPlainPassword($newPlainPassword);

        // Validation avec groupe update_password
        $violations = $this->validator->validate($user, null, ['update_password']);
        if (count($violations) > 0) {
            $errors = \App\Utils\ValidationUtils::formatValidationErrors($violations);
            throw new ValidationException($errors);
        }

        $hashedPassword = $this->passwordHasher->hashPassword($user, $newPlainPassword);
        $user->setPassword($hashedPassword);
        $user->setPlainPassword(null);

        $this->em->flush();

        // Révocation des anciens tokens pour forcer reconnexion
        $this->refreshTokenManager->revokeAllInvalid();

        return $user;
    }

    /**
     * Active/désactive un utilisateur.
     * Endpoint dédié : PATCH /users/{id}/status
     * 
     * Avantages :
     * - Action claire et explicite
     * - Peut être restrictif (ROLE_ADMIN uniquement)
     * - Révocation des tokens si désactivation
     */
    public function toggleStatus(int $id, bool $valid): User
    {
        $user = $this->findEntityById($id);
        $user->setIsValid($valid);

        $this->em->flush();

        // Si désactivation, révoquer les tokens invalides
        if (!$valid) {
            $this->refreshTokenManager->revokeAllInvalid();
        }

        return $user;
    }

    /**
     * Ajoute un rôle à un utilisateur.
     * Endpoint dédié : POST /users/{id}/roles
     * 
     * Avantages :
     * - Gestion granulaire des permissions
     * - ROLE_ADMIN uniquement
     * - Évite les erreurs dans update général
     */
    public function addRole(int $id, string $role): User
    {
        $user = $this->findEntityById($id);

        $roles = $user->getRoles();
        if (!in_array($role, $roles, true)) {
            $roles[] = $role;
            $user->setRoles($roles);
            $this->em->flush();
        }

        return $user;
    }

    /**
     * Retire un rôle d'un utilisateur.
     * Endpoint dédié : DELETE /users/{id}/roles/{role}
     * 
     * Protection : impossible de retirer ROLE_USER
     */
    public function removeRole(int $id, string $role): User
    {
        $user = $this->findEntityById($id);

        if ($role === 'ROLE_USER') {
            throw new \InvalidArgumentException('Cannot remove ROLE_USER');
        }

        $roles = array_values(array_diff($user->getRoles(), [$role]));
        $user->setRoles($roles);
        $this->em->flush();

        return $user;
    }


    // protected function getRelationConfig(): array
    // {
    //     return [
    //         'team' => [
    //             'type' => 'many_to_one',
    //             'target_entity' => Team::class,
    //             'identifier_key' => 'id',
    //         ],
    //         'division' => [
    //             'type' => 'many_to_one',
    //             'target_entity' => Division::class,
    //             'identifier_key' => 'id',
    //         ],
    //         'laboratory' => [
    //             'type' => 'many_to_one',
    //             'target_entity' => Laboratory::class,
    //             'identifier_key' => 'id',
    //         ],
    //         'country' => [
    //             'type' => 'many_to_one',
    //             'target_entity' => Country::class,
    //             'identifier_key' => 'id',
    //         ],
    //         'mailLocale' => [
    //             'type' => 'many_to_one',
    //             'target_entity' => MailLocale::class,
    //             'identifier_key' => 'id',
    //         ],
    //         'supervisors' => [
    //             'type' => 'many_to_many',
    //             'target_entity' => User::class,
    //             'identifier_key' => 'id',
    //         ],
    //         'sessions' => [
    //             'type' => 'many_to_many',
    //             'target_entity' => Session::class,
    //             'identifier_key' => 'id',
    //         ],
    //     ];
    // }
}

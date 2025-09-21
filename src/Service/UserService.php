<?php

namespace App\Service;

use App\Entity\User;
use App\Service\AbstractService;
use Doctrine\ORM\EntityManagerInterface;
use App\Exception\EntityNotFoundException;
use App\Service\Processing\EntityProcessor;
use App\Service\Processing\RelationManager;
use App\Repository\Interface\UserRepositoryInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class UserService extends AbstractService
{
    public function __construct(
        EntityManagerInterface $em,
        EntityProcessor $entityProcessor,
        RelationManager $relationManager,
        private readonly UserRepositoryInterface $userRepository,
        private readonly UserPasswordHasherInterface $passwordHasher
    ) {
        parent::__construct($em, $entityProcessor, $relationManager);
    }

    public function createUser(string $jsonData): User
    {
        return $this->executeInTransaction(function () use ($jsonData) {
            // 1. Traitement des données (validation automatique via contraintes)
            $user = $this->processEntityFromJson($jsonData, User::class);

            // 2. Validation métier
            $this->validateForCreation($user);

            // 3. Hachage du mot de passe si fourni
            if ($user->getPassword()) {
                $hashedPassword = $this->passwordHasher->hashPassword($user, $user->getPassword());
                $user->setPassword($hashedPassword);
            }

            // 4. Sauvegarde (dates automatiques via DateTrait)
            $this->saveEntity($user);

            return $user;
        });
    }

    public function updateUser(int $id, string $jsonData): User
    {
        return $this->executeInTransaction(function () use ($id, $jsonData) {
            $user = $this->findEntityByCriteria(User::class, ['id' => $id]);

            // Sauvegarde de l'ancien mot de passe
            $oldPassword = $user->getPassword();

            $this->processEntityFromJson($jsonData, User::class, $user);

            // Validation métier
            $this->validateForUpdate($user);

            // Gestion du mot de passe
            $this->handlePasswordUpdate($user, $oldPassword);

            $this->saveEntity($user);

            return $user;
        });
    }

    public function getUser(int $id): User
    {
        return $this->findEntityByCriteria(User::class, ['id' => $id]);
    }

    public function getUserByEmail(string $email): User
    {
        $user = $this->userRepository->findByEmail($email);

        if (!$user) {
            throw new EntityNotFoundException('user', ['email' => $email]);
        }

        return $user;
    }

    public function deleteUser(int $id): User
    {
        return $this->executeInTransaction(function () use ($id) {
            $user = $this->findEntityByCriteria(User::class, ['id' => $id]);

            $this->validateDeletion($user);

            $this->removeEntity($user);

            return $user;
        });
    }

    public function toggleUserStatus(int $id, bool $active): User
    {
        $user = $this->findEntityByCriteria(User::class, ['id' => $id]);

        $user->setIsActive($active);
        $this->saveEntity($user);

        return $user;
    }

    public function changePassword(int $id, string $newPassword): User
    {
        $user = $this->findEntityByCriteria(User::class, ['id' => $id]);

        $hashedPassword = $this->passwordHasher->hashPassword($user, $newPassword);
        $user->setPassword($hashedPassword);

        $this->saveEntity($user);

        return $user;
    }

    public function addRole(int $id, string $role): User
    {
        $user = $this->findEntityByCriteria(User::class, ['id' => $id]);

        $roles = $user->getRoles();
        if (!in_array($role, $roles, true)) {
            $roles[] = $role;
            $user->setRoles($roles);
            $this->saveEntity($user);
        }

        return $user;
    }

    public function removeRole(int $id, string $role): User
    {
        $user = $this->findEntityByCriteria(User::class, ['id' => $id]);

        if ($role === 'ROLE_USER') {
            throw new \InvalidArgumentException('Cannot remove ROLE_USER');
        }

        $roles = array_values(array_diff($user->getRoles(), [$role]));
        $user->setRoles($roles);
        $this->saveEntity($user);

        return $user;
    }

    // === VALIDATION MÉTIER ===

    private function validateForCreation(User $user): void
    {
        if ($this->userRepository->findByEmail($user->getEmail())) {
            throw new \InvalidArgumentException('Email already exists');
        }
    }

    private function validateForUpdate(User $user): void
    {
        $existingUser = $this->userRepository->findByEmail($user->getEmail());
        if ($existingUser && $existingUser->getId() !== $user->getId()) {
            throw new \InvalidArgumentException('Email already exists');
        }
    }

    private function validateDeletion(User $user): void
    {
        if ($this->userRepository->hasActiveOrders($user)) {
            throw new \DomainException('Cannot delete user with active orders');
        }
    }

    private function handlePasswordUpdate(User $user, string $oldPassword): void
    {
        // Si le mot de passe a changé et n'est pas vide
        if ($user->getPassword() && $user->getPassword() !== $oldPassword) {
            // Si ce n'est pas déjà un hash (nouveau mot de passe en clair)
            if (!password_get_info($user->getPassword())['algo']) {
                $hashedPassword = $this->passwordHasher->hashPassword($user, $user->getPassword());
                $user->setPassword($hashedPassword);
            }
        } elseif (!$user->getPassword()) {
            // Si le mot de passe est vide, on garde l'ancien
            $user->setPassword($oldPassword);
        }
    }
}
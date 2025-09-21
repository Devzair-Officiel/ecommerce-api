<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\User;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Faker\Factory;
use Faker\Generator;

final class UserFixtures extends Fixture
{
    private const USER_COUNT = 20;

    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher
    ) {}

    public function load(ObjectManager $manager): void
    {
        $faker = Factory::create('fr_FR');

        // Utilisateurs normaux
        for ($i = 1; $i <= self::USER_COUNT; $i++) {
            $user = new User();
            $user->setEmail($faker->unique()->safeEmail());
            $user->setFirstname($faker->firstName());
            $user->setLastname($faker->lastName());
            $user->setPassword($this->passwordHasher->hashPassword($user, 'password123'));
            $user->setRoles(['ROLE_USER']); // Tous ROLE_USER
            $user->setPhone($faker->optional(0.8)->phoneNumber());
            $user->setIsActive($faker->boolean(85)); // 85% actifs

            $manager->persist($user);
        }

        // Admin spécifique
        $admin = new User();
        $admin->setEmail('admin@gmail.com');
        $admin->setFirstname('Aurel');
        $admin->setLastname('Boud');
        $admin->setPassword($this->passwordHasher->hashPassword($admin, 'azerty'));
        $admin->setRoles(['ROLE_ADMIN', 'ROLE_USER']);
        $admin->setPhone('0601020304');
        $admin->setIsActive(true);

        $manager->persist($admin);
        $manager->flush();
    }
}

<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\User;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Faker\Factory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class UserFixtures extends Fixture
{
    private const USER_COUNT = 20;

    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher
    ) {}

    public function load(ObjectManager $manager): void
    {
        $faker = Factory::create('fr_FR');

        // Création d'utilisateurs aléatoires
        for ($i = 1; $i <= self::USER_COUNT; $i++) {
            $user = new User();
            $user->setEmail($faker->unique()->safeEmail());
            $user->setFirstname($faker->firstName());
            $user->setLastname($faker->lastName());
            $user->setPassword($this->passwordHasher->hashPassword($user, 'password123'));
            $user->setRoles([$faker->randomElement(['ROLE_USER', 'ROLE_ADMIN'])]);
            $user->setPhone($faker->optional(0.8)->phoneNumber()); // 80% de chance d'avoir un numéro
            $user->setLastLogin($faker->optional()->dateTimeBetween('-1 year', 'now') ? new \DateTimeImmutable() : null);

            $manager->persist($user);
        }

        // Création d'un utilisateur admin fixe
        $admin = new User();
        $admin->setEmail('admin@gmail.com');
        $admin->setFirstname('Aurel');
        $admin->setLastname('Boud');
        $admin->setPassword($this->passwordHasher->hashPassword($admin, 'azerty'));
        $admin->setRoles(['ROLE_ADMIN']);
        $admin->setPhone('0601020304');
        $admin->setLastLogin(new \DateTimeImmutable());

        $manager->persist($admin);

        $manager->flush();
    }
}

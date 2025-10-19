<?php

declare(strict_types=1);

namespace App\DataFixtures\User;

use App\Entity\Site\Site;
use App\Entity\User\User;
use App\Enum\User\UserRole;
use Faker\Factory as FakerFactory;
use App\DataFixtures\Site\SiteFixtures;
use Doctrine\Persistence\ObjectManager;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Fixtures pour l'entité User.
 * 
 * Génère :
 * - 1 super admin multi-site
 * - 1 admin par site
 * - 1 modérateur par site
 * - 20 clients standards par site
 */
class UserFixtures extends Fixture implements DependentFixtureInterface
{
    public const USER_SUPER_ADMIN = 'user_super_admin';
    public const USER_ADMIN_FR = 'user_admin_fr';
    public const USER_CLIENT_FR_1 = 'user_client_fr_1';

    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher
    ) {}

    public function load(ObjectManager $manager): void
    {
        $faker = FakerFactory::create('fr_FR');

        /** @var Site $siteFr */
        $siteFr = $this->getReference(SiteFixtures::SITE_FR_REFERENCE, Site::class);
        /** @var Site $siteBe */
        $siteBe = $this->getReference(SiteFixtures::SITE_BE_REFERENCE, Site::class);
        /** @var Site $sitePro */
        $sitePro = $this->getReference(SiteFixtures::SITE_PRO_REFERENCE, Site::class);

        // ===============================================
        // SUPER ADMIN (multi-site)
        // ===============================================
        $superAdmin = $this->createUser(
            site: $siteFr,
            email: 'superadmin@boutique-bio.fr',
            password: 'SuperAdmin123!',
            firstName: 'Jean',
            lastName: 'Dupont',
            roles: [UserRole::ROLE_SUPER_ADMIN],
            isVerified: true
        );
        $manager->persist($superAdmin);
        $this->addReference(self::USER_SUPER_ADMIN, $superAdmin);

        // ===============================================
        // ADMINS PAR SITE
        // ===============================================
        $adminFr = $this->createUser(
            site: $siteFr,
            email: 'admin@boutique-bio.fr',
            password: 'Admin123!',
            firstName: 'Marie',
            lastName: 'Martin',
            roles: [UserRole::ROLE_ADMIN],
            isVerified: true
        );
        $manager->persist($adminFr);
        $this->addReference(self::USER_ADMIN_FR, $adminFr);

        $adminBe = $this->createUser(
            site: $siteBe,
            email: 'admin@boutique-bio.be',
            password: 'Admin123!',
            firstName: 'Pierre',
            lastName: 'Leroy',
            roles: [UserRole::ROLE_ADMIN],
            isVerified: true
        );
        $manager->persist($adminBe);

        // ===============================================
        // MODÉRATEURS PAR SITE
        // ===============================================
        $moderatorFr = $this->createUser(
            site: $siteFr,
            email: 'moderateur@boutique-bio.fr',
            password: 'Moderator123!',
            firstName: 'Sophie',
            lastName: 'Bernard',
            roles: [UserRole::ROLE_MODERATOR],
            isVerified: true
        );
        $manager->persist($moderatorFr);

        $moderatorBe = $this->createUser(
            site: $siteBe,
            email: 'moderateur@boutique-bio.be',
            password: 'Moderator123!',
            firstName: 'Luc',
            lastName: 'Dubois',
            roles: [UserRole::ROLE_MODERATOR],
            isVerified: true
        );
        $manager->persist($moderatorBe);

        // ===============================================
        // CLIENTS STANDARDS (20 par site)
        // ===============================================

        // Site France
        for ($i = 1; $i <= 20; $i++) {
            $user = $this->createUser(
                site: $siteFr,
                email: $faker->unique()->safeEmail(),
                password: 'Password123!',
                firstName: $faker->firstName(),
                lastName: $faker->lastName(),
                roles: [UserRole::ROLE_USER],
                isVerified: $faker->boolean(80), // 80% vérifiés
                phone: $faker->phoneNumber(),
                birthDate: $faker->dateTimeBetween('-65 years', '-18 years'),
                newsletterOptIn: $faker->boolean(30) // 30% opt-in newsletter
            );

            // Simuler des dernières connexions variées
            if ($user->isVerified()) {
                $lastLogin = $faker->dateTimeBetween('-6 months', 'now');
                $user->setLastLoginAt(\DateTimeImmutable::createFromMutable($lastLogin));
            }

            $manager->persist($user);

            // Référence pour le premier client (sera utilisé dans d'autres fixtures)
            if ($i === 1) {
                $this->addReference(self::USER_CLIENT_FR_1, $user);
            }
        }

        // Site Belgique
        for ($i = 1; $i <= 20; $i++) {
            $user = $this->createUser(
                site: $siteBe,
                email: $faker->unique()->safeEmail(),
                password: 'Password123!',
                firstName: $faker->firstName(),
                lastName: $faker->lastName(),
                roles: [UserRole::ROLE_USER],
                isVerified: $faker->boolean(75),
                phone: $faker->phoneNumber(),
                birthDate: $faker->dateTimeBetween('-65 years', '-18 years'),
                newsletterOptIn: $faker->boolean(25)
            );

            if ($user->isVerified()) {
                $lastLogin = $faker->dateTimeBetween('-6 months', 'now');
                $user->setLastLoginAt(\DateTimeImmutable::createFromMutable($lastLogin));
            }

            $manager->persist($user);
        }

        // Site Pro (B2B - moins d'utilisateurs)
        for ($i = 1; $i <= 10; $i++) {
            $user = $this->createUser(
                site: $sitePro,
                email: $faker->unique()->companyEmail(),
                password: 'Password123!',
                firstName: $faker->firstName(),
                lastName: $faker->lastName(),
                roles: [UserRole::ROLE_USER],
                isVerified: true, // Tous vérifiés pour B2B
                phone: $faker->phoneNumber(),
                metadata: [
                    'company' => $faker->company(),
                    'vat_number' => $faker->numerify('BE##########'),
                    'business_type' => $faker->randomElement(['retail', 'wholesale', 'online'])
                ]
            );

            $lastLogin = $faker->dateTimeBetween('-3 months', 'now');
            $user->setLastLoginAt(\DateTimeImmutable::createFromMutable($lastLogin));

            $manager->persist($user);
        }

        // ===============================================
        // UTILISATEURS AVEC ÉTATS SPÉCIAUX (pour tests)
        // ===============================================

        // Utilisateur non vérifié
        $unverifiedUser = $this->createUser(
            site: $siteFr,
            email: 'nonverifie@test.fr',
            password: 'Test123!',
            firstName: 'Test',
            lastName: 'NonVerifie',
            roles: [UserRole::ROLE_USER],
            isVerified: false
        );
        $unverifiedUser->setVerificationToken(bin2hex(random_bytes(32)));
        $manager->persist($unverifiedUser);

        // Utilisateur banni
        $bannedUser = $this->createUser(
            site: $siteFr,
            email: 'banni@test.fr',
            password: 'Test123!',
            firstName: 'Test',
            lastName: 'Banni',
            roles: [UserRole::ROLE_USER],
            isVerified: true
        );
        $bannedUser->ban(); // Désactivé via ActiveStateTrait
        $manager->persist($bannedUser);

        // Utilisateur inactif (dernière connexion > 1 an)
        $inactiveUser = $this->createUser(
            site: $siteFr,
            email: 'inactif@test.fr',
            password: 'Test123!',
            firstName: 'Test',
            lastName: 'Inactif',
            roles: [UserRole::ROLE_USER],
            isVerified: true
        );
        $oldLogin = new \DateTimeImmutable('-400 days');
        $inactiveUser->setLastLoginAt($oldLogin);
        $manager->persist($inactiveUser);

        $manager->flush();
    }

    /**
     * Helper pour créer un utilisateur avec moins de duplication.
     */
    private function createUser(
        Site $site,
        string $email,
        string $password,
        string $firstName,
        string $lastName,
        array $roles,
        bool $isVerified,
        ?string $phone = null,
        ?\DateTime $birthDate = null,
        bool $newsletterOptIn = false,
        ?array $metadata = null
    ): User {
        $user = new User();
        $user
            ->setSite($site)
            ->setEmail($email)
            ->setPassword($this->passwordHasher->hashPassword($user, $password))
            ->setFirstName($firstName)
            ->setLastName($lastName)
            ->setRoles(array_map(fn($role) => $role->value, $roles))
            ->setIsVerified($isVerified)
            ->setNewsletterOptIn($newsletterOptIn);

        if ($phone) {
            $user->setPhone($phone);
        }

        if ($birthDate) {
            $user->setBirthDate(\DateTimeImmutable::createFromMutable($birthDate));
        }

        if ($metadata) {
            $user->setMetadata($metadata);
        }

        return $user;
    }

    /**
     * Dépendances : Les fixtures User nécessitent les fixtures Site.
     */
    public function getDependencies(): array
    {
        return [
            SiteFixtures::class,
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\DataFixtures\User;

use App\Entity\User\User;
use App\Entity\User\Address;
use App\Enum\User\AddressType;
use Faker\Factory as FakerFactory;
use App\DataFixtures\User\UserFixtures;
use Doctrine\Persistence\ObjectManager;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;

/**
 * Fixtures pour l'entité Address.
 * 
 * Génère :
 * - 2-3 adresses par utilisateur (domicile, travail, parents...)
 * - 1 adresse par défaut par utilisateur
 * - Mix de types : facturation, livraison, les deux
 */
class AddressFixtures extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        $faker = FakerFactory::create('fr_FR');

        // Récupérer quelques utilisateurs de test
        /** @var User $superAdmin */
        $superAdmin = $this->getReference(UserFixtures::USER_SUPER_ADMIN, User::class);
        /** @var User $adminFr */
        $adminFr = $this->getReference(UserFixtures::USER_ADMIN_FR, User::class);
        /** @var User $clientFr */
        $clientFr = $this->getReference(UserFixtures::USER_CLIENT_FR_1, User::class);

        // ===============================================
        // ADRESSES POUR SUPER ADMIN
        // ===============================================
        $this->createAddress(
            manager: $manager,
            user: $superAdmin,
            fullName: $superAdmin->getFullName(),
            company: 'Boutique Bio Siège',
            street: '123 Avenue des Champs-Élysées',
            postalCode: '75008',
            city: 'Paris',
            countryCode: 'FR',
            phone: '+33 1 23 45 67 89',
            type: AddressType::BOTH,
            isDefault: true,
            label: 'Bureau principal'
        );

        // ===============================================
        // ADRESSES POUR ADMIN FR
        // ===============================================
        $this->createAddress(
            manager: $manager,
            user: $adminFr,
            fullName: $adminFr->getFullName(),
            street: $faker->streetAddress(),
            postalCode: $faker->postcode(),
            city: $faker->city(),
            countryCode: 'FR',
            phone: $faker->phoneNumber(),
            type: AddressType::BOTH,
            isDefault: true,
            label: 'Domicile'
        );

        $this->createAddress(
            manager: $manager,
            user: $adminFr,
            fullName: $adminFr->getFullName(),
            company: $faker->company(),
            street: $faker->streetAddress(),
            postalCode: $faker->postcode(),
            city: $faker->city(),
            countryCode: 'FR',
            phone: $faker->phoneNumber(),
            type: AddressType::BILLING,
            isDefault: false,
            label: 'Bureau'
        );

        // ===============================================
        // ADRESSES POUR CLIENT TEST
        // ===============================================
        $this->createAddress(
            manager: $manager,
            user: $clientFr,
            fullName: $clientFr->getFullName(),
            street: $faker->streetAddress(),
            additionalAddress: 'Appartement ' . $faker->numberBetween(1, 50),
            postalCode: $faker->postcode(),
            city: $faker->city(),
            countryCode: 'FR',
            phone: $faker->phoneNumber(),
            type: AddressType::BOTH,
            isDefault: true,
            label: 'Domicile',
            deliveryInstructions: 'Code portail : ' . $faker->numberBetween(1000, 9999)
        );

        $this->createAddress(
            manager: $manager,
            user: $clientFr,
            fullName: 'Famille ' . $clientFr->getLastName(),
            street: $faker->streetAddress(),
            postalCode: $faker->postcode(),
            city: $faker->city(),
            countryCode: 'FR',
            phone: $faker->phoneNumber(),
            type: AddressType::SHIPPING,
            isDefault: false,
            label: 'Chez mes parents'
        );

        // ===============================================
        // ADRESSES ALÉATOIRES POUR 10 AUTRES USERS
        // ===============================================
        for ($i = 2; $i <= 10; $i++) {
            try {
                /** @var User $user */
                $user = $this->getReference('user_client_fr_' . $i, User::class);
            } catch (\Exception $e) {
                continue; // Passer si la référence n'existe pas
            }

            // 2-3 adresses par utilisateur
            $nbAddresses = $faker->numberBetween(2, 3);

            for ($j = 0; $j < $nbAddresses; $j++) {
                $types = [AddressType::BILLING, AddressType::SHIPPING, AddressType::BOTH];
                $labels = ['Domicile', 'Bureau', 'Parents', 'Résidence secondaire', 'Point relais'];

                $this->createAddress(
                    manager: $manager,
                    user: $user,
                    fullName: $user->getFullName(),
                    company: $j === 1 ? $faker->company() : null,
                    street: $faker->streetAddress(),
                    additionalAddress: $faker->boolean(30) ? $faker->secondaryAddress() : null,
                    postalCode: $faker->postcode(),
                    city: $faker->city(),
                    countryCode: $faker->randomElement(['FR', 'FR', 'FR', 'BE']), // 75% France, 25% Belgique
                    phone: $faker->phoneNumber(),
                    type: $faker->randomElement($types),
                    isDefault: $j === 0, // Première adresse = par défaut
                    label: $faker->randomElement($labels),
                    deliveryInstructions: $faker->boolean(20) ? $faker->sentence() : null
                );
            }
        }

        $manager->flush();
    }

    /**
     * Helper pour créer une adresse.
     */
    private function createAddress(
        ObjectManager $manager,
        User $user,
        string $fullName,
        string $street,
        string $postalCode,
        string $city,
        string $countryCode,
        string $phone,
        AddressType $type,
        bool $isDefault,
        ?string $company = null,
        ?string $additionalAddress = null,
        ?string $label = null,
        ?string $deliveryInstructions = null
    ): Address {
        $address = new Address();
        $address
            ->setUser($user)
            ->setFullName($fullName)
            ->setCompany($company)
            ->setStreet($street)
            ->setAdditionalAddress($additionalAddress)
            ->setPostalCode($postalCode)
            ->setCity($city)
            ->setCountryCode($countryCode)
            ->setPhone($phone)
            ->setType($type)
            ->setIsDefault($isDefault)
            ->setLabel($label)
            ->setDeliveryInstructions($deliveryInstructions);

        $manager->persist($address);

        return $address;
    }

    /**
     * Dépendances : Les fixtures Address nécessitent les fixtures User.
     */
    public function getDependencies(): array
    {
        return [
            UserFixtures::class,
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\DataFixtures\Order;

use App\Entity\Site\Site;
use App\Entity\User\User;
use App\Entity\Cart\Coupon;
use App\Entity\Order\Order;
use App\Enum\Order\OrderStatus;
use Faker\Factory as FakerFactory;
use App\DataFixtures\Site\SiteFixtures;
use App\DataFixtures\User\UserFixtures;
use Doctrine\Persistence\ObjectManager;
use App\Repository\Order\OrderRepository;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;

class OrderFixtures extends Fixture implements DependentFixtureInterface
{
    // Références publiques si tu veux en cibler certaines ensuite
    public const ORDER_EXAMPLE_1 = 'order_example_1';
    public const ORDER_EXAMPLE_2 = 'order_example_2';

    public function load(ObjectManager $manager): void
    {
        $faker = FakerFactory::create('fr_FR');

        /** @var OrderRepository $orderRepo */
        $orderRepo = $manager->getRepository(Order::class);

        // Références (si présentes)
        /** @var User|null $superAdmin */
        $superAdmin = $this->getRef(UserFixtures::USER_SUPER_ADMIN, $manager, User::class);
        /** @var User|null $adminFr */
        $adminFr = $this->getRef(UserFixtures::USER_ADMIN_FR, $manager, User::class);
        /** @var User|null $clientFr */
        $clientFr = $this->getRef(UserFixtures::USER_CLIENT_FR_1, $manager, User::class);

        /** @var Site|null $mainSite */
        $mainSite = $this->resolveSite($manager);

        /** @var Coupon|null $welcomeCoupon */
        $welcomeCoupon = $this->getRef('coupon_welcome_10', $manager, Coupon::class);

        // ---------- Commandes pilotes sans items ----------
        if ($superAdmin) {
            $o1 = $this->createOrder(
                manager: $manager,
                orderRepo: $orderRepo,
                user: $superAdmin,
                site: $mainSite,
                currency: 'EUR',
                locale: 'fr',
                customerType: 'B2C',
                shippingCost: 4.90,
                status: OrderStatus::CONFIRMED,
                createdAt: (new \DateTimeImmutable('first day of this month'))->setTime(10, 15),
                coupon: $welcomeCoupon
            );
            $this->addReference(self::ORDER_EXAMPLE_1, $o1);
        }

        if ($adminFr) {
            $o2 = $this->createOrder(
                manager: $manager,
                orderRepo: $orderRepo,
                user: $adminFr,
                site: $mainSite,
                currency: 'EUR',
                locale: 'fr',
                customerType: 'B2C',
                shippingCost: 3.90,
                status: OrderStatus::PROCESSING,
                createdAt: (new \DateTimeImmutable('first day of this month + 3 days'))->setTime(14, 22),
                coupon: null
            );
            $this->addReference(self::ORDER_EXAMPLE_2, $o2);
        }

        if ($clientFr) {
            for ($i = 0; $i < 2; $i++) {
                $this->createOrder(
                    manager: $manager,
                    orderRepo: $orderRepo,
                    user: $clientFr,
                    site: $mainSite,
                    currency: 'EUR',
                    locale: $faker->randomElement(['fr', 'en']),
                    customerType: $faker->randomElement(['B2C', 'B2B']),
                    shippingCost: $faker->randomElement([0.00, 3.90, 4.90, 6.90]),
                    status: $faker->randomElement([
                        OrderStatus::PENDING,
                        OrderStatus::CONFIRMED,
                        OrderStatus::PROCESSING,
                        OrderStatus::SHIPPED,
                        OrderStatus::DELIVERED
                    ]),
                    createdAt: (new \DateTimeImmutable(sprintf('-%d days', $faker->numberBetween(1, 25))))
                        ->setTime($faker->numberBetween(8, 20), $faker->numberBetween(0, 59)),
                    coupon: $faker->boolean(20) ? $welcomeCoupon : null
                );
            }
        }

        // ---------- Quelques commandes pour d'autres users ----------
        for ($i = 2; $i <= 10; $i++) {
            /** @var User|null $user */
            $user = $this->getRef('user_client_fr_' . $i, $manager, User::class);
            if (!$user) {
                continue;
            }

            $count = $faker->numberBetween(1, 3);
            for ($j = 0; $j < $count; $j++) {
                $this->createOrder(
                    manager: $manager,
                    orderRepo: $orderRepo,
                    user: $user,
                    site: $mainSite,
                    currency: 'EUR',
                    locale: $faker->randomElement(['fr', 'en']),
                    customerType: $faker->randomElement(['B2C', 'B2B']),
                    shippingCost: $faker->randomElement([0.00, 3.90, 4.90, 6.90, 9.90]),
                    status: $faker->randomElement([
                        OrderStatus::PENDING,
                        OrderStatus::CONFIRMED,
                        OrderStatus::PROCESSING,
                        OrderStatus::SHIPPED,
                        OrderStatus::DELIVERED,
                        OrderStatus::CANCELLED,
                    ]),
                    createdAt: (new \DateTimeImmutable(sprintf('-%d days', $faker->numberBetween(1, 50))))
                        ->setTime($faker->numberBetween(8, 20), $faker->numberBetween(0, 59)),
                    coupon: $faker->boolean(15) ? $welcomeCoupon : null
                );
            }
        }

        $manager->flush();
    }

    private function resolveSite(\Doctrine\Persistence\ObjectManager $manager): \App\Entity\Site\Site
    {
        // 1) Essayer des références usuelles si ta SiteFixtures en définit
        $refNames = [
            'site_main',
            'site_default',
            'site_fr',
        ];

        // Si ta SiteFixtures expose des constantes, on tente aussi
        if (class_exists(\App\DataFixtures\Site\SiteFixtures::class)) {
            foreach (['SITE_MAIN', 'SITE_DEFAULT', 'SITE_FR'] as $const) {
                if (defined(\App\DataFixtures\Site\SiteFixtures::class . '::' . $const)) {
                    $refNames[] = constant(\App\DataFixtures\Site\SiteFixtures::class . '::' . $const);
                }
            }
        }

        foreach ($refNames as $name) {
            try {
                /** @var \App\Entity\Site\Site $s */
                $s = $this->getReference($name, \App\Entity\Site\Site::class);
                if ($s) {
                    return $s;
                }
            } catch (\Throwable) {
                // on continue
            }
        }

        // 2) Fallback : prendre le premier Site en base
        $site = $manager->getRepository(\App\Entity\Site\Site::class)->findOneBy([]);
        if ($site) {
            return $site;
        }

        // 3) Rien trouvé → on plante avec un message clair
        throw new \RuntimeException("Aucun Site trouvé. Assure-toi que SiteFixtures s’exécute avant OrderFixtures et qu’au moins un Site existe.");
    }

    private function createOrder(
        ObjectManager $manager,
        OrderRepository $orderRepo,
        User $user,
        Site $site,
        string $currency,
        string $locale,
        string $customerType,
        float $shippingCost,
        OrderStatus $status,
        \DateTimeImmutable $createdAt,
        ?Coupon $coupon
    ): Order {
        $order = new Order();

        $reference = method_exists($orderRepo, 'generateUniqueReference')
            ? $orderRepo->generateUniqueReference()
            : $this->fallbackReference();

        $order
            ->setReference($reference)
            ->setUser($user)
            ->setCurrency($currency)
            ->setLocale($locale)
            ->setSite($site)
            ->setCustomerType($customerType)
            ->setShippingCost($shippingCost)
            ->setTaxRate(20.0)
            ->setDiscountAmount(0.0)   // totaux corrigés plus tard par OrderItemFixtures
            ->setSubtotal(0.0)
            ->setTaxAmount(0.0)
            ->setGrandTotal($shippingCost); // provisoire : items mettront à jour

        if ($site && method_exists($order, 'setSite')) {
            $order->setSite($site);
        }

        // Snapshots d’adresse + client (faker minimal)
        $order->setShippingAddress($this->fakeAddressSnapshot($user));
        $order->setBillingAddress($this->fakeAddressSnapshot($user));
        $order->setCustomerSnapshot($this->fakeCustomerSnapshot($user));

        if ($coupon) {
            $order->setCoupon($coupon);
            $order->setAppliedCoupon([
                'code'        => $coupon->getCode(),
                'type'        => $coupon->getType(),
                'value'       => $coupon->getValue(),
                'description' => $coupon->getDescription(),
            ]);
        }

        // createdAt (via réflexion si DateTrait protège la prop)
        if (property_exists($order, 'createdAt')) {
            $rp = new \ReflectionProperty($order, 'createdAt');
            $rp->setAccessible(true);
            $rp->setValue($order, $createdAt);
        }

        // Respecter la FSM : on avance par étapes
        if ($status !== OrderStatus::PENDING) {
            $this->moveToStatus($order, $status);
        }

        $manager->persist($order);
        $manager->flush();
        return $order;
    }

    private function moveToStatus(\App\Entity\Order\Order $order, \App\Enum\Order\OrderStatus $target): void
    {
        $steps = match ($target) {
            \App\Enum\Order\OrderStatus::PENDING    => [],
            \App\Enum\Order\OrderStatus::CONFIRMED  => [\App\Enum\Order\OrderStatus::CONFIRMED],
            \App\Enum\Order\OrderStatus::PROCESSING => [\App\Enum\Order\OrderStatus::CONFIRMED, \App\Enum\Order\OrderStatus::PROCESSING],
            \App\Enum\Order\OrderStatus::SHIPPED    => [\App\Enum\Order\OrderStatus::CONFIRMED, \App\Enum\Order\OrderStatus::PROCESSING, \App\Enum\Order\OrderStatus::SHIPPED],
            \App\Enum\Order\OrderStatus::DELIVERED  => [\App\Enum\Order\OrderStatus::CONFIRMED, \App\Enum\Order\OrderStatus::PROCESSING, \App\Enum\Order\OrderStatus::SHIPPED, \App\Enum\Order\OrderStatus::DELIVERED],
            \App\Enum\Order\OrderStatus::ON_HOLD    => [\App\Enum\Order\OrderStatus::CONFIRMED, \App\Enum\Order\OrderStatus::ON_HOLD],
            \App\Enum\Order\OrderStatus::CANCELLED  => [\App\Enum\Order\OrderStatus::CANCELLED],
            \App\Enum\Order\OrderStatus::REFUNDED   => [\App\Enum\Order\OrderStatus::CONFIRMED, \App\Enum\Order\OrderStatus::REFUNDED],
        };

        foreach ($steps as $step) {
            if ($order->getStatus()->canTransitionTo($step)) {
                $order->changeStatus($step, null, 'system', 'Fixture status');
            } else {
                break;
            }
        }
    }


    private function fakeAddressSnapshot(User $user): array
    {
        $faker = FakerFactory::create('fr_FR');
        return [
            'firstName'         => $user->getFirstName() ?: $faker->firstName(),
            'lastName'          => $user->getLastName() ?: $faker->lastName(),
            'company'           => $faker->boolean(30) ? $faker->company() : null,
            'street'            => $faker->streetAddress(),
            'additionalAddress' => $faker->boolean(30) ? $faker->secondaryAddress() : null,
            'postalCode'        => $faker->postcode(),
            'city'              => $faker->city(),
            'countryCode'       => $faker->randomElement(['FR', 'FR', 'FR', 'BE']),
            'phone'             => $faker->phoneNumber(),
        ];
    }

    private function fakeCustomerSnapshot(User $user): array
    {
        return [
            'email'     => $user->getEmail(),
            'firstName' => $user->getFirstName(),
            'lastName'  => $user->getLastName(),
            'phone'     => method_exists($user, 'getPhone') ? $user->getPhone() : null,
            'isGuest'   => false,
        ];
    }

    private function fallbackReference(): string
    {
        $ym = (new \DateTimeImmutable())->format('Y-m');
        $rand = str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT);
        return sprintf('%s-%s', $ym, $rand);
    }

    /**
     * Récupère une référence si elle existe ; sinon null.
     * @template T of object
     * @param class-string<T> $class
     * @return T|null
     */
    private function getRef(string $name, ObjectManager $manager, string $class): ?object
    {
        try {
            /** @var T $obj */
            $obj = $this->getReference($name, $class);
            return $obj;
        } catch (\Throwable) {
            return null;
        }
    }

    public function getDependencies(): array
    {
        return [
            \App\DataFixtures\User\UserFixtures::class,
            \App\DataFixtures\Site\SiteFixtures::class, 
            \App\DataFixtures\Cart\CouponFixtures::class,
        ];
    }
}

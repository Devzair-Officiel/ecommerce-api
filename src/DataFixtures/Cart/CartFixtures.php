<?php

declare(strict_types=1);

namespace App\DataFixtures\Cart;

use App\Entity\Cart\Cart;
use App\Entity\Site\Site;
use App\Entity\User\User;
use App\Entity\Cart\Coupon;
use App\Entity\Cart\CartItem;
use Faker\Factory as FakerFactory;
use App\Entity\Product\ProductVariant;
use App\DataFixtures\Site\SiteFixtures;
use App\DataFixtures\User\UserFixtures;
use Doctrine\Persistence\ObjectManager;
use Doctrine\Bundle\FixturesBundle\Fixture;
use App\DataFixtures\Product\ProductFixtures;
use App\DataFixtures\Product\ProductVariantFixtures;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;

/**
 * Fixtures pour Cart et CartItem.
 * 
 * Génère :
 * - 10 paniers invités (sessionToken)
 * - 15 paniers utilisateurs actifs
 * - 5 paniers abandonnés (expirés)
 * - Variété de produits (2-5 items par panier)
 * - Quelques paniers avec coupons appliqués
 * 
 * Améliorations 2025 :
 * ✅ Correction des dépendances (CouponFixtures existe)
 * ✅ Meilleure gestion des références avec typage fort
 * ✅ Commentaires explicatifs pour les scénarios de test
 * ✅ Gestion des erreurs robuste
 */
class CartFixtures extends Fixture implements DependentFixtureInterface
{
    public const CART_GUEST_1 = 'cart_guest_1';
    public const CART_USER_1 = 'cart_user_1';

    public function load(ObjectManager $manager): void
    {
        $faker = FakerFactory::create('fr_FR');

        /** @var Site $siteFr */
        $siteFr = $this->getReference(SiteFixtures::SITE_FR_REFERENCE, Site::class);

        // Récupérer quelques coupons (✅ CouponFixtures existe maintenant)
        /** @var Coupon $couponWelcome */
        $couponWelcome = $this->getReference(CouponFixtures::COUPON_WELCOME, Coupon::class);
        /** @var Coupon $couponFreeShip */
        $couponFreeShip = $this->getReference(CouponFixtures::COUPON_FREE_SHIPPING, Coupon::class);

        // ===============================================
        // PANIERS INVITÉS (sessionToken)
        // ===============================================
        for ($i = 1; $i <= 10; $i++) {
            $cart = $this->createGuestCart($siteFr, $faker);
            $manager->persist($cart);

            // Ajouter 2-4 produits
            $nbItems = $faker->numberBetween(2, 4);
            for ($j = 1; $j <= $nbItems; $j++) {
                $item = $this->createCartItem($cart, $faker);
                $manager->persist($item);
            }

            // Premier panier invité avec coupon
            if ($i === 1) {
                $cart->setCoupon($couponWelcome);
                $this->addReference(self::CART_GUEST_1, $cart);
            }
        }

        // ===============================================
        // PANIERS UTILISATEURS ACTIFS
        // ===============================================
        // Architecture : OneToOne entre User et Cart
        // → UN SEUL cart actif par utilisateur

        // Récupérer les utilisateurs disponibles (sans doublon)
        $availableUsers = $this->getAvailableUsers(15); // Récupérer 15 users uniques

        foreach ($availableUsers as $index => $user) {
            $cart = $this->createUserCart($siteFr, $user, $faker);
            $manager->persist($cart);

            // Ajouter 1-5 produits
            $nbItems = $faker->numberBetween(1, 5);
            for ($j = 1; $j <= $nbItems; $j++) {
                $item = $this->createCartItem($cart, $faker);
                $manager->persist($item);
            }

            // Quelques paniers avec coupons (les 3 premiers)
            if ($index < 3) {
                $cart->setCoupon($faker->randomElement([$couponWelcome, $couponFreeShip, null]));
            }

            // Référence pour le premier cart utilisateur
            if ($index === 0) {
                $this->addReference(self::CART_USER_1, $cart);
            }
        }

        // ===============================================
        // PANIERS ABANDONNÉS (expirés) - Scénario de test
        // ===============================================
        for ($i = 1; $i <= 5; $i++) {
            $cart = $this->createGuestCart($siteFr, $faker);

            // Expiration passée (simuler un abandon de panier réaliste)
            $cart->setExpiresAt(new \DateTimeImmutable('-' . $faker->numberBetween(1, 10) . ' days'));
            $cart->setLastActivityAt(new \DateTimeImmutable('-' . $faker->numberBetween(8, 30) . ' days'));

            $manager->persist($cart);

            // Ajouter quelques produits
            $nbItems = $faker->numberBetween(2, 3);
            for ($j = 1; $j <= $nbItems; $j++) {
                $item = $this->createCartItem($cart, $faker);
                $manager->persist($item);
            }
        }

        // ===============================================
        // PANIERS VIDES (pour tests UI)
        // ===============================================
        for ($i = 1; $i <= 3; $i++) {
            $cart = $this->createGuestCart($siteFr, $faker);
            $manager->persist($cart);
            // Pas d'items → panier vide pour tester l'affichage
        }

        $manager->flush();
    }

    /**
     * Crée un panier invité avec sessionToken.
     * 
     * Utilise UUID v4 pour garantir l'unicité du token de session.
     */
    private function createGuestCart(Site $site, $faker): Cart
    {
        $cart = new Cart();

        // Générer sessionToken (UUID v4)
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        $sessionToken = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));

        $cart
            ->setSite($site)
            ->setSessionToken($sessionToken)
            ->setCurrency('EUR')
            ->setLocale('fr')
            ->setCustomerType('B2C')
            ->setLastActivityAt(new \DateTimeImmutable('-' . $faker->numberBetween(1, 48) . ' hours'))
            ->setMetadata([
                'utm_source' => $faker->randomElement(['google', 'facebook', 'email', 'direct']),
                'device_type' => $faker->randomElement(['mobile', 'desktop', 'tablet']),
                'ip_address' => $faker->ipv4()
            ]);

        // Recalculer automatiquement l'expiration
        $cart->recalculateExpiration();

        return $cart;
    }

    /**
     * Crée un panier utilisateur.
     */
    private function createUserCart(Site $site, User $user, $faker): Cart
    {
        $cart = new Cart();

        $cart
            ->setSite($site)
            ->setUser($user)
            ->setCurrency('EUR')
            ->setLocale('fr')
            ->setCustomerType($faker->randomElement(['B2C', 'B2B']))
            ->setLastActivityAt(new \DateTimeImmutable('-' . $faker->numberBetween(1, 72) . ' hours'))
            ->setMetadata([
                'utm_source' => $faker->randomElement(['google', 'facebook', 'email', 'direct']),
                'device_type' => $faker->randomElement(['mobile', 'desktop', 'tablet'])
            ]);

        $cart->recalculateExpiration();

        return $cart;
    }

    /**
     * Crée un item de panier avec variante aléatoire.
     * 
     * Gestion robuste des références avec fallback sur la première variante disponible.
     */
    private function createCartItem(Cart $cart, $faker): CartItem
    {
        // Récupérer une variante aléatoire avec la bonne nomenclature
        $variant = $this->getRandomVariant($faker);

        $product = $variant->getProduct();
        $quantity = $faker->numberBetween(1, 5);

        // Récupérer le prix
        $price = $variant->getPriceFor($cart->getCurrency(), $cart->getCustomerType(), $quantity);

        // Calculer économies si tarif dégressif
        $savings = null;
        $prices = $variant->getPrices();
        $currency = $cart->getCurrency();
        $customerType = $cart->getCustomerType();

        if (isset($prices[$currency][$customerType]['quantity_tiers']) && $quantity >= 5) {
            $basePrice = $prices[$currency][$customerType]['base'];
            $savings = ($basePrice - $price) * $quantity;
        }

        $item = new CartItem();
        $item
            ->setCart($cart)
            ->setVariant($variant)
            ->setProduct($product)
            ->setQuantity($quantity)
            ->setPriceAtAdd($price)
            ->setSavingsAtAdd($savings)
            ->setCustomMessage($faker->optional(0.2)->sentence()); // 20% ont un message

        // Créer snapshot (capture l'état actuel du produit)
        $item->createSnapshot();

        return $item;
    }

    /**
     * Définit les dépendances des fixtures.
     */
    public function getDependencies(): array
    {
        return [
            SiteFixtures::class,
            UserFixtures::class,
            ProductFixtures::class,
            CouponFixtures::class, // ✅ Dépendance correcte
        ];
    }

    /**
     * Récupère une variante aléatoire depuis les fixtures ProductVariant.
     * 
     * Stratégie :
     * - Essaie de récupérer des variantes fr (variant_fr_honey_flowers_250g, etc.)
     * - Si non trouvé, utilise un fallback sur la première variante disponible
     * 
     * @throws \RuntimeException Si aucune variante n'est trouvée après plusieurs tentatives
     */
    private function getRandomVariant($faker): ProductVariant
    {
        // Liste des produits et leurs variantes possibles (selon ProductVariantFixtures)
        $productKeys = [
            'honey_flowers',
            'honey_acacia',
            'honey_lavender',
            'oil_olive',
            'oil_coconut',
            'syrup_agave',
            'jam_apricot',
            'tea_green',
            'cream_face',
            'lotion_body',
            'shampoo_solid',
            'essential_lavender',
            'supplement_vitamin',
            'herbal_chamomile',
            'diffuser_essential',
        ];

        $variantSuffixes = ['_250g', '_500g', '_1kg', '_250ml', '_500ml', '_1l', '_220g', '_370g', '_50g', '_100g'];

        // Tenter de récupérer une variante aléatoire
        $attempts = 0;
        $maxAttempts = 50;

        while ($attempts < $maxAttempts) {
            $attempts++;

            $productKey = $faker->randomElement($productKeys);
            $suffix = $faker->randomElement($variantSuffixes);

            // Essayer avec locale 'fr'
            $variantRef = ProductVariantFixtures::VARIANT_REFERENCE_PREFIX . 'fr_' . $productKey . $suffix;

            try {
                if ($this->hasReference($variantRef, ProductVariant::class)) {
                    return $this->getReference($variantRef, ProductVariant::class);
                }
            } catch (\Exception $e) {
                // Continuer la recherche
            }

            // Essayer sans suffix (juste le produit)
            $variantRef = ProductVariantFixtures::VARIANT_REFERENCE_PREFIX . 'fr_' . $productKey;
            try {
                if ($this->hasReference($variantRef, ProductVariant::class)) {
                    return $this->getReference($variantRef, ProductVariant::class);
                }
            } catch (\Exception $e) {
                // Continuer la recherche
            }
        }

        // Fallback : utiliser la première variante disponible (miel de fleurs 250g)
        try {
            $fallbackRef = ProductVariantFixtures::VARIANT_REFERENCE_PREFIX . 'fr_honey_flowers_250g';
            if ($this->hasReference($fallbackRef, ProductVariant::class)) {
                return $this->getReference($fallbackRef, ProductVariant::class);
            }
        } catch (\Exception $e) {
            // Dernier recours : exception explicite
            throw new \RuntimeException(
                'Impossible de trouver des variantes de produits. '
                    . 'Vérifiez que ProductVariantFixtures a été chargé correctement avec les bonnes références. '
                    . 'Attendu : variant_fr_honey_flowers_250g, variant_fr_oil_olive_250ml, etc.'
            );
        }

        throw new \RuntimeException('Aucune variante de produit trouvée après ' . $maxAttempts . ' tentatives.');
    }

    /**
     * Récupère une liste d'utilisateurs uniques pour les carts.
     * 
     * IMPORTANT : Contrainte OneToOne entre User et Cart
     * → Un utilisateur ne peut avoir qu'UN SEUL cart actif
     * → Cette méthode garantit qu'on ne retourne jamais le même user deux fois
     * 
     * @param int $count Nombre d'utilisateurs à récupérer
     * @return User[] Tableau d'utilisateurs uniques
     */
    private function getAvailableUsers(int $count): array
    {
        $users = [];

        // Tentative de récupération des utilisateurs
        // On essaie user_client_fr_1, user_client_fr_2, etc.
        for ($i = 1; $i <= 100; $i++) {
            if (count($users) >= $count) {
                break; // On a assez d'utilisateurs
            }

            $userRef = 'user_client_fr_' . $i;

            try {
                if ($this->hasReference($userRef, User::class)) {
                    $user = $this->getReference($userRef, User::class);
                    $users[] = $user;
                }
            } catch (\Exception $e) {
                // Utilisateur non trouvé, on continue
                continue;
            }
        }

        // Si on n'a pas assez d'utilisateurs, utiliser USER_CLIENT_FR_1 en fallback
        if (empty($users)) {
            try {
                $user = $this->getReference(UserFixtures::USER_CLIENT_FR_1, User::class);
                $users[] = $user;
            } catch (\Exception $e) {
                throw new \RuntimeException(
                    'Impossible de créer des carts utilisateurs : aucun utilisateur trouvé dans les fixtures.'
                );
            }
        }

        // Mélanger pour avoir de la variété
        shuffle($users);

        // Retourner exactement $count utilisateurs (ou moins si pas assez)
        return array_slice($users, 0, $count);
    }
}

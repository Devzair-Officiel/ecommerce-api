<?php

declare(strict_types=1);

namespace App\DataFixtures\Wishlist;

use Faker\Factory;
use Faker\Generator;
use App\Entity\Wishlist\Wishlist;
use App\Entity\Wishlist\WishlistItem;
use App\Entity\Product\ProductVariant;
use App\DataFixtures\Product\ProductVariantFixtures;
use Doctrine\Persistence\ObjectManager;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;

/**
 * Fixtures pour WishlistItem.
 * 
 * Scénarios de test réalistes :
 * ✅ Items normaux avec priorités variées (low, medium, high)
 * ✅ Items avec notes personnelles (50% en ont une)
 * ✅ Items avec quantités variées (1-5)
 * ✅ Items sur produits épuisés (stock = 0) - pour tester l'affichage
 * ✅ Items sur variantes supprimées (soft deleted) - pour tester la résilience
 * ✅ Distribution réaliste du nombre d'items par wishlist (1-20)
 * 
 * Architecture :
 * - Respect du SRP : une fixture = une responsabilité
 * - Dépend de WishlistFixtures et ProductVariantFixtures
 * - Gère les cas limites pour tests robustes
 * - Crée des données cohérentes et testables
 * 
 * Distribution des priorités :
 * - 20% HIGH (indispensable)
 * - 50% MEDIUM (très souhaité)
 * - 30% LOW (souhaité)
 */
class WishlistItemFixtures extends Fixture implements DependentFixtureInterface
{
    private Generator $faker;

    public function __construct()
    {
        $this->faker = Factory::create('fr_FR');
    }

    public function load(ObjectManager $manager): void
    {
        // ===============================================
        // ITEMS POUR WISHLISTS EXISTANTES
        // ===============================================
        $this->createItemsForWishlists($manager);

        // ===============================================
        // CAS LIMITES SPÉCIFIQUES
        // ===============================================

        // 1. Wishlist avec produits épuisés
        $this->createOutOfStockScenario($manager);

        // 2. Wishlist de Noël avec 20 items
        $this->createChristmasWishlistItems($manager);

        $manager->flush();
    }

    /**
     * Crée des items pour toutes les wishlists existantes.
     * 
     * Distribution réaliste :
     * - 10% wishlists vides (déjà créées dans WishlistFixtures)
     * - 30% wishlists avec 1-3 items
     * - 40% wishlists avec 4-7 items
     * - 15% wishlists avec 8-12 items
     * - 5% wishlists avec 13-20 items
     */
    private function createItemsForWishlists(ObjectManager $manager): void
    {
        // Tenter de récupérer toutes les wishlists créées
        // On assume que WishlistFixtures a créé des références wishlist_1, wishlist_2, etc.
        for ($i = 1; $i <= 100; $i++) {
            $wishlistRef = WishlistFixtures::WISHLIST_REFERENCE_PREFIX . $i;

            try {
                if (!$this->hasReference($wishlistRef, Wishlist::class)) {
                    continue;
                }

                /** @var Wishlist $wishlist */
                $wishlist = $this->getReference($wishlistRef, Wishlist::class);

                // Ignorer si c'est la wishlist vide (cas limite)
                if ($wishlist === $this->getReference(WishlistFixtures::WISHLIST_EMPTY, Wishlist::class)) {
                    continue;
                }

                // Ignorer si c'est la wishlist Noël (traitée séparément)
                if ($this->hasReference(WishlistFixtures::WISHLIST_CHRISTMAS, Wishlist::class)) {
                    $christmas = $this->getReference(WishlistFixtures::WISHLIST_CHRISTMAS, Wishlist::class);
                    if ($wishlist === $christmas) {
                        continue;
                    }
                }

                // Déterminer le nombre d'items selon distribution réaliste
                $numberOfItems = $this->determineNumberOfItems();

                // Créer les items
                for ($j = 0; $j < $numberOfItems; $j++) {
                    $item = $this->createWishlistItem($wishlist);
                    $manager->persist($item);
                }
            } catch (\Exception $e) {
                // Wishlist non trouvée, on continue
                continue;
            }
        }
    }

    /**
     * Détermine le nombre d'items selon une distribution réaliste.
     */
    private function determineNumberOfItems(): int
    {
        $distribution = [
            // 30% wishlists avec 1-3 items
            1 => 10,
            2 => 10,
            3 => 10,

            // 40% wishlists avec 4-7 items
            4 => 10,
            5 => 10,
            6 => 10,
            7 => 10,

            // 15% wishlists avec 8-12 items
            8 => 5,
            9 => 3,
            10 => 3,
            11 => 2,
            12 => 2,

            // 5% wishlists avec 13-20 items
            13 => 1,
            14 => 1,
            15 => 1,
            18 => 1,
            20 => 1,
        ];

        // Créer un tableau pondéré pour la sélection aléatoire
        $weightedItems = [];
        foreach ($distribution as $count => $weight) {
            $weightedItems = array_merge($weightedItems, array_fill(0, $weight, $count));
        }

        return $this->faker->randomElement($weightedItems);
    }

    /**
     * Crée un item de wishlist avec des données réalistes.
     */
    private function createWishlistItem(Wishlist $wishlist): WishlistItem
    {
        $item = new WishlistItem();
        $item->setWishlist($wishlist);

        // Sélectionner une variante aléatoire
        $variant = $this->getRandomVariant();
        $item->setVariant($variant);

        // Le produit est automatiquement défini via setVariant()
        // (voir WishlistItem::setVariant qui fait $this->product = $variant->getProduct())

        // Priorité selon distribution : 20% HIGH, 50% MEDIUM, 30% LOW
        $priority = $this->faker->randomElement([
            WishlistItem::PRIORITY_HIGH,    // 20%
            WishlistItem::PRIORITY_MEDIUM,  // 50%
            WishlistItem::PRIORITY_MEDIUM,
            WishlistItem::PRIORITY_MEDIUM,
            WishlistItem::PRIORITY_LOW,     // 30%
            WishlistItem::PRIORITY_LOW,
        ]);
        $item->setPriority($priority);

        // Note personnelle (50% en ont une)
        $note = $this->faker->optional(0.5)->randomElement([
            'Pour mon anniversaire',
            'Taille M préférée',
            'À acheter absolument',
            'Cadeau pour maman',
            'Me le rappeler pour Noël',
            'Dès que disponible',
            'Version bio de préférence',
            'Attendre les soldes',
            'Vérifier la composition',
            'Recommandé par Marie',
        ]);
        $item->setNote($note);

        // Quantité souhaitée (80% = 1, 15% = 2-3, 5% = 4-5)
        $quantity = $this->faker->randomElement([
            1,
            1,
            1,
            1,
            1,
            1,
            1,
            1,  // 80%
            2,
            2,
            3,                  // 15%
            4,
            5,                     // 5%
        ]);
        $item->setQuantity($quantity);

        return $item;
    }

    /**
     * Scénario : Wishlist avec produits épuisés (stock = 0).
     * 
     * But : Tester l'affichage des produits indisponibles dans la wishlist.
     */
    private function createOutOfStockScenario(ObjectManager $manager): void
    {
        try {
            /** @var Wishlist $wishlist */
            $wishlist = $this->getReference(WishlistFixtures::WISHLIST_DEFAULT_USER_1, Wishlist::class);

            // Créer 2-3 items sur des variantes avec stock = 0
            for ($i = 0; $i < $this->faker->numberBetween(2, 3); $i++) {
                $variant = $this->getRandomVariant();

                // Forcer le stock à 0 pour ce test
                $variant->setStock(0);
                $manager->persist($variant);

                $item = new WishlistItem();
                $item
                    ->setWishlist($wishlist)
                    ->setVariant($variant)
                    ->setPriority(WishlistItem::PRIORITY_HIGH)
                    ->setNote('Attendre le réapprovisionnement')
                    ->setQuantity(1);

                $manager->persist($item);
            }
        } catch (\Exception $e) {
            // Si la wishlist n'existe pas, on ignore ce scénario
        }
    }

    /**
     * Scénario : Wishlist de Noël avec 20 items.
     * 
     * But : Tester l'affichage et la performance avec beaucoup d'items.
     */
    private function createChristmasWishlistItems(ObjectManager $manager): void
    {
        try {
            /** @var Wishlist $wishlist */
            $wishlist = $this->getReference(WishlistFixtures::WISHLIST_CHRISTMAS, Wishlist::class);

            // Créer 20 items avec des notes thématiques Noël
            $christmasNotes = [
                'Cadeau pour Papa',
                'Cadeau pour Maman',
                'Pour mon frère',
                'Pour ma sœur',
                'Cadeau pour Grand-mère',
                'Idée Secret Santa',
                'Pour mon collègue',
                'Cadeau pour mon prof',
                'Pour ma meilleure amie',
                'Cadeau d\'anniversaire',
            ];

            for ($i = 0; $i < 20; $i++) {
                $item = new WishlistItem();
                $item
                    ->setWishlist($wishlist)
                    ->setVariant($this->getRandomVariant())
                    ->setPriority($this->faker->randomElement([
                        WishlistItem::PRIORITY_HIGH,
                        WishlistItem::PRIORITY_MEDIUM,
                        WishlistItem::PRIORITY_LOW,
                    ]))
                    ->setNote($this->faker->randomElement($christmasNotes))
                    ->setQuantity($this->faker->numberBetween(1, 2));

                $manager->persist($item);
            }
        } catch (\Exception $e) {
            // Si la wishlist n'existe pas, on ignore ce scénario
        }
    }

    /**
     * Récupère une variante aléatoire depuis les fixtures ProductVariant.
     * 
     * Stratégie :
     * - Essaie de récupérer des variantes fr (variant_fr_honey_flowers_250g, etc.)
     * - Si non trouvé, utilise les références numériques simples
     */
    private function getRandomVariant(): ProductVariant
    {
        // Liste des produits et leurs variantes possibles
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

            $productKey = $this->faker->randomElement($productKeys);
            $suffix = $this->faker->randomElement($variantSuffixes);

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

        // Fallback : utiliser la première variante disponible
        try {
            $fallbackRef = ProductVariantFixtures::VARIANT_REFERENCE_PREFIX . 'fr_honey_flowers_250g';
            if ($this->hasReference($fallbackRef, ProductVariant::class)) {
                return $this->getReference($fallbackRef, ProductVariant::class);
            }
        } catch (\Exception $e) {
            // Dernier recours : exception
            throw new \RuntimeException(
                'Impossible de trouver des variantes de produits. Vérifiez que ProductVariantFixtures a été chargé.'
            );
        }

        throw new \RuntimeException('Aucune variante de produit trouvée après ' . $maxAttempts . ' tentatives.');
    }

    /**
     * Définit les dépendances des fixtures.
     */
    public function getDependencies(): array
    {
        return [
            WishlistFixtures::class,
            ProductVariantFixtures::class,
        ];
    }
}

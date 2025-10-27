<?php

declare(strict_types=1);

namespace App\DataFixtures\Product;

use Faker\Factory;
use Faker\Generator;
use App\Entity\Product\Product;
use App\Entity\Product\ProductVariant;
use Doctrine\Persistence\ObjectManager;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;

/**
 * Fixtures pour les variantes de produits.
 * 
 * Responsabilités :
 * - Créer plusieurs variantes (formats/volumes) par produit
 * - Définir les prix différenciés B2B/B2C avec tarifs dégressifs
 * - Gérer le stock et les seuils d'alerte
 * - Définir les caractéristiques physiques (poids, dimensions, EAN)
 * 
 * Architecture des prix :
 * {
 *   "EUR": {
 *     "B2C": { 
 *       "base": 12.90, 
 *       "quantity_tiers": [{"min": 5, "price": 11.90}, {"min": 10, "price": 10.90}]
 *     },
 *     "B2B": { 
 *       "base": 9.90, 
 *       "quantity_tiers": [{"min": 20, "price": 8.50}, {"min": 50, "price": 7.90}]
 *     }
 *   },
 *   "USD": { "B2C": { "base": 14.90 } },
 *   "GBP": { "B2C": { "base": 11.90 } }
 * }
 */
class ProductVariantFixtures extends Fixture implements DependentFixtureInterface
{
    public const VARIANT_REFERENCE_PREFIX = 'variant_';

    private Generator $faker;

    public function __construct()
    {
        $this->faker = Factory::create('fr_FR');
    }

    public function load(ObjectManager $manager): void
    {
        $locales = ['fr', 'en', 'es'];

        foreach ($locales as $locale) {
            $this->createVariantsForLocale($manager, $locale);
        }

        $manager->flush();
    }

    /**
     * Crée les variantes pour une locale spécifique.
     */
    private function createVariantsForLocale(ObjectManager $manager, string $locale): void
    {
        $products = $this->getProductReferencesForLocale($locale);

        foreach ($products as $productRefKey => $variantsData) {
            $productReference = ProductFixtures::PRODUCT_REFERENCE_PREFIX . $locale . '_' . $productRefKey;

            // ✅ FIX 1 : Ajout du deuxième paramètre $class pour hasReference()
            if (!$this->hasReference($productReference, Product::class)) {
                continue;
            }

            // ✅ FIX 2 : Ajout du deuxième paramètre $class pour getReference()
            /** @var Product $product */
            $product = $this->getReference($productReference, Product::class);

            $position = 0;
            foreach ($variantsData as $variantData) {
                $this->createVariant($manager, $product, $locale, $variantData, $position++);
            }
        }
    }

    /**
     * Crée une variante de produit.
     */
    private function createVariant(
        ObjectManager $manager,
        Product $product,
        string $locale,
        array $variantData,
        int $position
    ): ProductVariant {
        $variant = new ProductVariant();
        $variant->setProduct($product);
        $variant->setSku($variantData['sku']);
        $variant->setName($variantData['name']);
        $variant->setDescription($variantData['description'] ?? null);
        $variant->setPosition($position);

        // Prix différenciés multi-devises avec tarifs dégressifs
        $variant->setPrices($this->generatePrices($variantData['base_price_eur']));

        // Gestion du stock
        $variant->setStock($variantData['stock']);

        // ✅ FIX 3 : Correction de setStockAlert() en setLowStockThreshold()
        // L'entité ProductVariant utilise $lowStockThreshold, pas $stockAlert
        $variant->setLowStockThreshold($variantData['stock_alert'] ?? 10);

        // Caractéristiques physiques
        $variant->setWeight($variantData['weight']);
        $variant->setDimensions($this->generateDimensions($variantData['weight']));
        $variant->setEan($this->generateEAN());

        // État
        // ✅ FIX : isActive() RETOURNE un boolean, ne DÉFINIT PAS l'état
        // Pour activer : utiliser activate() (trait ActiveStateTrait)
        $variant->activate(); // Équivalent à setClosedAt(null)
        $variant->setIsDefault($variantData['is_default'] ?? false);

        $manager->persist($variant);

        // Créer une référence pour utilisation future
        $reference = self::VARIANT_REFERENCE_PREFIX . $locale . '_' . $variantData['ref_key'];
        $this->addReference($reference, $variant);

        return $variant;
    }

    /**
     * Définit les variantes pour chaque produit.
     * 
     * Chaque produit a plusieurs variantes (formats/volumes différents).
     * Les prix augmentent proportionnellement au poids mais avec économie d'échelle.
     */
    private function getProductReferencesForLocale(string $locale): array
    {
        // Structure commune pour toutes les locales (les noms seront traduits)
        return [
            // MIEL DE FLEURS - 3 formats
            'honey_flowers' => [
                [
                    'ref_key' => 'honey_flowers_250g',
                    'sku' => 'MIEL-FLEURS-BIO-250G',
                    'name' => $this->getTranslation('variant.honey.250g', $locale),
                    'base_price_eur' => 8.90,
                    'stock' => $this->faker->numberBetween(50, 200),
                    'stock_alert' => 20,
                    'weight' => 250,
                    'is_default' => true,
                ],
                [
                    'ref_key' => 'honey_flowers_500g',
                    'sku' => 'MIEL-FLEURS-BIO-500G',
                    'name' => $this->getTranslation('variant.honey.500g', $locale),
                    'base_price_eur' => 15.90,
                    'stock' => $this->faker->numberBetween(30, 150),
                    'stock_alert' => 15,
                    'weight' => 500,
                    'is_default' => false,
                ],
                [
                    'ref_key' => 'honey_flowers_1kg',
                    'sku' => 'MIEL-FLEURS-BIO-1KG',
                    'name' => $this->getTranslation('variant.honey.1kg', $locale),
                    'base_price_eur' => 28.90,
                    'stock' => $this->faker->numberBetween(20, 80),
                    'stock_alert' => 10,
                    'weight' => 1000,
                    'is_default' => false,
                ],
            ],

            // MIEL D'ACACIA - 3 formats
            'honey_acacia' => [
                [
                    'ref_key' => 'honey_acacia_250g',
                    'sku' => 'MIEL-ACACIA-BIO-250G',
                    'name' => $this->getTranslation('variant.honey.250g', $locale),
                    'base_price_eur' => 9.90,
                    'stock' => $this->faker->numberBetween(40, 180),
                    'weight' => 250,
                    'is_default' => true,
                ],
                [
                    'ref_key' => 'honey_acacia_500g',
                    'sku' => 'MIEL-ACACIA-BIO-500G',
                    'name' => $this->getTranslation('variant.honey.500g', $locale),
                    'base_price_eur' => 17.90,
                    'stock' => $this->faker->numberBetween(25, 120),
                    'weight' => 500,
                    'is_default' => false,
                ],
                [
                    'ref_key' => 'honey_acacia_1kg',
                    'sku' => 'MIEL-ACACIA-BIO-1KG',
                    'name' => $this->getTranslation('variant.honey.1kg', $locale),
                    'base_price_eur' => 32.90,
                    'stock' => $this->faker->numberBetween(15, 70),
                    'weight' => 1000,
                    'is_default' => false,
                ],
            ],

            // MIEL DE LAVANDE - 3 formats
            'honey_lavender' => [
                [
                    'ref_key' => 'honey_lavender_250g',
                    'sku' => 'MIEL-LAVANDE-BIO-250G',
                    'name' => $this->getTranslation('variant.honey.250g', $locale),
                    'base_price_eur' => 11.90,
                    'stock' => $this->faker->numberBetween(35, 160),
                    'weight' => 250,
                    'is_default' => true,
                ],
                [
                    'ref_key' => 'honey_lavender_500g',
                    'sku' => 'MIEL-LAVANDE-BIO-500G',
                    'name' => $this->getTranslation('variant.honey.500g', $locale),
                    'base_price_eur' => 21.90,
                    'stock' => $this->faker->numberBetween(20, 100),
                    'weight' => 500,
                    'is_default' => false,
                ],
                [
                    'ref_key' => 'honey_lavender_1kg',
                    'sku' => 'MIEL-LAVANDE-BIO-1KG',
                    'name' => $this->getTranslation('variant.honey.1kg', $locale),
                    'base_price_eur' => 39.90,
                    'stock' => $this->faker->numberBetween(10, 50),
                    'weight' => 1000,
                    'is_default' => false,
                ],
            ],

            // HUILE D'OLIVE - 3 formats
            'oil_olive' => [
                [
                    'ref_key' => 'oil_olive_250ml',
                    'sku' => 'HUILE-OLIVE-BIO-250ML',
                    'name' => $this->getTranslation('variant.oil.250ml', $locale),
                    'base_price_eur' => 7.90,
                    'stock' => $this->faker->numberBetween(60, 220),
                    'weight' => 250,
                    'is_default' => true,
                ],
                [
                    'ref_key' => 'oil_olive_500ml',
                    'sku' => 'HUILE-OLIVE-BIO-500ML',
                    'name' => $this->getTranslation('variant.oil.500ml', $locale),
                    'base_price_eur' => 13.90,
                    'stock' => $this->faker->numberBetween(40, 180),
                    'weight' => 500,
                    'is_default' => false,
                ],
                [
                    'ref_key' => 'oil_olive_1l',
                    'sku' => 'HUILE-OLIVE-BIO-1L',
                    'name' => $this->getTranslation('variant.oil.1l', $locale),
                    'base_price_eur' => 24.90,
                    'stock' => $this->faker->numberBetween(25, 100),
                    'weight' => 1000,
                    'is_default' => false,
                ],
            ],

            // HUILE DE COCO - 3 formats
            'oil_coconut' => [
                [
                    'ref_key' => 'oil_coconut_250ml',
                    'sku' => 'HUILE-COCO-BIO-250ML',
                    'name' => $this->getTranslation('variant.oil.250ml', $locale),
                    'base_price_eur' => 6.90,
                    'stock' => $this->faker->numberBetween(50, 200),
                    'weight' => 250,
                    'is_default' => true,
                ],
                [
                    'ref_key' => 'oil_coconut_500ml',
                    'sku' => 'HUILE-COCO-BIO-500ML',
                    'name' => $this->getTranslation('variant.oil.500ml', $locale),
                    'base_price_eur' => 11.90,
                    'stock' => $this->faker->numberBetween(35, 150),
                    'weight' => 500,
                    'is_default' => false,
                ],
                [
                    'ref_key' => 'oil_coconut_1l',
                    'sku' => 'HUILE-COCO-BIO-1L',
                    'name' => $this->getTranslation('variant.oil.1l', $locale),
                    'base_price_eur' => 21.90,
                    'stock' => $this->faker->numberBetween(20, 90),
                    'weight' => 1000,
                    'is_default' => false,
                ],
            ],

            // SIROP D'ÉRABLE - 2 formats
            'syrup_maple' => [
                [
                    'ref_key' => 'syrup_maple_250ml',
                    'sku' => 'SIROP-ERABLE-BIO-250ML',
                    'name' => $this->getTranslation('variant.syrup.250ml', $locale),
                    'base_price_eur' => 9.90,
                    'stock' => $this->faker->numberBetween(40, 150),
                    'weight' => 350,
                    'is_default' => true,
                ],
                [
                    'ref_key' => 'syrup_maple_500ml',
                    'sku' => 'SIROP-ERABLE-BIO-500ML',
                    'name' => $this->getTranslation('variant.syrup.500ml', $locale),
                    'base_price_eur' => 17.90,
                    'stock' => $this->faker->numberBetween(25, 100),
                    'weight' => 700,
                    'is_default' => false,
                ],
            ],

            // CONFITURE FRAISES - 2 formats
            'jam_strawberry' => [
                [
                    'ref_key' => 'jam_strawberry_220g',
                    'sku' => 'CONFITURE-FRAISE-BIO-220G',
                    'name' => $this->getTranslation('variant.jam.220g', $locale),
                    'base_price_eur' => 4.90,
                    'stock' => $this->faker->numberBetween(60, 200),
                    'weight' => 220,
                    'is_default' => true,
                ],
                [
                    'ref_key' => 'jam_strawberry_370g',
                    'sku' => 'CONFITURE-FRAISE-BIO-370G',
                    'name' => $this->getTranslation('variant.jam.370g', $locale),
                    'base_price_eur' => 7.90,
                    'stock' => $this->faker->numberBetween(40, 150),
                    'weight' => 370,
                    'is_default' => false,
                ],
            ],

            // THÉ VERT - 2 formats
            'tea_green' => [
                [
                    'ref_key' => 'tea_green_50g',
                    'sku' => 'THE-VERT-BIO-50G',
                    'name' => $this->getTranslation('variant.tea.50g', $locale),
                    'base_price_eur' => 5.90,
                    'stock' => $this->faker->numberBetween(70, 250),
                    'weight' => 50,
                    'is_default' => true,
                ],
                [
                    'ref_key' => 'tea_green_100g',
                    'sku' => 'THE-VERT-BIO-100G',
                    'name' => $this->getTranslation('variant.tea.100g', $locale),
                    'base_price_eur' => 10.90,
                    'stock' => $this->faker->numberBetween(50, 180),
                    'weight' => 100,
                    'is_default' => false,
                ],
            ],

            // CRÈME VISAGE - 2 formats
            'cream_face' => [
                [
                    'ref_key' => 'cream_face_50ml',
                    'sku' => 'CREME-VISAGE-BIO-50ML',
                    'name' => $this->getTranslation('variant.cream.50ml', $locale),
                    'base_price_eur' => 19.90,
                    'stock' => $this->faker->numberBetween(30, 120),
                    'weight' => 80,
                    'is_default' => true,
                ],
                [
                    'ref_key' => 'cream_face_100ml',
                    'sku' => 'CREME-VISAGE-BIO-100ML',
                    'name' => $this->getTranslation('variant.cream.100ml', $locale),
                    'base_price_eur' => 34.90,
                    'stock' => $this->faker->numberBetween(20, 80),
                    'weight' => 150,
                    'is_default' => false,
                ],
            ],

            // SÉRUM ANTI-ÂGE - 1 format
            'serum_antiaging' => [
                [
                    'ref_key' => 'serum_antiaging_30ml',
                    'sku' => 'SERUM-ANTIAGE-BIO-30ML',
                    'name' => $this->getTranslation('variant.serum.30ml', $locale),
                    'base_price_eur' => 29.90,
                    'stock' => $this->faker->numberBetween(25, 100),
                    'weight' => 50,
                    'is_default' => true,
                ],
            ],

            // LOTION CORPS - 2 formats
            'lotion_body' => [
                [
                    'ref_key' => 'lotion_body_200ml',
                    'sku' => 'LOTION-CORPS-BIO-200ML',
                    'name' => $this->getTranslation('variant.lotion.200ml', $locale),
                    'base_price_eur' => 14.90,
                    'stock' => $this->faker->numberBetween(40, 150),
                    'weight' => 220,
                    'is_default' => true,
                ],
                [
                    'ref_key' => 'lotion_body_500ml',
                    'sku' => 'LOTION-CORPS-BIO-500ML',
                    'name' => $this->getTranslation('variant.lotion.500ml', $locale),
                    'base_price_eur' => 29.90,
                    'stock' => $this->faker->numberBetween(25, 100),
                    'weight' => 550,
                    'is_default' => false,
                ],
            ],

            // SHAMPOING SOLIDE - 1 format
            'shampoo_solid' => [
                [
                    'ref_key' => 'shampoo_solid_75g',
                    'sku' => 'SHAMPOING-SOLIDE-BIO-75G',
                    'name' => $this->getTranslation('variant.shampoo.75g', $locale),
                    'base_price_eur' => 8.90,
                    'stock' => $this->faker->numberBetween(50, 180),
                    'weight' => 75,
                    'is_default' => true,
                ],
            ],

            // HUILE ESSENTIELLE LAVANDE - 2 formats
            'essential_lavender' => [
                [
                    'ref_key' => 'essential_lavender_10ml',
                    'sku' => 'HE-LAVANDE-BIO-10ML',
                    'name' => $this->getTranslation('variant.essential.10ml', $locale),
                    'base_price_eur' => 7.90,
                    'stock' => $this->faker->numberBetween(60, 200),
                    'weight' => 25,
                    'is_default' => true,
                ],
                [
                    'ref_key' => 'essential_lavender_30ml',
                    'sku' => 'HE-LAVANDE-BIO-30ML',
                    'name' => $this->getTranslation('variant.essential.30ml', $locale),
                    'base_price_eur' => 19.90,
                    'stock' => $this->faker->numberBetween(30, 120),
                    'weight' => 50,
                    'is_default' => false,
                ],
            ],

            // COMPLÉMENT VITAMINE C - 2 formats
            'supplement_vitc' => [
                [
                    'ref_key' => 'supplement_vitc_60caps',
                    'sku' => 'VITAMINE-C-BIO-60CAPS',
                    'name' => $this->getTranslation('variant.supplement.60caps', $locale),
                    'base_price_eur' => 12.90,
                    'stock' => $this->faker->numberBetween(40, 150),
                    'weight' => 60,
                    'is_default' => true,
                ],
                [
                    'ref_key' => 'supplement_vitc_120caps',
                    'sku' => 'VITAMINE-C-BIO-120CAPS',
                    'name' => $this->getTranslation('variant.supplement.120caps', $locale),
                    'base_price_eur' => 22.90,
                    'stock' => $this->faker->numberBetween(25, 100),
                    'weight' => 120,
                    'is_default' => false,
                ],
            ],

            // TISANE CAMOMILLE - 2 formats
            'herbal_chamomile' => [
                [
                    'ref_key' => 'herbal_chamomile_20bags',
                    'sku' => 'TISANE-CAMOMILLE-BIO-20S',
                    'name' => $this->getTranslation('variant.herbal.20bags', $locale),
                    'base_price_eur' => 4.90,
                    'stock' => $this->faker->numberBetween(70, 220),
                    'weight' => 40,
                    'is_default' => true,
                ],
                [
                    'ref_key' => 'herbal_chamomille_40bags',
                    'sku' => 'TISANE-CAMOMILLE-BIO-40S',
                    'name' => $this->getTranslation('variant.herbal.40bags', $locale),
                    'base_price_eur' => 8.90,
                    'stock' => $this->faker->numberBetween(50, 150),
                    'weight' => 80,
                    'is_default' => false,
                ],
            ],

            // DIFFUSEUR HUILES ESSENTIELLES - 1 format
            'diffuser_essential' => [
                [
                    'ref_key' => 'diffuser_essential_300ml',
                    'sku' => 'DIFFUSEUR-HE-300ML',
                    'name' => $this->getTranslation('variant.diffuser.300ml', $locale),
                    'base_price_eur' => 39.90,
                    'stock' => $this->faker->numberBetween(15, 60),
                    'weight' => 450,
                    'is_default' => true,
                ],
            ],
        ];
    }

    /**
     * Génère les prix multi-devises avec tarifs dégressifs B2B/B2C.
     * 
     * Stratégie de pricing :
     * - B2C : Prix public avec tarifs dégressifs à partir de 5 unités
     * - B2B : Prix professionnels (-30%) avec tarifs dégressifs à partir de 20 unités
     * - Multi-devises : EUR (base), USD (+10%), GBP (-15%)
     */
    private function generatePrices(float $basePriceEur): array
    {
        // Prix B2C
        $b2cBase = $basePriceEur;
        $b2cTier1 = round($basePriceEur * 0.92, 2); // -8% à partir de 5
        $b2cTier2 = round($basePriceEur * 0.85, 2); // -15% à partir de 10

        // Prix B2B (environ -30% par rapport au B2C)
        $b2bBase = round($basePriceEur * 0.70, 2);
        $b2bTier1 = round($basePriceEur * 0.65, 2); // -35% à partir de 20
        $b2bTier2 = round($basePriceEur * 0.60, 2); // -40% à partir de 50

        // Taux de conversion (approximatifs, devraient être configurables)
        $usdRate = 1.10; // 1 EUR = 1.10 USD
        $gbpRate = 0.85; // 1 EUR = 0.85 GBP

        return [
            'EUR' => [
                'B2C' => [
                    'base' => $b2cBase,
                    'quantity_tiers' => [
                        ['min' => 5, 'price' => $b2cTier1],
                        ['min' => 10, 'price' => $b2cTier2],
                    ],
                ],
                'B2B' => [
                    'base' => $b2bBase,
                    'quantity_tiers' => [
                        ['min' => 20, 'price' => $b2bTier1],
                        ['min' => 50, 'price' => $b2bTier2],
                    ],
                ],
            ],
            'USD' => [
                'B2C' => [
                    'base' => round($b2cBase * $usdRate, 2),
                    'quantity_tiers' => [
                        ['min' => 5, 'price' => round($b2cTier1 * $usdRate, 2)],
                        ['min' => 10, 'price' => round($b2cTier2 * $usdRate, 2)],
                    ],
                ],
                'B2B' => [
                    'base' => round($b2bBase * $usdRate, 2),
                    'quantity_tiers' => [
                        ['min' => 20, 'price' => round($b2bTier1 * $usdRate, 2)],
                        ['min' => 50, 'price' => round($b2bTier2 * $usdRate, 2)],
                    ],
                ],
            ],
            'GBP' => [
                'B2C' => [
                    'base' => round($b2cBase * $gbpRate, 2),
                    'quantity_tiers' => [
                        ['min' => 5, 'price' => round($b2cTier1 * $gbpRate, 2)],
                        ['min' => 10, 'price' => round($b2cTier2 * $gbpRate, 2)],
                    ],
                ],
                'B2B' => [
                    'base' => round($b2bBase * $gbpRate, 2),
                    'quantity_tiers' => [
                        ['min' => 20, 'price' => round($b2bTier1 * $gbpRate, 2)],
                        ['min' => 50, 'price' => round($b2bTier2 * $gbpRate, 2)],
                    ],
                ],
            ],
        ];
    }

    /**
     * Génère les dimensions du colis en fonction du poids (approximatif).
     * 
     * Ces dimensions sont utilisées pour :
     * - Calcul des frais de port (volumétrique)
     * - Optimisation du packaging
     * - Préparation logistique
     */
    private function generateDimensions(int $weight): array
    {
        // Calcul approximatif basé sur le poids
        if ($weight <= 100) {
            // Petit format (huiles essentielles, sérums)
            $length = $this->faker->numberBetween(8, 12);
            $width = $this->faker->numberBetween(4, 8);
            $height = $this->faker->numberBetween(2, 4);
        } elseif ($weight <= 300) {
            // Format moyen (pots 250g, flacons 200ml)
            $length = $this->faker->numberBetween(10, 15);
            $width = $this->faker->numberBetween(8, 12);
            $height = $this->faker->numberBetween(6, 10);
        } elseif ($weight <= 600) {
            // Format standard (pots 500g, flacons 500ml)
            $length = $this->faker->numberBetween(12, 18);
            $width = $this->faker->numberBetween(10, 14);
            $height = $this->faker->numberBetween(8, 12);
        } else {
            // Grand format (pots 1kg, bidons 1L)
            $length = $this->faker->numberBetween(15, 25);
            $width = $this->faker->numberBetween(12, 18);
            $height = $this->faker->numberBetween(10, 15);
        }

        return [
            'length' => $length,
            'width' => $width,
            'height' => $height,
            'unit' => 'cm',
        ];
    }

    /**
     * Génère un code EAN-13 factice mais au format valide.
     * 
     * ⚠️ ATTENTION PRODUCTION :
     * En production, utiliser de VRAIS codes EAN achetés auprès de GS1.
     * Les codes générés ici sont UNIQUEMENT pour les tests/développement.
     * 
     * Ressource : https://www.gs1.org/
     */
    private function generateEAN(): string
    {
        // Génère 12 chiffres aléatoires
        $ean12 = '';
        for ($i = 0; $i < 12; $i++) {
            $ean12 .= $this->faker->numberBetween(0, 9);
        }

        // Calcul de la clé de contrôle (13ème chiffre) selon algorithme EAN-13
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $sum += (int)$ean12[$i] * (($i % 2 === 0) ? 1 : 3);
        }
        $checksum = (10 - ($sum % 10)) % 10;

        return $ean12 . $checksum;
    }

    /**
     * Récupère les traductions des noms de variantes.
     * 
     * Pour une vraie application multilingue, utiliser :
     * - Le composant Translation de Symfony
     * - Ou une entité dédiée ProductVariantTranslation
     */
    private function getTranslation(string $key, string $locale): string
    {
        $translations = [
            'fr' => [
                // Miels
                'variant.honey.250g' => 'Pot 250g',
                'variant.honey.500g' => 'Pot 500g',
                'variant.honey.1kg' => 'Pot 1kg',
                // Huiles
                'variant.oil.250ml' => 'Flacon 250ml',
                'variant.oil.500ml' => 'Flacon 500ml',
                'variant.oil.1l' => 'Bidon 1L',
                // Sirops
                'variant.syrup.250ml' => 'Bouteille 250ml',
                'variant.syrup.500ml' => 'Bouteille 500ml',
                // Confitures
                'variant.jam.220g' => 'Pot 220g',
                'variant.jam.370g' => 'Pot 370g',
                // Thés
                'variant.tea.50g' => 'Sachet 50g',
                'variant.tea.100g' => 'Sachet 100g',
                // Cosmétiques
                'variant.cream.50ml' => 'Pot 50ml',
                'variant.cream.100ml' => 'Pot 100ml',
                'variant.serum.30ml' => 'Flacon 30ml',
                'variant.lotion.200ml' => 'Flacon 200ml',
                'variant.lotion.500ml' => 'Flacon 500ml',
                'variant.shampoo.75g' => 'Pain 75g',
                'variant.essential.10ml' => 'Flacon 10ml',
                'variant.essential.30ml' => 'Flacon 30ml',
                // Compléments
                'variant.supplement.60caps' => '60 gélules',
                'variant.supplement.120caps' => '120 gélules',
                // Tisanes
                'variant.herbal.20bags' => '20 sachets',
                'variant.herbal.40bags' => '40 sachets',
                // Diffuseur
                'variant.diffuser.300ml' => 'Modèle 300ml',
            ],
            'en' => [
                // Honeys
                'variant.honey.250g' => 'Jar 250g',
                'variant.honey.500g' => 'Jar 500g',
                'variant.honey.1kg' => 'Jar 1kg',
                // Oils
                'variant.oil.250ml' => 'Bottle 250ml',
                'variant.oil.500ml' => 'Bottle 500ml',
                'variant.oil.1l' => 'Can 1L',
                // Syrups
                'variant.syrup.250ml' => 'Bottle 250ml',
                'variant.syrup.500ml' => 'Bottle 500ml',
                // Jams
                'variant.jam.220g' => 'Jar 220g',
                'variant.jam.370g' => 'Jar 370g',
                // Teas
                'variant.tea.50g' => 'Pack 50g',
                'variant.tea.100g' => 'Pack 100g',
                // Cosmetics
                'variant.cream.50ml' => 'Jar 50ml',
                'variant.cream.100ml' => 'Jar 100ml',
                'variant.serum.30ml' => 'Bottle 30ml',
                'variant.lotion.200ml' => 'Bottle 200ml',
                'variant.lotion.500ml' => 'Bottle 500ml',
                'variant.shampoo.75g' => 'Bar 75g',
                'variant.essential.10ml' => 'Bottle 10ml',
                'variant.essential.30ml' => 'Bottle 30ml',
                // Supplements
                'variant.supplement.60caps' => '60 capsules',
                'variant.supplement.120caps' => '120 capsules',
                // Herbal teas
                'variant.herbal.20bags' => '20 bags',
                'variant.herbal.40bags' => '40 bags',
                // Diffuser
                'variant.diffuser.300ml' => 'Model 300ml',
            ],
            'es' => [
                // Mieles
                'variant.honey.250g' => 'Tarro 250g',
                'variant.honey.500g' => 'Tarro 500g',
                'variant.honey.1kg' => 'Tarro 1kg',
                // Aceites
                'variant.oil.250ml' => 'Botella 250ml',
                'variant.oil.500ml' => 'Botella 500ml',
                'variant.oil.1l' => 'Bidón 1L',
                // Jarabes
                'variant.syrup.250ml' => 'Botella 250ml',
                'variant.syrup.500ml' => 'Botella 500ml',
                // Mermeladas
                'variant.jam.220g' => 'Tarro 220g',
                'variant.jam.370g' => 'Tarro 370g',
                // Tés
                'variant.tea.50g' => 'Paquete 50g',
                'variant.tea.100g' => 'Paquete 100g',
                // Cosméticos
                'variant.cream.50ml' => 'Tarro 50ml',
                'variant.cream.100ml' => 'Tarro 100ml',
                'variant.serum.30ml' => 'Botella 30ml',
                'variant.lotion.200ml' => 'Botella 200ml',
                'variant.lotion.500ml' => 'Botella 500ml',
                'variant.shampoo.75g' => 'Pastilla 75g',
                'variant.essential.10ml' => 'Botella 10ml',
                'variant.essential.30ml' => 'Botella 30ml',
                // Suplementos
                'variant.supplement.60caps' => '60 cápsulas',
                'variant.supplement.120caps' => '120 cápsulas',
                // Infusiones
                'variant.herbal.20bags' => '20 bolsitas',
                'variant.herbal.40bags' => '40 bolsitas',
                // Difusor
                'variant.diffuser.300ml' => 'Modelo 300ml',
            ],
        ];

        return $translations[$locale][$key] ?? $translations['fr'][$key] ?? $key;
    }

    /**
     * Définit les dépendances des fixtures.
     */
    public function getDependencies(): array
    {
        return [
            ProductFixtures::class,
        ];
    }
}

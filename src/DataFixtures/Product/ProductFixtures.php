<?php

declare(strict_types=1);

namespace App\DataFixtures\Product;

use Faker\Factory;
use Faker\Generator;
use App\Entity\Site\Site;
use App\Entity\Product\Product;
use App\Entity\Product\Category;
use App\DataFixtures\Site\SiteFixtures;
use Doctrine\Persistence\ObjectManager;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;

/**
 * Fixtures pour les produits.
 * 
 * Responsabilités :
 * - Créer des produits réalistes pour une boutique bio
 * - Associer les produits aux catégories existantes
 * - Générer des données multilingues (fr, en, es)
 * - Respecter les bonnes pratiques SEO (meta, structured data)
 * 
 * Architecture :
 * - Dépend de SiteFixtures et CategoryFixtures
 * - Crée des références pour ProductVariantFixtures
 * - Utilise Faker pour des données cohérentes et réalistes
 */
class ProductFixtures extends Fixture implements DependentFixtureInterface
{
    public const PRODUCT_REFERENCE_PREFIX = 'product_';

    private Generator $faker;

    public function __construct()
    {
        $this->faker = Factory::create('fr_FR');
    }

    public function load(ObjectManager $manager): void
    {
        /** @var Site $site */
        $site = $this->getReference(SiteFixtures::SITE_FR_REFERENCE, Site::class);

        $locales = ['fr', 'en', 'es'];

        foreach ($locales as $locale) {
            $this->createProductsForLocale($manager, $site, $locale);
        }

        $manager->flush();
    }

    /**
     * Crée les produits pour une locale spécifique.
     */
    private function createProductsForLocale(
        ObjectManager $manager,
        Site $site,
        string $locale
    ): void {
        $products = $this->getProductsData($locale);

        foreach ($products as $productData) {
            $this->createProduct($manager, $site, $locale, $productData);
        }
    }

    /**
     * Crée un produit avec toutes ses données.
     */
    private function createProduct(
        ObjectManager $manager,
        Site $site,
        string $locale,
        array $productData
    ): Product {
        $product = new Product();
        $product->setLocale($locale);
        $product->setSite($site);

        // Informations de base
        $product->setSku($productData['sku']);
        $product->setName($productData['name']);
        $product->setSlug($productData['slug']);
        $product->setShortDescription($productData['short_description']);
        $product->setDescription($productData['description']);

        // Images (stockées en JSON pour flexibilité)
        $product->setImages($this->generateProductImages($productData['slug']));

        // Attributs spécifiques (origine, certifications, bienfaits)
        $product->setAttributes($this->generateProductAttributes($locale));

        // Valeurs nutritionnelles (pour produits alimentaires)
        if ($productData['has_nutritional']) {
            $product->setNutritionalValues($this->generateNutritionalValues());
        }

        // SEO
        $product->setMetaTitle($productData['name'] . ' - ' . $this->getTranslation('meta.suffix', $locale));
        $product->setMetaDescription($this->generateMetaDescription($productData['name'], $locale));
        $product->setStructuredData($this->generateStructuredData($product, $locale));

        // État et visibilité
        $product->isActive();
        $product->setIsFeatured($productData['is_featured'] ?? false);

        // Association avec les catégories
        foreach ($productData['categories'] as $categoryKey) {
            $categoryRef = CategoryFixtures::CATEGORY_REFERENCE_PREFIX . $locale . '_' . $categoryKey;

            if ($this->hasReference($categoryRef, Category::class)) {
                /** @var Category $category */
                $category = $this->getReference($categoryRef, Category::class);
                $product->addCategory($category);
            }
        }



        $manager->persist($product);

        // Créer une référence pour les fixtures de variantes
        $reference = self::PRODUCT_REFERENCE_PREFIX . $locale . '_' . $productData['ref_key'];
        $this->addReference($reference, $product);

        return $product;
    }

    /**
     * Définit la structure des produits par locale.
     * 
     * Structure réaliste pour une boutique bio avec :
     * - Produits alimentaires (miels, huiles, sirops, etc.)
     * - Produits cosmétiques (soins visage, corps, cheveux)
     * - Produits bien-être (compléments, tisanes, aromathérapie)
     */
    private function getProductsData(string $locale): array
    {
        $productsMap = [
            'fr' => [
                // ALIMENTAIRE - MIELS
                [
                    'ref_key' => 'honey_flowers',
                    'sku' => 'MIEL-FLEURS-BIO',
                    'name' => 'Miel de Fleurs Bio',
                    'slug' => 'miel-de-fleurs-bio',
                    'short_description' => 'Miel de fleurs sauvages bio, récolté dans nos ruches en Provence.',
                    'description' => 'Notre miel de fleurs bio est récolté avec soin dans les champs de Provence. Riche en saveurs et en bienfaits, il est idéal pour sucrer vos boissons chaudes ou accompagner vos tartines. Production locale et responsable garantie.',
                    'categories' => ['honeys', 'food'],
                    'has_nutritional' => true,
                    'is_featured' => true,
                ],
                [
                    'ref_key' => 'honey_acacia',
                    'sku' => 'MIEL-ACACIA-BIO',
                    'name' => 'Miel d\'Acacia Bio',
                    'slug' => 'miel-acacia-bio',
                    'short_description' => 'Miel d\'acacia bio doux et délicat, idéal pour toute la famille.',
                    'description' => 'Le miel d\'acacia bio est réputé pour sa douceur et sa couleur claire. Parfait pour les enfants et les personnes recherchant un goût subtil. Récolte française certifiée AB.',
                    'categories' => ['honeys', 'food'],
                    'has_nutritional' => true,
                    'is_featured' => false,
                ],
                [
                    'ref_key' => 'honey_lavender',
                    'sku' => 'MIEL-LAVANDE-BIO',
                    'name' => 'Miel de Lavande Bio',
                    'slug' => 'miel-de-lavande-bio',
                    'short_description' => 'Miel de lavande bio de Provence, arôme floral unique.',
                    'description' => 'Notre miel de lavande bio capture l\'essence des champs de lavande provençaux. Son goût floral distinctif et ses propriétés apaisantes en font un produit d\'exception. Certifié bio et production artisanale.',
                    'categories' => ['honeys', 'food'],
                    'has_nutritional' => true,
                    'is_featured' => true,
                ],

                // ALIMENTAIRE - HUILES
                [
                    'ref_key' => 'oil_olive',
                    'sku' => 'HUILE-OLIVE-BIO',
                    'name' => 'Huile d\'Olive Extra Vierge Bio',
                    'slug' => 'huile-olive-extra-vierge-bio',
                    'short_description' => 'Huile d\'olive extra vierge bio, première pression à froid.',
                    'description' => 'Huile d\'olive extra vierge bio obtenue par première pression à froid. Cultivée et pressée en Provence, elle offre un goût fruité et équilibré, riche en oméga-9 et antioxydants. Idéale pour la cuisine et l\'assaisonnement.',
                    'categories' => ['oils', 'food'],
                    'has_nutritional' => true,
                    'is_featured' => true,
                ],
                [
                    'ref_key' => 'oil_coconut',
                    'sku' => 'HUILE-COCO-BIO',
                    'name' => 'Huile de Coco Vierge Bio',
                    'slug' => 'huile-coco-vierge-bio',
                    'short_description' => 'Huile de coco vierge bio, polyvalente et naturelle.',
                    'description' => 'Huile de coco vierge bio extraite à froid. Parfaite pour la cuisine à haute température, les soins capillaires et corporels. Sans additifs, 100% naturelle. Certifiée agriculture biologique.',
                    'categories' => ['oils', 'food'],
                    'has_nutritional' => true,
                    'is_featured' => false,
                ],

                // ALIMENTAIRE - SIROPS
                [
                    'ref_key' => 'syrup_maple',
                    'sku' => 'SIROP-ERABLE-BIO',
                    'name' => 'Sirop d\'Érable Bio',
                    'slug' => 'sirop-erable-bio',
                    'short_description' => 'Sirop d\'érable bio pur, importé du Canada.',
                    'description' => 'Sirop d\'érable bio grade A, ambré et riche en saveur. Importé directement du Canada, il est parfait pour les crêpes, les yaourts et la pâtisserie. 100% pur et naturel.',
                    'categories' => ['syrups', 'food'],
                    'has_nutritional' => true,
                    'is_featured' => false,
                ],

                // ALIMENTAIRE - CONFITURES
                [
                    'ref_key' => 'jam_strawberry',
                    'sku' => 'CONF-FRAISE-BIO',
                    'name' => 'Confiture de Fraises Bio',
                    'slug' => 'confiture-fraises-bio',
                    'short_description' => 'Confiture de fraises bio artisanale, 70% de fruits.',
                    'description' => 'Confiture artisanale aux fraises bio françaises. Préparée avec 70% de fruits et du sucre de canne bio. Texture généreuse et goût authentique. Production locale en petits lots.',
                    'categories' => ['jams', 'food'],
                    'has_nutritional' => true,
                    'is_featured' => false,
                ],

                // ALIMENTAIRE - THÉS
                [
                    'ref_key' => 'tea_green',
                    'sku' => 'THE-VERT-BIO',
                    'name' => 'Thé Vert Bio Premium',
                    'slug' => 'the-vert-bio-premium',
                    'short_description' => 'Thé vert bio premium, riche en antioxydants.',
                    'description' => 'Thé vert bio de qualité premium, cultivé en agriculture biologique. Riche en catéchines et antioxydants naturels. Notes végétales et légèrement sucrées. Parfait pour un moment de détente.',
                    'categories' => ['teas', 'food'],
                    'has_nutritional' => false,
                    'is_featured' => true,
                ],

                // COSMÉTIQUES - SOINS VISAGE
                [
                    'ref_key' => 'cream_face',
                    'sku' => 'CREME-VISAGE-BIO',
                    'name' => 'Crème Visage Hydratante Bio',
                    'slug' => 'creme-visage-hydratante-bio',
                    'short_description' => 'Crème visage bio à l\'acide hyaluronique et aloé vera.',
                    'description' => 'Crème visage hydratante bio formulée avec de l\'acide hyaluronique végétal et de l\'aloé vera. Texture légère, pénétration rapide. Convient à tous types de peaux. Certifiée Ecocert et vegan.',
                    'categories' => ['face_care', 'cosmetics'],
                    'has_nutritional' => false,
                    'is_featured' => true,
                ],
                [
                    'ref_key' => 'serum_face',
                    'sku' => 'SERUM-VISAGE-BIO',
                    'name' => 'Sérum Visage Anti-Âge Bio',
                    'slug' => 'serum-visage-anti-age-bio',
                    'short_description' => 'Sérum anti-âge bio à la vitamine C et aux peptides végétaux.',
                    'description' => 'Sérum visage concentré en actifs anti-âge. Vitamine C stabilisée, peptides végétaux et acide hyaluronique. Réduit visiblement les rides et redonne de l\'éclat. Formule clean et certifiée bio.',
                    'categories' => ['face_care', 'cosmetics'],
                    'has_nutritional' => false,
                    'is_featured' => true,
                ],

                // COSMÉTIQUES - SOINS CORPS
                [
                    'ref_key' => 'body_lotion',
                    'sku' => 'LAIT-CORPS-BIO',
                    'name' => 'Lait Corps Nourrissant Bio',
                    'slug' => 'lait-corps-nourrissant-bio',
                    'short_description' => 'Lait corporel bio au beurre de karité et huile d\'amande douce.',
                    'description' => 'Lait corporel nourrissant enrichi en beurre de karité bio et huile d\'amande douce. Hydrate intensément sans effet gras. Parfum délicat et naturel. Convient aux peaux sensibles.',
                    'categories' => ['body_care', 'cosmetics'],
                    'has_nutritional' => false,
                    'is_featured' => false,
                ],

                // COSMÉTIQUES - SOINS CHEVEUX
                [
                    'ref_key' => 'shampoo_solid',
                    'sku' => 'SHAMP-SOLIDE-BIO',
                    'name' => 'Shampoing Solide Bio',
                    'slug' => 'shampoing-solide-bio',
                    'short_description' => 'Shampoing solide bio zéro déchet, tous types de cheveux.',
                    'description' => 'Shampoing solide bio, alternative zéro déchet aux shampoings liquides. Formule douce aux huiles essentielles de romarin et lavande. Équivaut à 2-3 flacons classiques. Sans sulfates ni silicones.',
                    'categories' => ['hair_care', 'cosmetics'],
                    'has_nutritional' => false,
                    'is_featured' => true,
                ],

                // COSMÉTIQUES - HUILES ESSENTIELLES
                [
                    'ref_key' => 'essential_lavender',
                    'sku' => 'HE-LAVANDE-BIO',
                    'name' => 'Huile Essentielle Lavande Bio',
                    'slug' => 'huile-essentielle-lavande-bio',
                    'short_description' => 'Huile essentielle de lavande fine bio, apaisante et relaxante.',
                    'description' => 'Huile essentielle de lavande fine (Lavandula angustifolia) 100% pure et naturelle. Distillée en Provence. Propriétés calmantes et relaxantes reconnues. Parfaite pour l\'aromathérapie et les soins.',
                    'categories' => ['essential_oils', 'cosmetics'],
                    'has_nutritional' => false,
                    'is_featured' => true,
                ],

                // BIEN-ÊTRE - COMPLÉMENTS
                [
                    'ref_key' => 'vitamin_c',
                    'sku' => 'VITC-NAT-BIO',
                    'name' => 'Vitamine C Naturelle Bio',
                    'slug' => 'vitamine-c-naturelle-bio',
                    'short_description' => 'Vitamine C naturelle extraite d\'acérola bio, immunité et vitalité.',
                    'description' => 'Complément alimentaire à base de vitamine C naturelle extraite d\'acérola bio. Renforce le système immunitaire et réduit la fatigue. 1000mg par dose. Gélules végétales, sans excipients artificiels.',
                    'categories' => ['supplements', 'wellness'],
                    'has_nutritional' => false,
                    'is_featured' => false,
                ],

                // BIEN-ÊTRE - TISANES
                [
                    'ref_key' => 'herbal_relax',
                    'sku' => 'TISANE-RELAX-BIO',
                    'name' => 'Tisane Relaxation Bio',
                    'slug' => 'tisane-relaxation-bio',
                    'short_description' => 'Mélange de plantes bio pour la relaxation : camomille, verveine, tilleul.',
                    'description' => 'Infusion bio relaxante aux plantes calmantes : camomille, verveine et tilleul. Idéale avant le coucher pour favoriser la détente. Sachets biodégradables. Cultivée et conditionnée en France.',
                    'categories' => ['herbal_teas', 'wellness'],
                    'has_nutritional' => false,
                    'is_featured' => false,
                ],

                // BIEN-ÊTRE - AROMATHÉRAPIE
                [
                    'ref_key' => 'diffuser',
                    'sku' => 'DIFF-AROMA-BIO',
                    'name' => 'Diffuseur d\'Huiles Essentielles',
                    'slug' => 'diffuseur-huiles-essentielles',
                    'short_description' => 'Diffuseur ultrasonique en bois naturel pour aromathérapie.',
                    'description' => 'Diffuseur d\'huiles essentielles ultrasonique au design épuré. Revêtement en bois naturel. Technologie silencieuse avec lumière LED réglable. Capacité 300ml, autonomie 10h. Idéal pour purifier l\'air.',
                    'categories' => ['aromatherapy', 'wellness'],
                    'has_nutritional' => false,
                    'is_featured' => true,
                ],
            ]
        ];

        return $productsMap[$locale] ?? [];
    }

    /**
     * Génère les URLs des images du produit.
     * 
     * Structure JSON pour flexibilité (évite une table dédiée pour < 10 images).
     */
    private function generateProductImages(string $slug): array
    {
        return [
            'main' => [
                'original' => "products/{$slug}-main-original.jpg",
                'large' => "products/{$slug}-main-large.jpg",
                'medium' => "products/{$slug}-main-medium.jpg",
                'thumbnail' => "products/{$slug}-main-thumb.jpg",
                'alt' => $slug,
            ],
            'gallery' => [
                [
                    'original' => "products/{$slug}-gallery-1-original.jpg",
                    'large' => "products/{$slug}-gallery-1-large.jpg",
                    'medium' => "products/{$slug}-gallery-1-medium.jpg",
                    'thumbnail' => "products/{$slug}-gallery-1-thumb.jpg",
                    'alt' => $slug . ' vue 1',
                ],
                [
                    'original' => "products/{$slug}-gallery-2-original.jpg",
                    'large' => "products/{$slug}-gallery-2-large.jpg",
                    'medium' => "products/{$slug}-gallery-2-medium.jpg",
                    'thumbnail' => "products/{$slug}-gallery-2-thumb.jpg",
                    'alt' => $slug . ' vue 2',
                ],
            ],
            'uploaded_at' => $this->faker->dateTimeBetween('-6 months', 'now')->format('c'),
        ];
    }

    /**
     * Génère les attributs spécifiques du produit (origine, certifications, etc.).
     */
    private function generateProductAttributes(string $locale): array
    {
        $origins = ['France', 'Provence', 'Languedoc', 'Bretagne', 'Alsace'];
        $certifications = ['AB (Agriculture Biologique)', 'Ecocert', 'Nature & Progrès', 'Demeter'];

        return [
            'origin' => $this->faker->randomElement($origins),
            'certifications' => $this->faker->randomElements($certifications, $this->faker->numberBetween(1, 2)),
            'benefits' => $this->generateBenefits($locale),
            'allergens' => $this->faker->optional(0.3)->randomElements(
                ['Traces de fruits à coque', 'Peut contenir du gluten', 'Contient du soja'],
                $this->faker->numberBetween(1, 2)
            ) ?? [],
            'storage' => $this->getTranslation('storage.instructions', $locale),
            'made_in' => 'France',
        ];
    }

    /**
     * Génère des bienfaits produit réalistes.
     */
    private function generateBenefits(string $locale): array
    {
        $benefitsMap = [
            'fr' => [
                'Riche en antioxydants naturels',
                'Source d\'énergie naturelle',
                'Favorise le bien-être digestif',
                'Contribue à l\'hydratation de la peau',
                'Renforce les défenses naturelles',
                'Sans additifs artificiels',
                'Production respectueuse de l\'environnement',
            ],
            'en' => [
                'Rich in natural antioxidants',
                'Natural energy source',
                'Promotes digestive wellness',
                'Contributes to skin hydration',
                'Strengthens natural defenses',
                'No artificial additives',
                'Environmentally friendly production',
            ],
            'es' => [
                'Rico en antioxidantes naturales',
                'Fuente de energía natural',
                'Favorece el bienestar digestivo',
                'Contribuye a la hidratación de la piel',
                'Refuerza las defensas naturales',
                'Sin aditivos artificiales',
                'Producción respetuosa con el medio ambiente',
            ],
        ];

        return $this->faker->randomElements(
            $benefitsMap[$locale] ?? $benefitsMap['fr'],
            $this->faker->numberBetween(3, 5)
        );
    }

    /**
     * Génère les valeurs nutritionnelles (pour produits alimentaires).
     */
    private function generateNutritionalValues(): array
    {
        return [
            'per_100g' => [
                'energy_kj' => $this->faker->numberBetween(800, 1500),
                'energy_kcal' => $this->faker->numberBetween(200, 350),
                'fat' => round($this->faker->randomFloat(1, 0, 15), 1),
                'saturated_fat' => round($this->faker->randomFloat(1, 0, 5), 1),
                'carbohydrates' => round($this->faker->randomFloat(1, 40, 85), 1),
                'sugars' => round($this->faker->randomFloat(1, 30, 80), 1),
                'protein' => round($this->faker->randomFloat(1, 0.1, 8), 1),
                'salt' => round($this->faker->randomFloat(2, 0, 0.5), 2),
            ],
            'serving_size' => '20g (1 cuillère à soupe)',
        ];
    }

    /**
     * Génère une meta description optimisée SEO.
     */
    private function generateMetaDescription(string $productName, string $locale): string
    {
        $templates = [
            'fr' => "Découvrez notre {product} bio et naturel. ✓ Produit artisanal ✓ Made in France",
            'en' => "Discover our organic and natural {product}. ✓ Made in France",
            'es' => "Descubra nuestro {product} ecológico y natural.  ✓ Producto artesanal ✓ Hecho en Francia",
        ];

        return str_replace('{product}', strtolower($productName), $templates[$locale] ?? $templates['fr']);
    }

    /**
     * Génère le structured data (Schema.org) pour le SEO.
     */
    private function generateStructuredData(Product $product, string $locale): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            'name' => $product->getName(),
            'description' => $product->getShortDescription(),
            'sku' => $product->getSku(),
            'brand' => [
                '@type' => 'Brand',
                'name' => 'Bio Nature Premium',
            ],
            'offers' => [
                '@type' => 'AggregateOffer',
                'priceCurrency' => 'EUR',
                'availability' => 'https://schema.org/InStock',
            ],
        ];
    }

    /**
     * Récupère une traduction selon la locale.
     */
    private function getTranslation(string $key, string $locale): string
    {
        $translations = [
            'fr' => [
                'meta.suffix' => 'Boutique Bio',
                'storage.instructions' => 'À conserver au sec et à l\'abri de la lumière',
            ],
            'en' => [
                'meta.suffix' => 'Organic Shop',
                'storage.instructions' => 'Store in a dry place away from light',
            ],
            'es' => [
                'meta.suffix' => 'Tienda Ecológica',
                'storage.instructions' => 'Conservar en un lugar seco y protegido de la luz',
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
            SiteFixtures::class,
            CategoryFixtures::class,
        ];
    }
}

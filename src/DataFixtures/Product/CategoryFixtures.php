<?php

declare(strict_types=1);

namespace App\DataFixtures\Product;

use Faker\Factory;
use Faker\Generator;
use App\Entity\Site\Site;
use App\Entity\Product\Category;
use App\DataFixtures\Site\SiteFixtures;
use Doctrine\Persistence\ObjectManager;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;

class CategoryFixtures extends Fixture implements DependentFixtureInterface
{
    public const CATEGORY_REFERENCE_PREFIX = 'category_';
    
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
            $this->createCategoriesForLocale($manager, $site, $locale);
        }

        $manager->flush();
    }

    private function createCategoriesForLocale(ObjectManager $manager, Site $site, string $locale): void
    {
        $structure = $this->getCategoryStructure($locale);

        $position = 0;

        foreach ($structure as $nameKey => $children) {
            $parent = $this->createCategory(
                $manager,
                $site,
                $nameKey,
                $locale,
                null,
                $position++
            );

            if (!empty($children)) {
                $childPosition = 0;
                foreach ($children as $childNameKey) {
                    $this->createCategory(
                        $manager,
                        $site,
                        $childNameKey,
                        $locale,
                        $parent,
                        $childPosition++
                    );
                }
            }
        }
    }

    private function createCategory(
        ObjectManager $manager,
        Site $site,
        string $nameKey,
        string $locale,
        ?Category $parent,
        int $position
    ): Category {
        $category = new Category();
        $category->setLocale($locale);
        $category->setSite($site);
        $category->setPosition($position);
        $category->setParent($parent);

        $category->setDescription($this->faker->paragraph(3));

        // Générer manuellement le slug pour les fixtures
        $slug = $this->generateSlugFromKey($nameKey);
        $category->setSlug($slug);
        $category->setImages([
            'original' => "categories/{$slug}-original.jpg",
            'thumbnail' => "categories/{$slug}-thumb.jpg",
            'medium' => "categories/{$slug}-medium.jpg",
            'large' => "categories/{$slug}-large.jpg",
            'alt' => $this->getTranslation($nameKey, $locale),
            'uploaded_at' => $this->faker->dateTimeBetween('-1 year', 'now')->format('c'),
        ]);

        $translatedName = $this->getTranslation($nameKey, $locale);
        $category->setName($translatedName);
        $category->setMetaTitle($translatedName . ' - Boutique Bio');
        $category->setMetaDescription($this->faker->realText(150));
        $category->setStructuredData($this->generateStructuredData($translatedName));

        $manager->persist($category);

        $reference = self::CATEGORY_REFERENCE_PREFIX . $locale . '_' . $slug;
        $this->addReference($reference, $category);

        return $category;
    }

    private function getCategoryStructure(string $locale): array
    {
        return match ($locale) {
            'fr' => [
                'category.name.food' => [
                    'category.name.honeys',
                    'category.name.oils',
                    'category.name.syrups',
                    'category.name.jams',
                    'category.name.teas',
                ],
                'category.name.cosmetics' => [
                    'category.name.face_care',
                    'category.name.body_care',
                    'category.name.hair_care',
                    'category.name.essential_oils',
                ],
                'category.name.wellness' => [
                    'category.name.supplements',
                    'category.name.herbal_teas',
                    'category.name.aromatherapy',
                ],
            ],
            'en' => [
                'category.name.food' => [
                    'category.name.honeys',
                    'category.name.oils',
                    'category.name.syrups',
                    'category.name.jams',
                    'category.name.teas',
                ],
                'category.name.cosmetics' => [
                    'category.name.face_care',
                    'category.name.body_care',
                    'category.name.hair_care',
                    'category.name.essential_oils',
                ],
                'category.name.wellness' => [
                    'category.name.supplements',
                    'category.name.herbal_teas',
                    'category.name.aromatherapy',
                ],
            ],
            'es' => [
                'category.name.food' => [
                    'category.name.honeys',
                    'category.name.oils',
                    'category.name.syrups',
                    'category.name.jams',
                    'category.name.teas',
                ],
                'category.name.cosmetics' => [
                    'category.name.face_care',
                    'category.name.body_care',
                    'category.name.hair_care',
                    'category.name.essential_oils',
                ],
                'category.name.wellness' => [
                    'category.name.supplements',
                    'category.name.herbal_teas',
                    'category.name.aromatherapy',
                ],
            ],
        };
    }

    private function getTranslation(string $key, string $locale): string
    {
        $translations = [
            'fr' => [
                'category.name.food' => 'Alimentaire',
                'category.name.honeys' => 'Miels',
                'category.name.oils' => 'Huiles',
                'category.name.syrups' => 'Sirops',
                'category.name.jams' => 'Confitures',
                'category.name.teas' => 'Thés & Infusions',
                'category.name.cosmetics' => 'Cosmétiques',
                'category.name.face_care' => 'Soins Visage',
                'category.name.body_care' => 'Soins Corps',
                'category.name.hair_care' => 'Soins Cheveux',
                'category.name.essential_oils' => 'Huiles Essentielles',
                'category.name.wellness' => 'Bien-être',
                'category.name.supplements' => 'Compléments',
                'category.name.herbal_teas' => 'Tisanes',
                'category.name.aromatherapy' => 'Aromathérapie',
            ],
            'en' => [
                'category.name.food' => 'Food',
                'category.name.honeys' => 'Honeys',
                'category.name.oils' => 'Oils',
                'category.name.syrups' => 'Syrups',
                'category.name.jams' => 'Jams',
                'category.name.teas' => 'Teas & Infusions',
                'category.name.cosmetics' => 'Cosmetics',
                'category.name.face_care' => 'Face Care',
                'category.name.body_care' => 'Body Care',
                'category.name.hair_care' => 'Hair Care',
                'category.name.essential_oils' => 'Essential Oils',
                'category.name.wellness' => 'Wellness',
                'category.name.supplements' => 'Supplements',
                'category.name.herbal_teas' => 'Herbal Teas',
                'category.name.aromatherapy' => 'Aromatherapy',
            ],
            'es' => [
                'category.name.food' => 'Alimentación',
                'category.name.honeys' => 'Mieles',
                'category.name.oils' => 'Aceites',
                'category.name.syrups' => 'Siropes',
                'category.name.jams' => 'Mermeladas',
                'category.name.teas' => 'Tés e Infusiones',
                'category.name.cosmetics' => 'Cosméticos',
                'category.name.face_care' => 'Cuidado Facial',
                'category.name.body_care' => 'Cuidado Corporal',
                'category.name.hair_care' => 'Cuidado Capilar',
                'category.name.essential_oils' => 'Aceites Esenciales',
                'category.name.wellness' => 'Bienestar',
                'category.name.supplements' => 'Suplementos',
                'category.name.herbal_teas' => 'Tisanas',
                'category.name.aromatherapy' => 'Aromaterapia',
            ],
        ];

        return $translations[$locale][$key] ?? $key;
    }

    private function generateSlugFromKey(string $key): string
    {
        $parts = explode('.', $key);
        return end($parts);
    }

    private function generateStructuredData(string $name): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'CollectionPage',
            'name' => $name,
            'description' => $this->faker->sentence(10),
        ];
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
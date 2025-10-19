<?php

declare(strict_types=1);

namespace App\DataFixtures\Site;

use App\Entity\Site\Site;
use App\Enum\Site\SiteStatus;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Faker\Factory as FakerFactory;

/**
 * Fixtures pour l'entité Site.
 * 
 * Génère :
 * - 1 site principal (FR)
 * - 2 sites secondaires (BE, PRO)
 * - 1 site en maintenance
 * - 1 site archivé
 */
class SiteFixtures extends Fixture
{
    public const SITE_FR_REFERENCE = 'site_fr';
    public const SITE_BE_REFERENCE = 'site_be';
    public const SITE_PRO_REFERENCE = 'site_pro';

    public function load(ObjectManager $manager): void
    {
        $faker = FakerFactory::create('fr_FR');

        // Site principal France
        $siteFr = $this->createSite(
            code: 'FR',
            name: 'Ma Boutique Bio France',
            domain: 'boutique-bio.fr',
            currency: 'EUR',
            locales: ['fr', 'en'],
            defaultLocale: 'fr',
            status: SiteStatus::ACTIVE,
            settings: [
                'theme' => [
                    'primary_color' => '#2E7D32',
                    'logo_url' => 'https://via.placeholder.com/200x60?text=Logo+FR',
                    'favicon_url' => '/favicon-fr.ico',
                ],
                'contact' => [
                    'email' => 'contact@boutique-bio.fr',
                    'phone' => '+33 1 23 45 67 89',
                    'address' => '123 Rue de la Santé, 75013 Paris',
                ],
                'seo' => [
                    'default_title' => 'Boutique Bio France - Produits Bio et Naturels',
                    'default_description' => 'Découvrez notre sélection de produits bio et naturels.',
                    'google_analytics_id' => 'UA-123456-1',
                ],
                'social' => [
                    'facebook' => 'https://facebook.com/boutiquebio',
                    'instagram' => 'https://instagram.com/boutiquebio',
                    'twitter' => 'https://twitter.com/boutiquebio',
                ],
            ]
        );
        $manager->persist($siteFr);
        $this->addReference(self::SITE_FR_REFERENCE, $siteFr);

        // Site Belgique
        $siteBe = $this->createSite(
            code: 'BE',
            name: 'Ma Boutique Bio Belgique',
            domain: 'boutique-bio.be',
            currency: 'EUR',
            locales: ['fr', 'nl', 'en'],
            defaultLocale: 'fr',
            status: SiteStatus::ACTIVE,
            settings: [
                'theme' => [
                    'primary_color' => '#1976D2',
                    'logo_url' => 'https://via.placeholder.com/200x60?text=Logo+BE',
                ],
                'contact' => [
                    'email' => 'contact@boutique-bio.be',
                    'phone' => '+32 2 123 45 67',
                    'address' => 'Rue de la Loi 123, 1000 Bruxelles',
                ],
            ]
        );
        $manager->persist($siteBe);
        $this->addReference(self::SITE_BE_REFERENCE, $siteBe);

        // Site Pro (revendeurs B2B)
        $sitePro = $this->createSite(
            code: 'PRO',
            name: 'Espace Professionnel',
            domain: 'pro.boutique-bio.fr',
            currency: 'EUR',
            locales: ['fr', 'en'],
            defaultLocale: 'fr',
            status: SiteStatus::ACTIVE,
            settings: [
                'theme' => [
                    'primary_color' => '#F57C00',
                    'logo_url' => 'https://via.placeholder.com/200x60?text=Logo+PRO',
                ],
                'contact' => [
                    'email' => 'pro@boutique-bio.fr',
                    'phone' => '+33 1 98 76 54 32',
                ],
                'b2b' => [
                    'min_order_amount' => 500,
                    'discount_percentage' => 20,
                ],
            ]
        );
        $manager->persist($sitePro);
        $this->addReference(self::SITE_PRO_REFERENCE, $sitePro);

        // Site en maintenance (exemple)
        $siteMaintenance = $this->createSite(
            code: 'CH',
            name: 'Boutique Suisse',
            domain: 'boutique-bio.ch',
            currency: 'CHF',
            locales: ['fr', 'de', 'it', 'en'],
            defaultLocale: 'fr',
            status: SiteStatus::MAINTENANCE,
            settings: [
                'maintenance' => [
                    'message' => 'Site en cours de mise à jour, retour bientôt !',
                    'estimated_end' => $faker->dateTimeBetween('+1 day', '+7 days')->format('Y-m-d H:i:s'),
                ],
            ]
        );
        $manager->persist($siteMaintenance);

        // Site archivé (ancien client)
        $siteArchived = $this->createSite(
            code: 'OLD',
            name: 'Ancien Site Test',
            domain: 'old.boutique-bio.test',
            currency: 'EUR',
            locales: ['fr'],
            defaultLocale: 'fr',
            status: SiteStatus::ARCHIVED
        );
        $siteArchived->deactivate(); // closedAt défini
        $manager->persist($siteArchived);

        $manager->flush();
    }

    /**
     * Helper pour créer un site avec moins de duplication.
     */
    private function createSite(
        string $code,
        string $name,
        string $domain,
        string $currency,
        array $locales,
        string $defaultLocale,
        SiteStatus $status,
        ?array $settings = null
    ): Site {
        $site = new Site();
        $site
            ->setCode($code)
            ->setName($name)
            ->setDomain($domain)
            ->setCurrency($currency)
            ->setLocales($locales)
            ->setDefaultLocale($defaultLocale)
            ->setStatus($status)
            ->setSettings($settings);

        return $site;
    }
}

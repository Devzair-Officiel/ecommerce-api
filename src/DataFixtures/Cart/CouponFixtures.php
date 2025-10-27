<?php

declare(strict_types=1);

namespace App\DataFixtures\Cart;

use App\Entity\Cart\Coupon;
use App\Entity\Site\Site;
use App\DataFixtures\Site\SiteFixtures;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

/**
 * Fixtures pour Coupon.
 * 
 * Génère :
 * - Coupons de bienvenue
 * - Promotions saisonnières
 * - Codes VIP
 * - Livraison gratuite
 */
class CouponFixtures extends Fixture implements DependentFixtureInterface
{
    public const COUPON_WELCOME = 'coupon_welcome';
    public const COUPON_FREE_SHIPPING = 'coupon_free_shipping';

    public function load(ObjectManager $manager): void
    {
        /** @var Site $siteFr */
        $siteFr = $this->getReference(SiteFixtures::SITE_FR_REFERENCE, Site::class);

        // ===============================================
        // COUPON BIENVENUE (10% - première commande)
        // ===============================================
        $welcome = new Coupon();
        $welcome
            ->setSite($siteFr)
            ->setCode('WELCOME10')
            ->setType(Coupon::TYPE_PERCENTAGE)
            ->setValue(0.10) // 10%
            ->setMinimumAmount(30.00)
            ->setMaximumDiscount(15.00) // Plafonné à 15€
            ->setValidFrom(new \DateTimeImmutable('-1 month'))
            ->setValidUntil(new \DateTimeImmutable('+1 year'))
            ->setMaxUsages(1000)
            ->setMaxUsagesPerUser(1)
            ->setFirstOrderOnly(true)
            ->setPublicMessage('Bienvenue ! Profitez de 10% sur votre première commande.')
            ->setInternalNote('Campagne bienvenue nouveaux clients');

        $manager->persist($welcome);
        $this->addReference(self::COUPON_WELCOME, $welcome);

        // ===============================================
        // LIVRAISON GRATUITE (>50€)
        // ===============================================
        $freeShipping = new Coupon();
        $freeShipping
            ->setSite($siteFr)
            ->setCode('FREESHIP')
            ->setType(Coupon::TYPE_FREE_SHIPPING)
            ->setValue(null)
            ->setMinimumAmount(50.00)
            ->setValidFrom(new \DateTimeImmutable('-1 week'))
            ->setValidUntil(new \DateTimeImmutable('+3 months'))
            ->setMaxUsages(null) // Illimité
            ->setMaxUsagesPerUser(null)
            ->setPublicMessage('Livraison gratuite dès 50€ d\'achat !')
            ->setInternalNote('Promotion livraison gratuite permanente');

        $manager->persist($freeShipping);
        $this->addReference(self::COUPON_FREE_SHIPPING, $freeShipping);

        // ===============================================
        // PROMO NOEL (15% - limité)
        // ===============================================
        $noel = new Coupon();
        $noel
            ->setSite($siteFr)
            ->setCode('NOEL2024')
            ->setType(Coupon::TYPE_PERCENTAGE)
            ->setValue(0.15) // 15%
            ->setMinimumAmount(50.00)
            ->setMaximumDiscount(30.00)
            ->setValidFrom(new \DateTimeImmutable('-2 weeks'))
            ->setValidUntil(new \DateTimeImmutable('+1 month'))
            ->setMaxUsages(500)
            ->setMaxUsagesPerUser(1)
            ->setPublicMessage('Fêtes de fin d\'année : -15% sur tout le site !')
            ->setInternalNote('Campagne Noël 2024');

        $manager->persist($noel);

        // ===============================================
        // RÉDUCTION FIXE (5€)
        // ===============================================
        $fixed5 = new Coupon();
        $fixed5
            ->setSite($siteFr)
            ->setCode('SAVE5')
            ->setType(Coupon::TYPE_FIXED_AMOUNT)
            ->setValue(5.00)
            ->setMinimumAmount(40.00)
            ->setValidFrom(new \DateTimeImmutable('-1 week'))
            ->setValidUntil(new \DateTimeImmutable('+2 months'))
            ->setMaxUsages(null)
            ->setMaxUsagesPerUser(3)
            ->setPublicMessage('5€ de réduction immédiate !')
            ->setInternalNote('Promo récurrente 5€');

        $manager->persist($fixed5);

        // ===============================================
        // CODE VIP (20% - clients fidèles)
        // ===============================================
        $vip = new Coupon();
        $vip
            ->setSite($siteFr)
            ->setCode('VIP20')
            ->setType(Coupon::TYPE_PERCENTAGE)
            ->setValue(0.20) // 20%
            ->setMinimumAmount(100.00)
            ->setMaximumDiscount(50.00)
            ->setValidFrom(new \DateTimeImmutable('-1 month'))
            ->setValidUntil(new \DateTimeImmutable('+6 months'))
            ->setMaxUsages(100)
            ->setMaxUsagesPerUser(2)
            ->setPublicMessage('Code VIP : -20% réservé à nos clients fidèles.')
            ->setInternalNote('Campagne fidélisation VIP');

        $manager->persist($vip);

        // ===============================================
        // COUPON EXPIRÉ (pour tests)
        // ===============================================
        $expired = new Coupon();
        $expired
            ->setSite($siteFr)
            ->setCode('EXPIRED')
            ->setType(Coupon::TYPE_PERCENTAGE)
            ->setValue(0.10)
            ->setValidFrom(new \DateTimeImmutable('-2 months'))
            ->setValidUntil(new \DateTimeImmutable('-1 week'))
            ->setMaxUsages(100)
            ->setUsageCount(45)
            ->setPublicMessage('Code expiré (test)')
            ->setInternalNote('Coupon expiré pour tests');

        $manager->persist($expired);

        // ===============================================
        // COUPON ÉPUISÉ (pour tests)
        // ===============================================
        $exhausted = new Coupon();
        $exhausted
            ->setSite($siteFr)
            ->setCode('EXHAUSTED')
            ->setType(Coupon::TYPE_PERCENTAGE)
            ->setValue(0.10)
            ->setValidFrom(new \DateTimeImmutable('-1 month'))
            ->setValidUntil(new \DateTimeImmutable('+1 month'))
            ->setMaxUsages(50)
            ->setUsageCount(50) // Limite atteinte
            ->setPublicMessage('Code épuisé (test)')
            ->setInternalNote('Coupon épuisé pour tests');

        $manager->persist($exhausted);

        // ===============================================
        // COUPON B2B UNIQUEMENT
        // ===============================================
        $b2b = new Coupon();
        $b2b
            ->setSite($siteFr)
            ->setCode('PRO15')
            ->setType(Coupon::TYPE_PERCENTAGE)
            ->setValue(0.15)
            ->setMinimumAmount(200.00)
            ->setValidFrom(new \DateTimeImmutable('-1 month'))
            ->setValidUntil(new \DateTimeImmutable('+1 year'))
            ->setMaxUsages(null)
            ->setMaxUsagesPerUser(null)
            ->setAllowedCustomerTypes(['B2B'])
            ->setPublicMessage('Réduction professionnelle -15%')
            ->setInternalNote('Coupon réservé aux comptes B2B');

        $manager->persist($b2b);

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [
            SiteFixtures::class,
        ];
    }
}

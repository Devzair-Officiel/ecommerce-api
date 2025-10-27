<?php

declare(strict_types=1);

namespace App\DataFixtures\Wishlist;

use Faker\Factory;
use Faker\Generator;
use App\Entity\Site\Site;
use App\Entity\User\User;
use App\Entity\Wishlist\Wishlist;
use App\DataFixtures\Site\SiteFixtures;
use App\DataFixtures\User\UserFixtures;
use Doctrine\Persistence\ObjectManager;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;

/**
 * Fixtures pour Wishlist.
 * 
 * Règles métier :
 * - Wishlist UNIQUEMENT pour utilisateurs authentifiés (pas d'invités)
 * - 30% des utilisateurs ont une wishlist
 * - Pas de wishlists publiques/partagées (isPublic = false, shareToken = null)
 * - isDefault = true pour la première wishlist de chaque utilisateur
 * - Nom de wishlist non traduit (utilise la locale de l'utilisateur)
 * 
 * Scénarios de test inclus :
 * ✅ Wishlist avec items normaux
 * ✅ Wishlist vide (pour tester l'UI)
 * ✅ Wishlist avec produits épuisés (stock = 0)
 * ✅ Wishlist avec variantes supprimées (soft deleted)
 * ✅ Wishlist avec différentes priorités
 * ✅ Wishlist thématiques (Noël, Anniversaire, etc.)
 * ✅ Wishlist avec notes personnelles
 * 
 * Architecture :
 * - Dépend de SiteFixtures et UserFixtures
 * - Crée des références pour WishlistItemFixtures
 * - Gère les cas limites pour tests robustes
 */
class WishlistFixtures extends Fixture implements DependentFixtureInterface
{
    public const WISHLIST_REFERENCE_PREFIX = 'wishlist_';

    // Références spécifiques pour les tests
    public const WISHLIST_DEFAULT_USER_1 = 'wishlist_default_user_1';
    public const WISHLIST_EMPTY = 'wishlist_empty';
    public const WISHLIST_CHRISTMAS = 'wishlist_christmas';

    private Generator $faker;

    public function __construct()
    {
        $this->faker = Factory::create('fr_FR');
    }

    public function load(ObjectManager $manager): void
    {
        /** @var Site $siteFr */
        $siteFr = $this->getReference(SiteFixtures::SITE_FR_REFERENCE, Site::class);

        // Récupérer les utilisateurs existants
        $users = $this->getUsersForWishlists();

        // ===============================================
        // STRATÉGIE : 30% des users ont une wishlist
        // ===============================================
        $usersWithWishlist = [];
        $totalUsers = count($users);
        $targetWishlistUsers = (int) ceil($totalUsers * 0.30);

        // Sélectionner aléatoirement 30% des utilisateurs
        $userKeys = array_keys($users);
        shuffle($userKeys);
        $selectedUserKeys = array_slice($userKeys, 0, $targetWishlistUsers);

        foreach ($selectedUserKeys as $userKey) {
            $usersWithWishlist[$userKey] = $users[$userKey];
        }

        // ===============================================
        // WISHLISTS PRINCIPALES (utilisateurs sélectionnés)
        // ===============================================
        $wishlistCount = 0;
        foreach ($usersWithWishlist as $userKey => $user) {
            $wishlistCount++;

            // 80% ont une seule wishlist (défaut), 20% en ont plusieurs
            $numberOfWishlists = $this->faker->randomElement([1, 1, 1, 1, $this->faker->numberBetween(2, 3)]);

            for ($i = 0; $i < $numberOfWishlists; $i++) {
                $isFirstWishlist = ($i === 0);
                $wishlist = $this->createWishlist(
                    $siteFr,
                    $user,
                    $isFirstWishlist,
                    $wishlistCount,
                    $i
                );

                $manager->persist($wishlist);

                // Créer des références pour WishlistItemFixtures
                $reference = self::WISHLIST_REFERENCE_PREFIX . $wishlistCount;
                $this->addReference($reference, $wishlist);

                // Référence spéciale pour le premier utilisateur (pour tests)
                if ($userKey === 'user_1' && $isFirstWishlist) {
                    $this->addReference(self::WISHLIST_DEFAULT_USER_1, $wishlist);
                }
            }
        }

        // ===============================================
        // WISHLIST VIDE (cas limite - pour tests UI)
        // ===============================================
        /** @var User $userForEmpty */
        $userForEmpty = $this->getReference(UserFixtures::USER_CLIENT_FR_1, User::class);

        $emptyWishlist = new Wishlist();
        $emptyWishlist
            ->setName('Ma liste vide')
            ->setDescription('Wishlist vide pour tester l\'interface')
            ->setUser($userForEmpty)
            ->setSite($siteFr)
            ->setIsDefault(false)
            ->setIsPublic(false) // Jamais public selon les règles
            ->setShareToken(null); // Jamais de token selon les règles

        $manager->persist($emptyWishlist);
        $this->addReference(self::WISHLIST_EMPTY, $emptyWishlist);

        // ===============================================
        // WISHLIST THÉMATIQUE NOËL (pour tests)
        // ===============================================
        $christmasWishlist = new Wishlist();
        $christmasWishlist
            ->setName('Liste de Noël 2024')
            ->setDescription('Mes idées cadeaux pour Noël')
            ->setUser($userForEmpty)
            ->setSite($siteFr)
            ->setIsDefault(false)
            ->setIsPublic(false)
            ->setShareToken(null);

        $manager->persist($christmasWishlist);
        $this->addReference(self::WISHLIST_CHRISTMAS, $christmasWishlist);

        $manager->flush();
    }

    /**
     * Crée une wishlist avec des données réalistes.
     */
    private function createWishlist(
        Site $site,
        User $user,
        bool $isDefault,
        int $count,
        int $index
    ): Wishlist {
        $wishlist = new Wishlist();

        // Nom de wishlist réaliste
        $name = $this->generateWishlistName($isDefault, $index);

        // Description optionnelle (50% en ont une)
        $description = $this->faker->optional(0.5)->sentence(8);

        $wishlist
            ->setName($name)
            ->setDescription($description)
            ->setUser($user)
            ->setSite($site)
            ->setIsDefault($isDefault)
            ->setIsPublic(false) // ❌ Jamais public selon les règles
            ->setShareToken(null); // ❌ Jamais de token selon les règles

        return $wishlist;
    }

    /**
     * Génère un nom de wishlist réaliste.
     */
    private function generateWishlistName(bool $isDefault, int $index): string
    {
        // Wishlist par défaut : nom simple
        if ($isDefault) {
            return $this->faker->randomElement([
                'Ma liste de souhaits',
                'Mes envies',
                'Mes favoris',
                'Liste de favoris',
                'Wishlist',
            ]);
        }

        // Wishlists secondaires : noms thématiques
        $thematicNames = [
            'Liste de Noël',
            'Anniversaire',
            'Fête des mères',
            'Saint-Valentin',
            'Mariage',
            'Cadeau pour moi',
            'Idées cadeaux',
            'Pour plus tard',
            'Mes coups de cœur',
            'À acheter',
        ];

        return $this->faker->randomElement($thematicNames);
    }

    /**
     * Récupère les utilisateurs disponibles pour créer des wishlists.
     * 
     * Stratégie :
     * - Tente de récupérer 100 utilisateurs (user_client_fr_1 à user_client_fr_100)
     * - Si un utilisateur n'existe pas, on l'ignore
     * - Retourne tous les utilisateurs trouvés
     */
    private function getUsersForWishlists(): array
    {
        $users = [];

        // Tenter de récupérer les 100 premiers utilisateurs
        for ($i = 1; $i <= 100; $i++) {
            $userRef = 'user_client_fr_' . $i;

            try {
                if ($this->hasReference($userRef, User::class)) {
                    $user = $this->getReference($userRef, User::class);
                    $users['user_' . $i] = $user;
                }
            } catch (\Exception $e) {
                // Utilisateur non trouvé, on continue
                continue;
            }
        }

        // Fallback : si aucun utilisateur trouvé, utiliser USER_CLIENT_FR_1
        if (empty($users)) {
            try {
                $user = $this->getReference(UserFixtures::USER_CLIENT_FR_1, User::class);
                $users['user_1'] = $user;
            } catch (\Exception $e) {
                throw new \RuntimeException(
                    'Impossible de créer des wishlists : aucun utilisateur trouvé dans les fixtures.'
                );
            }
        }

        return $users;
    }

    /**
     * Définit les dépendances des fixtures.
     */
    public function getDependencies(): array
    {
        return [
            SiteFixtures::class,
            UserFixtures::class,
        ];
    }
}

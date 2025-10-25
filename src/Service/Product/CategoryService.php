<?php

declare(strict_types=1);

namespace App\Service\Product;

use App\Entity\Product\Category;
use App\Entity\Site\Site;
use App\Exception\BusinessRuleException;
use App\Exception\ConflictException;
use App\Repository\Product\CategoryRepository;
use App\Service\Core\AbstractService;
use App\Service\Core\RelationProcessor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Service métier pour la gestion des catégories.
 * 
 * Responsabilités :
 * - CRUD avec validation de la hiérarchie (max 2 niveaux)
 * - Vérification unicité slug par site + locale
 * - Gestion des relations parent/enfants
 * - Statistiques et recherches avancées
 */
class CategoryService extends AbstractService
{
    public function __construct(
        EntityManagerInterface $em,
        SerializerInterface $serializer,
        ValidatorInterface $validator,
        RelationProcessor $relationProcessor,
        private readonly CategoryRepository $categoryRepository
    ) {
        parent::__construct($em, $serializer, $validator, $relationProcessor);
    }

    protected function getEntityClass(): string
    {
        return Category::class;
    }

    protected function getRepository(): CategoryRepository
    {
        return $this->categoryRepository;
    }

    // ===============================================
    // CRÉATION AVEC CONTEXTE
    // ===============================================

    /**
     * Crée une catégorie avec validation du site et de la hiérarchie.
     */
    public function createCategory(array $data, Site $site): Category
    {
        return $this->createWithHooks($data, [
            'site' => $site,
            'validation_groups' => ['Default']
        ]);
    }

    /**
     * Met à jour une catégorie avec validation.
     */
    public function updateCategory(int $id, array $data): Category
    {
        return $this->updateWithHooks($id, $data);
    }

    // ===============================================
    // HOOKS MÉTIER
    // ===============================================

    protected function beforeCreate(object $entity, array $data, array $context): void
    {
        /** @var Category $entity */

        // Assigner le site depuis le contexte
        if (isset($context['site'])) {
            $entity->setSite($context['site']);
        }

        // Validation de la hiérarchie si parent fourni
        if (isset($data['parent']) && $data['parent'] !== null) {
            $this->validateHierarchy($entity, $data['parent']);
        }

        // Vérifier l'unicité du slug (sera généré par Gedmo avant persist)
        // On vérifie après le flush dans afterCreate
    }

    protected function afterCreate(object $entity, array $context): void
    {
        /** @var Category $entity */

        // Vérifier l'unicité du slug après génération par Gedmo
        if ($this->isSlugDuplicate($entity)) {
            throw new ConflictException(
                'Category',
                'slug',
                $entity->getSlug() . ' (pour ce site et cette langue)'
            );
        }
    }

    protected function beforeUpdate(object $entity, array $data, array $context): void
    {
        /** @var Category $entity */

        // Validation de la hiérarchie si parent modifié
        if (isset($data['parent'])) {
            $this->validateHierarchy($entity, $data['parent']);
        }

        // Empêcher le changement de site (sécurité)
        if (isset($data['site'])) {
            unset($data['site']);
        }

        // Empêcher le changement de locale (risque de slug en double)
        if (isset($data['locale']) && $data['locale'] !== $entity->getLocale()) {
            throw new BusinessRuleException(
                'locale_change_forbidden',
                'La langue d\'une catégorie ne peut pas être modifiée après sa création.'
            );
        }
    }

    protected function afterUpdate(object $entity, array $context): void
    {
        /** @var Category $entity */

        // Vérifier l'unicité du slug si le name a changé
        if (isset($context['previous_state'])) {
            /** @var Category $previousState */
            $previousState = $context['previous_state'];

            if ($previousState->getName() !== $entity->getName()) {
                if ($this->isSlugDuplicate($entity)) {
                    throw new ConflictException(
                        'Category',
                        'slug',
                        $entity->getSlug() . ' (pour ce site et cette langue)'
                    );
                }
            }
        }
    }

    protected function beforeDelete(object $entity, array $context): void
    {
        /** @var Category $entity */

        // Vérifier que la catégorie n'a pas d'enfants
        if ($entity->hasChildren()) {
            throw new BusinessRuleException(
                'category_has_children',
                'Impossible de supprimer une catégorie qui contient des sous-catégories.'
            );
        }

        // Optionnel : vérifier si la catégorie a des produits
        if ($entity->getProductCount() > 0) {
            throw new BusinessRuleException(
                'category_has_products',
                'Impossible de supprimer une catégorie qui contient des produits.'
            );
        }
    }

    // ===============================================
    // MÉTHODES MÉTIER SPÉCIALISÉES
    // ===============================================

    /**
     * Récupère l'arbre complet des catégories pour un site/locale.
     */
    public function getCategoryTree(Site $site, string $locale, bool $activeOnly = true): array
    {
        return $this->categoryRepository->findCategoryTree($site, $locale, $activeOnly);
    }

    /**
     * Récupère les catégories racines.
     */
    public function getRootCategories(Site $site, string $locale, bool $activeOnly = true): array
    {
        return $this->categoryRepository->findRootCategories($site, $locale, $activeOnly);
    }

    /**
     * Trouve une catégorie par son slug.
     */
    public function findBySlug(string $slug, Site $site, string $locale): ?Category
    {
        return $this->categoryRepository->findBySlug($slug, $site, $locale);
    }

    /**
     * Récupère les catégories avec leurs compteurs de produits.
     */
    public function getCategoriesWithProductCounts(Site $site, string $locale): array
    {
        return $this->categoryRepository->findWithProductCounts($site, $locale);
    }

    /**
     * Récupère les catégories populaires (avec le plus de produits).
     */
    public function getPopularCategories(Site $site, string $locale, int $limit = 10): array
    {
        return $this->categoryRepository->findPopularCategories($site, $locale, $limit);
    }

    /**
     * Réorganise les positions des catégories.
     * 
     * @param array $positions Format : [['id' => 1, 'position' => 0], ['id' => 2, 'position' => 1], ...]
     */
    public function reorderCategories(array $positions): void
    {
        foreach ($positions as $item) {
            if (!isset($item['id'], $item['position'])) {
                continue;
            }

            $category = $this->findEntityById($item['id']);
            $category->setPosition((int) $item['position']);
        }

        $this->em->flush();
    }

    /**
     * Déplace une catégorie vers un nouveau parent.
     */
    public function moveCategory(int $categoryId, ?int $newParentId): Category
    {
        $category = $this->findEntityById($categoryId);

        if ($newParentId === null) {
            $category->setParent(null);
        } else {
            $newParent = $this->findEntityById($newParentId);

            // Validation : empêcher de déplacer un parent sous son propre enfant
            if ($this->isDescendantOf($newParent, $category)) {
                throw new BusinessRuleException(
                    'circular_hierarchy',
                    'Impossible de déplacer une catégorie sous l\'une de ses propres sous-catégories.'
                );
            }

            $this->validateHierarchy($category, $newParentId);
            $category->setParent($newParent);
        }

        $this->em->flush();

        return $category;
    }

    /**
     * Clone une catégorie vers une autre locale.
     */
    public function cloneToLocale(int $categoryId, string $targetLocale): Category
    {
        $source = $this->findEntityById($categoryId);

        // Vérifier que la locale cible est différente
        if ($source->getLocale() === $targetLocale) {
            throw new BusinessRuleException(
                'same_locale',
                'La catégorie est déjà dans cette langue.'
            );
        }

        // Créer le clone
        $clone = new Category();
        $clone->setName($source->getName());
        $clone->setLocale($targetLocale);
        $clone->setSite($source->getSite());
        $clone->setDescription($source->getDescription());
        $clone->setPosition($source->getPosition());
        $clone->setImages($source->getImages());
        $clone->setMetaTitle($source->getMetaTitle());
        $clone->setMetaDescription($source->getMetaDescription());

        // Si le parent existe dans la locale cible, l'assigner
        if ($source->getParent() !== null) {
            $targetParent = $this->categoryRepository->findBySlug(
                $source->getParent()->getSlug(),
                $source->getSite(),
                $targetLocale
            );

            if ($targetParent) {
                $clone->setParent($targetParent);
            }
        }

        $this->em->persist($clone);
        $this->em->flush();

        return $clone;
    }

    // ===============================================
    // MÉTHODES DE VALIDATION PRIVÉES
    // ===============================================

    /**
     * Valide qu'une catégorie peut avoir ce parent (max 2 niveaux).
     */
    private function validateHierarchy(Category $category, ?int $parentId): void
    {
        if ($parentId === null) {
            return;
        }

        $parent = $this->findEntityById($parentId);

        // Un parent ne peut pas avoir lui-même de parent (max 2 niveaux)
        if ($parent->getParent() !== null) {
            throw new BusinessRuleException(
                'max_hierarchy_depth',
                'Les catégories ne peuvent avoir que 2 niveaux maximum (parent > enfant).'
            );
        }

        // Vérifier que le parent est du même site et de la même locale
        if ($parent->getSite()->getId() !== $category->getSite()->getId()) {
            throw new BusinessRuleException(
                'parent_different_site',
                'Le parent doit appartenir au même site.'
            );
        }

        if ($parent->getLocale() !== $category->getLocale()) {
            throw new BusinessRuleException(
                'parent_different_locale',
                'Le parent doit être dans la même langue.'
            );
        }
    }

    /**
     * Vérifie si le slug existe déjà pour ce site + locale.
     */
    private function isSlugDuplicate(Category $category): bool
    {
        return $this->categoryRepository->isSlugTaken(
            $category->getSlug(),
            $category->getSite(),
            $category->getLocale(),
            $category->getId()
        );
    }

    /**
     * Vérifie si une catégorie est descendante d'une autre.
     */
    private function isDescendantOf(Category $category, Category $potentialAncestor): bool
    {
        $parent = $category->getParent();

        while ($parent !== null) {
            if ($parent->getId() === $potentialAncestor->getId()) {
                return true;
            }
            $parent = $parent->getParent();
        }

        return false;
    }

    // ===============================================
    // CONFIGURATION DES RELATIONS
    // ===============================================

    protected function getRelationConfig(): array
    {
        return [
            'parent' => [
                'type' => 'many_to_one',
                'target_entity' => Category::class,
            ],
        ];
    }
}

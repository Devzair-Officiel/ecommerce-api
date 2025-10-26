<?php

declare(strict_types=1);

namespace App\Service\Product;

use App\Entity\Product\Product;
use App\Entity\Product\ProductVariant;
use App\Entity\Site\Site;
use App\Exception\BusinessRuleException;
use App\Exception\ValidationException;
use App\Repository\Product\ProductRepository;
use App\Service\Core\AbstractService;
use App\Service\Core\RelationProcessor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Service métier pour la gestion des produits.
 * 
 * Responsabilités :
 * - CRUD produits avec variantes
 * - Gestion des prix différenciés
 * - Validation stock avant opérations
 * - Génération SEO automatique
 */
class ProductService extends AbstractService
{
    public function __construct(
        EntityManagerInterface $em,
        SerializerInterface $serializer,
        ValidatorInterface $validator,
        RelationProcessor $relationProcessor,
        private readonly ProductRepository $productRepository
    ) {
        parent::__construct($em, $serializer, $validator, $relationProcessor);
    }

    protected function getEntityClass(): string
    {
        return Product::class;
    }

    protected function getRepository(): ProductRepository
    {
        return $this->productRepository;
    }

    // ===============================================
    // HOOKS MÉTIER
    // ===============================================

    protected function beforeCreate(object $entity, array $data, array $context): void
    {
        /** @var Product $entity */

        // Assigner le site depuis le contexte
        if (isset($context['site'])) {
            $entity->setSite($context['site']);
        }

        // Générer automatiquement les champs SEO si absents
        $this->generateSeoFieldsIfMissing($entity);

        // Valider le SKU unique
        if ($this->productRepository->findBySku($entity->getSku(), $entity->getSite())) {
            throw new BusinessRuleException(
                'sku_exists',
                sprintf('Le SKU "%s" existe déjà pour ce site.', $entity->getSku())
            );
        }
    }

    protected function beforeUpdate(object $entity, array $data, array $context): void
    {
        /** @var Product $entity */

        // Vérifier unicité SKU si modifié
        if (isset($data['sku']) && $data['sku'] !== $entity->getSku()) {
            $existing = $this->productRepository->findBySku($data['sku'], $entity->getSite());
            if ($existing && $existing->getId() !== $entity->getId()) {
                throw new BusinessRuleException(
                    'sku_exists',
                    sprintf('Le SKU "%s" existe déjà pour ce site.', $data['sku'])
                );
            }
        }

        // Régénérer SEO si nom/description modifiés
        if (isset($data['name']) || isset($data['description'])) {
            $this->generateSeoFieldsIfMissing($entity);
        }
    }

    protected function afterCreate(object $entity, array $context): void
    {
        /** @var Product $entity */

        // Si des variantes sont fournies dans le contexte, les créer
        if (isset($context['variants']) && is_array($context['variants'])) {
            $this->createVariantsForProduct($entity, $context['variants']);
        }
    }

    // ===============================================
    // MÉTHODES MÉTIER SPÉCIFIQUES
    // ===============================================

    /**
     * Crée un produit avec ses variantes en une seule transaction
     */
    public function createProductWithVariants(array $productData, array $variantsData, Site $site): Product
    {
        return $this->em->wrapInTransaction(function () use ($productData, $variantsData, $site) {
            // Créer le produit parent
            $product = $this->createWithHooks($productData, [
                'site' => $site,
                'validation_groups' => ['Default', 'product:create']
            ]);

            // Créer les variantes
            if (!empty($variantsData)) {
                $this->createVariantsForProduct($product, $variantsData);
            }

            return $product;
        });
    }

    /**
     * Met à jour un produit et ses variantes
     */
    public function updateProductWithVariants(int $id, array $productData, ?array $variantsData = null): Product
    {
        return $this->em->wrapInTransaction(function () use ($id, $productData, $variantsData) {
            // Mettre à jour le produit
            $product = $this->updateWithHooks($id, $productData);

            // Mettre à jour les variantes si fournies
            if ($variantsData !== null) {
                $this->syncVariants($product, $variantsData);
            }

            return $product;
        });
    }

    /**
     * Crée des variantes pour un produit
     */
    private function createVariantsForProduct(Product $product, array $variantsData): void
    {
        foreach ($variantsData as $position => $variantData) {
            $variant = new ProductVariant();

            // Désérialiser les données
            $this->serializer->deserialize(
                json_encode($variantData),
                ProductVariant::class,
                'json',
                ['object_to_populate' => $variant]
            );

            // Assigner le produit parent
            $variant->setProduct($product);
            $variant->setPosition($position);

            // Valider
            $this->validateEntity($variant);

            $this->em->persist($variant);
        }

        $this->em->flush();
    }

    /**
     * Synchronise les variantes d'un produit (ajout/modification/suppression)
     */
    private function syncVariants(Product $product, array $variantsData): void
    {
        $existingVariants = [];
        foreach ($product->getVariants() as $variant) {
            $existingVariants[$variant->getId()] = $variant;
        }

        $processedIds = [];

        foreach ($variantsData as $variantData) {
            $variantId = $variantData['id'] ?? null;

            if ($variantId && isset($existingVariants[$variantId])) {
                // Mise à jour variante existante
                $variant = $existingVariants[$variantId];

                $this->serializer->deserialize(
                    json_encode($variantData),
                    ProductVariant::class,
                    'json',
                    ['object_to_populate' => $variant]
                );

                $this->validateEntity($variant);
                $processedIds[] = $variantId;
            } else {
                // Création nouvelle variante
                $variant = new ProductVariant();

                $this->serializer->deserialize(
                    json_encode($variantData),
                    ProductVariant::class,
                    'json',
                    ['object_to_populate' => $variant]
                );

                $variant->setProduct($product);
                $this->validateEntity($variant);
                $this->em->persist($variant);
            }
        }

        // Supprimer les variantes non présentes dans la requête
        foreach ($existingVariants as $id => $variant) {
            if (!in_array($id, $processedIds, true)) {
                $this->em->remove($variant);
            }
        }

        $this->em->flush();
    }

    /**
     * Récupère les produits avec filtres e-commerce
     */
    public function searchProducts(int $page, int $limit, array $filters = []): array
    {
        return $this->productRepository->findWithPagination($page, $limit, $filters);
    }

    /**
     * Trouve un produit par slug
     */
    public function findBySlug(string $slug, Site $site, ?string $locale = null): ?Product
    {
        return $this->productRepository->findBySlugAndSite($slug, $site, $locale);
    }

    /**
     * Récupère les produits mis en avant
     */
    public function getFeaturedProducts(Site $site, ?string $locale = null, int $limit = 10): array
    {
        return $this->productRepository->findFeaturedProducts($site, $locale, $limit);
    }

    /**
     * Récupère les nouveautés
     */
    public function getNewProducts(Site $site, ?string $locale = null, int $limit = 10): array
    {
        return $this->productRepository->findNewProducts($site, $locale, $limit);
    }

    /**
     * Récupère les produits similaires
     */
    public function getSimilarProducts(Product $product, int $limit = 4): array
    {
        return $this->productRepository->findSimilarProducts($product, $limit);
    }

    /**
     * Vérifie la disponibilité d'une variante
     */
    public function checkVariantAvailability(ProductVariant $variant, int $quantity = 1): bool
    {
        if (!$variant->isActive() || $variant->isDeleted()) {
            return false;
        }

        return $variant->hasQuantityAvailable($quantity);
    }

    /**
     * Décrémente le stock d'une variante (avec validation)
     */
    public function decrementVariantStock(ProductVariant $variant, int $quantity): void
    {
        if (!$this->checkVariantAvailability($variant, $quantity)) {
            throw new BusinessRuleException(
                'insufficient_stock',
                sprintf(
                    'Stock insuffisant pour "%s" (demandé: %d, disponible: %d)',
                    $variant->getName(),
                    $quantity,
                    $variant->getStock()
                )
            );
        }

        $variant->decrementStock($quantity);
        $this->em->flush();
    }

    /**
     * Incrémente le stock d'une variante
     */
    public function incrementVariantStock(ProductVariant $variant, int $quantity): void
    {
        $variant->incrementStock($quantity);
        $this->em->flush();
    }

    /**
     * Active/désactive un produit
     */
    public function toggleProductStatus(int $id, bool $active): Product
    {
        return $this->toggleStatus($id, $active);
    }

    /**
     * Génère les structured data JSON-LD pour un produit
     */
    public function generateStructuredData(Product $product): array
    {
        $defaultVariant = $product->getDefaultVariant();
        $currency = $product->getSite()->getCurrency();

        $structuredData = [
            '@context' => 'https://schema.org/',
            '@type' => 'Product',
            'name' => $product->getName(),
            'description' => $product->getShortDescription() ?? $product->getDescription(),
            'sku' => $product->getSku(),
            'brand' => [
                '@type' => 'Brand',
                'name' => $product->getSite()->getName()
            ]
        ];

        // Image principale
        $mainImage = $product->getMainImage();
        if ($mainImage) {
            $structuredData['image'] = $mainImage['url'];
        }

        // Prix (variante par défaut)
        if ($defaultVariant) {
            $price = $defaultVariant->getPriceFor($currency, 'B2C');
            if ($price !== null) {
                $structuredData['offers'] = [
                    '@type' => 'Offer',
                    'price' => $price,
                    'priceCurrency' => $currency,
                    'availability' => $defaultVariant->isInStock()
                        ? 'https://schema.org/InStock'
                        : 'https://schema.org/OutOfStock',
                    'url' => $product->getUrl()
                ];
            }
        }

        return $structuredData;
    }

    // ===============================================
    // HELPERS PRIVÉS
    // ===============================================

    /**
     * Génère les champs SEO automatiquement si manquants
     */
    private function generateSeoFieldsIfMissing(Product $entity): void
    {
        // Meta title (max 70 caractères)
        if (empty($entity->getMetaTitle())) {
            $metaTitle = mb_substr($entity->getName(), 0, 70);
            $entity->setMetaTitle($metaTitle);
        }

        // Meta description (max 160 caractères)
        if (empty($entity->getMetaDescription())) {
            $description = $entity->getShortDescription() ?? $entity->getDescription() ?? '';
            $metaDescription = mb_substr(strip_tags($description), 0, 160);
            $entity->setMetaDescription($metaDescription);
        }

        // Structured data
        if (empty($entity->getStructuredData())) {
            // Sera généré après la création (car besoin des variantes)
            // On le laisse null pour l'instant
        }
    }

    /**
     * Configuration des relations (catégories)
     */
    protected function getRelationConfig(): array
    {
        return [
            'categories' => [
                'type' => 'many_to_many',
                'target_entity' => \App\Entity\Product\Category::class,
                'identifier_key' => 'id'
            ]
        ];
    }
}

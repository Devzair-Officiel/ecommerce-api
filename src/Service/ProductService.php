<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Site;
use App\Entity\Product;
use App\Entity\Category;
use App\Entity\ProductVariant;
use App\Service\AbstractService;
use App\Utils\JsonValidationUtils;
use App\Utils\DeserializationUtils;
use App\Repository\ProductRepository;
use Doctrine\ORM\EntityManagerInterface;
use App\Exception\EntityNotFoundException;
use App\Service\Processing\EntityProcessor;
use App\Service\Processing\RelationManager;
use App\Repository\Interface\ProductRepositoryInterface;

/**
 * Service métier pour la gestion des produits e-commerce.
 *
 * Ce service orchestre toutes les opérations CRUD liées aux produits
 * en centralisant la logique métier spécifique à cette entité.
 *
 * Responsabilités :
 * - Création et mise à jour de produits avec leurs relations complexes
 * - Gestion des relations multiples : site, catégories, variantes
 * - Application des règles métier spécifiques aux produits
 * - Validation des contraintes business (stock, prix, etc.)
 * - Interface pour les opérations de recherche et filtrage
 *
 * Relations gérées automatiquement :
 * - Site (ManyToOne) : rattachement obligatoire à un site
 * - Categories (ManyToMany) : classification flexible du produit  
 * - Variants (OneToMany) : gestion des tailles, couleurs, etc.
 *
 * Architecture :
 * - Hérite d'AbstractService pour les opérations de base
 * - Utilise RelationManager pour la gestion automatique des associations
 * - Configuration explicite des relations pour une maintenance aisée
 * - Separation claire entre logique métier et logique technique
 */
class ProductService extends AbstractService
{
    public function __construct(
        EntityManagerInterface $em,
        EntityProcessor $entityProcessor,
        RelationManager $relationManager,
        private readonly ProductRepositoryInterface $productRepository
    ) {
        parent::__construct($em, $entityProcessor, $relationManager);
    }

    public function createProduct(string $jsonData): Product
    {
        return $this->executeInTransaction(function () use ($jsonData) {
            // 1. Validation et hydratation (constraints d'entité + slug automatique)
            $product = $this->processEntityFromJson($jsonData, Product::class);

            // 2. Traitement des relations
            $data = json_decode($jsonData, true);
            $this->processRelations($product, $this->getRelationConfig(), $data);

            // 3. Persistance (dates automatiques via DateTrait)
            $this->saveEntity($product);

            return $product;
        });
    }

    public function updateProduct(int $id, string $jsonData): Product
    {
        return $this->executeInTransaction(function () use ($id, $jsonData) {
            $product = $this->findEntityByCriteria(Product::class, ['id' => $id]);

            $this->processEntityFromJson($jsonData, Product::class, $product);

            $data = json_decode($jsonData, true);
            $this->updateRelations($product, $this->getRelationConfig(), $data);

            $this->saveEntity($product);

            return $product;
        });
    }

    public function getProduct(int $id): Product
    {
        return $this->findEntityByCriteria(Product::class, ['id' => $id]);
    }

    public function getProductBySlug(string $slug, int $siteId): Product
    {
        $product = $this->productRepository->findBySlugWithSite($slug, $siteId);

        if (!$product) {
            throw new EntityNotFoundException('product', ['slug' => $slug, 'site' => $siteId]);
        }

        return $product;
    }

    public function deleteProduct(int $id): Product
    {
        return $this->executeInTransaction(function () use ($id) {
            $product = $this->findEntityByCriteria(Product::class, ['id' => $id]);

            $this->validateDeletion($product);

            $this->removeEntity($product);

            return $product;
        });
    }

    public function toggleStatus(int $id, bool $active): Product
    {
        $product = $this->findEntityByCriteria(Product::class, ['id' => $id]);

        $product->setIsActive($active);
        $this->saveEntity($product);

        return $product;
    }

    public function updateStock(int $id, int $stock): Product
    {
        $product = $this->findEntityByCriteria(Product::class, ['id' => $id]);

        $product->setStock($stock); // Validation dans l'entité
        $this->saveEntity($product);

        return $product;
    }

    public function adjustStock(int $id, int $adjustment): Product
    {
        $product = $this->findEntityByCriteria(Product::class, ['id' => $id]);

        $product->adjustStock($adjustment); // Méthode métier de l'entité
        $this->saveEntity($product);

        return $product;
    }

    // === VALIDATION MÉTIER ===

    private function validateDeletion(Product $product): void
    {
        if ($this->productRepository->hasActiveOrders($product)) {
            throw new \DomainException('Cannot delete product with active orders');
        }
    }

    private function getRelationConfig(): array
    {
        return [
            'categories' => [
                'targetEntity' => Category::class,
                'identifierKey' => 'id',
                'allowCreation' => false
            ],
            'site' => [
                'targetEntity' => Site::class,
                'identifierKey' => 'id',
                'allowCreation' => false
            ]
        ];
    }
}
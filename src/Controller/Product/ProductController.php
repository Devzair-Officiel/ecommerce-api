<?php

declare(strict_types=1);

namespace App\Controller\Product;

use App\Controller\Core\AbstractApiController;
use App\Entity\Site\Site;
use App\Exception\ValidationException;
use App\Repository\Site\SiteRepository;
use App\Service\Product\ProductService;
use App\Utils\ApiResponseUtils;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * Contrôleur pour la gestion des produits via API REST.
 * 
 * Endpoints :
 * - CRUD standard (admin)
 * - Consultation publique (liste, détail, featured, nouveautés)
 * - Gestion des variantes
 * - Endpoints spéciaux : similaires, recherche avancée
 */
#[Route('/products', name: 'api_products_')]
class ProductController extends AbstractApiController
{
    public function __construct(
        ApiResponseUtils $apiResponseUtils,
        SerializerInterface $serializer,
        private readonly ProductService $productService,
        private readonly SiteRepository $siteRepository,
        private EntityManagerInterface $em
    ) {
        parent::__construct($apiResponseUtils, $serializer);
    }

    // ===============================================
    // ENDPOINTS PUBLICS (CONSULTATION)
    // ===============================================

    /**
     * Liste paginée des produits (PUBLIC).
     * 
     * Query params :
     * - page, limit : Pagination
     * - search : Recherche textuelle
     * - category_id / category_slug : Filtrer par catégorie
     * - customer_type : B2C, B2B, BOTH
     * - is_featured : true/false
     * - is_new : true/false
     * - has_stock : true/false (produits disponibles uniquement)
     * - locale : fr/en/es
     * - sortBy, sortOrder : Tri
     */
    #[Route('', name: 'list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $pagination = $this->getPaginationParams($request);
        $filters = $this->extractFilters($request);

        // Ajouter le site depuis le contexte (multi-tenant)
        $site = $this->getCurrentSite($request);
        if ($site) {
            $filters['site_id'] = $site->getId();
        }

        $result = $this->productService->searchProducts(
            $pagination['page'],
            $pagination['limit'],
            $filters
        );

        return $this->listResponse(
            $result,
            ['product:list', 'date', 'active_state', 'site', 'product:variants'],
            'product',
            'api_products_list'
        );
    }

    /**
     * Détail d'un produit par ID (PUBLIC).
     */
    #[Route('/{id}', name: 'show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id): JsonResponse
    {
        $product = $this->productService->findEntityById($id);

        return $this->showResponse(
            $product,
            ['product:read', 'product:variants', 'product:categories', 'date', 'site', 'seo'],
            'product'
        );
    }

    /**
     * Détail d'un produit par slug (PUBLIC).
     * URL : /products/{slug}
     */
    #[Route('/slug/{slug}', name: 'show_by_slug', methods: ['GET'])]
    public function showBySlug(string $slug, Request $request): JsonResponse
    {
        $site = $this->getCurrentSite($request);
        $locale = $request->query->get('locale', 'fr');

        $product = $this->productService->findBySlug($slug, $site, $locale);

        if (!$product) {
            return $this->notFoundError('product', ['slug' => $slug]);
        }

        return $this->showResponse(
            $product,
            ['product:read', 'product:variants', 'product:categories', 'date', 'site', 'seo'],
            'product'
        );
    }

    /**
     * Produits mis en avant (PUBLIC).
     * URL : /products/featured
     */
    #[Route('/featured', name: 'featured', methods: ['GET'])]
    public function featured(Request $request): JsonResponse
    {
        $site = $this->getCurrentSite($request);
        $locale = $request->query->get('locale', 'fr');
        $limit = min((int) $request->query->get('limit', 10), 50);

        $products = $this->productService->getFeaturedProducts($site, $locale, $limit);

        return $this->apiResponseUtils->success(
            data: $this->serialize($products, ['product:list', 'product:variants', 'date']),
            messageKey: 'entity.list_retrieved',
            entityKey: 'product'
        );
    }

    /**
     * Nouveautés (PUBLIC).
     * URL : /products/new
     */
    #[Route('/new', name: 'new', methods: ['GET'])]
    public function newProducts(Request $request): JsonResponse
    {
        $site = $this->getCurrentSite($request);
        $locale = $request->query->get('locale', 'fr');
        $limit = min((int) $request->query->get('limit', 10), 50);

        $products = $this->productService->getNewProducts($site, $locale, $limit);

        return $this->apiResponseUtils->success(
            data: $this->serialize($products, ['product:list', 'product:variants', 'date']),
            messageKey: 'entity.list_retrieved',
            entityKey: 'product'
        );
    }

    /**
     * Produits similaires (PUBLIC).
     * URL : /products/{id}/similar
     */
    #[Route('/{id}/similar', name: 'similar', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function similar(int $id, Request $request): JsonResponse
    {
        $product = $this->productService->findEntityById($id);
        $limit = min((int) $request->query->get('limit', 4), 20);

        $similarProducts = $this->productService->getSimilarProducts($product, $limit);

        return $this->apiResponseUtils->success(
            data: $this->serialize($similarProducts, ['product:list', 'product:variants', 'date']),
            messageKey: 'entity.list_retrieved',
            entityKey: 'product'
        );
    }

    // ===============================================
    // ENDPOINTS ADMIN (GESTION)
    // ===============================================

    /**
     * Création d'un produit avec ses variantes (ADMIN).
     * 
     * Body JSON :
     * {
     *   "product": { "name": "Miel Bio", "sku": "MIEL-001", ... },
     *   "variants": [
     *     { "name": "250g", "sku": "MIEL-001-250G", "prices": {...}, "stock": 100 },
     *     { "name": "500g", "sku": "MIEL-001-500G", "prices": {...}, "stock": 50 }
     *   ],
     *   "category_ids": [1, 2]
     * }
     */
    #[Route('', name: 'create', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function create(Request $request): JsonResponse
    {
        $data = $this->getJsonData($request);

        try {
            $this->requireFields($data, ['product'], 'Les données produit sont requises');

            $productData = $data['product'];
            $variantsData = $data['variants'] ?? [];
            $categoryIds = $data['category_ids'] ?? [];

            // Ajouter les catégories aux données produit
            if (!empty($categoryIds)) {
                $productData['categories'] = array_map(fn($id) => ['id' => $id], $categoryIds);
            }

            $site = $this->getCurrentSite($request);

            $product = $this->productService->createProductWithVariants(
                $productData,
                $variantsData,
                $site
            );

            return $this->createResponse(
                $product,
                ['product:read', 'product:variants', 'date'],
                'product'
            );
        } catch (ValidationException $e) {
            return $this->validationError($e->getFormattedErrors());
        } catch (\InvalidArgumentException $e) {
            return $this->apiResponseUtils->error(
                errors: [['message' => $e->getMessage()]],
                messageKey: 'validation.failed',
                status: 400
            );
        }
    }

    /**
     * Mise à jour d'un produit (ADMIN).
     * 
     * Body JSON : Mêmes champs que create (tous optionnels)
     */
    #[Route('/{id}', name: 'update', methods: ['PUT', 'PATCH'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_ADMIN')]
    public function update(int $id, Request $request): JsonResponse
    {
        $data = $this->getJsonData($request);

        try {
            $productData = $data['product'] ?? $data;
            $variantsData = $data['variants'] ?? null;
            $categoryIds = $data['category_ids'] ?? null;

            // Ajouter les catégories si fournies
            if ($categoryIds !== null) {
                $productData['categories'] = array_map(fn($id) => ['id' => $id], $categoryIds);
            }

            $product = $this->productService->updateProductWithVariants(
                $id,
                $productData,
                $variantsData
            );

            return $this->updateResponse(
                $product,
                ['product:read', 'product:variants', 'date'],
                'product'
            );
        } catch (ValidationException $e) {
            return $this->validationError($e->getFormattedErrors());
        }
    }

    /**
     * Suppression d'un produit (soft delete) (ADMIN).
     */
    #[Route('/{id}', name: 'delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(int $id): JsonResponse
    {
        $product = $this->productService->delete($id);

        return $this->deleteResponse(
            ['id' => $id, 'name' => $product->getName()],
            'product'
        );
    }

    /**
     * Activation/désactivation d'un produit (ADMIN).
     * 
     * Body : { "active": true }
     */
    #[Route('/{id}/status', name: 'toggle_status', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_ADMIN')]
    public function toggleStatus(int $id, Request $request): JsonResponse
    {
        $data = $this->getJsonData($request);
        $active = $this->getBooleanValue($data, 'active', false);

        $product = $this->productService->toggleProductStatus($id, $active);

        return $this->statusReponse(
            ['id' => $id, 'name' => $product->getName(), 'active' => $product->isActive()],
            'product',
            $active
        );
    }

    // ===============================================
    // ENDPOINTS VARIANTES
    // ===============================================

    /**
     * Liste des variantes d'un produit (PUBLIC).
     * URL : /products/{id}/variants
     */
    #[Route('/{id}/variants', name: 'variants', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function variants(int $id): JsonResponse
    {
        $product = $this->productService->findEntityById($id);

        return $this->apiResponseUtils->success(
            data: $this->serialize($product->getVariants(), ['variant:read', 'variant:list', 'date']),
            messageKey: 'entity.list_retrieved',
            entityKey: 'variant'
        );
    }

    /**
     * Vérifier la disponibilité d'une variante (PUBLIC).
     * URL : /products/variants/{variantId}/availability
     * 
     * Query param : quantity (défaut: 1)
     */
    #[Route('/variants/{variantId}/availability', name: 'variant_availability', methods: ['GET'], requirements: ['variantId' => '\d+'])]
    public function checkVariantAvailability(int $variantId, Request $request): JsonResponse
    {
        $quantity = max(1, (int) $request->query->get('quantity', 1));

        $variant = $this->em->find(\App\Entity\Product\ProductVariant::class, $variantId);

        if (!$variant) {
            return $this->notFoundError('variant', ['id' => $variantId]);
        }

        $available = $this->productService->checkVariantAvailability($variant, $quantity);

        return $this->apiResponseUtils->success(
            data: [
                'variant_id' => $variantId,
                'quantity_requested' => $quantity,
                'available' => $available,
                'stock' => $variant->getStock(),
                'status' => $variant->getStockStatus()
            ],
            messageKey: 'product.stock_checked'
        );
    }

    /**
     * Mise à jour du stock d'une variante (ADMIN).
     * 
     * Body : { "stock": 50 } ou { "increment": 10 } ou { "decrement": 5 }
     */
    #[Route('/variants/{variantId}/stock', name: 'update_variant_stock', methods: ['PATCH'], requirements: ['variantId' => '\d+'])]
    #[IsGranted('ROLE_ADMIN')]
    public function updateVariantStock(int $variantId, Request $request): JsonResponse
    {
        $data = $this->getJsonData($request);

        $variant = $this->em->find(\App\Entity\Product\ProductVariant::class, $variantId);

        if (!$variant) {
            return $this->notFoundError('variant', ['id' => $variantId]);
        }

        try {
            if (isset($data['stock'])) {
                // Définir stock absolu
                $variant->setStock((int) $data['stock']);
            } elseif (isset($data['increment'])) {
                // Incrémenter
                $this->productService->incrementVariantStock($variant, (int) $data['increment']);
            } elseif (isset($data['decrement'])) {
                // Décrémenter
                $this->productService->decrementVariantStock($variant, (int) $data['decrement']);
            } else {
                return $this->apiResponseUtils->error(
                    errors: [['message' => 'Paramètre requis : stock, increment ou decrement']],
                    messageKey: 'validation.required_field',
                    status: 400
                );
            }

            $this->em->flush();

            return $this->apiResponseUtils->success(
                data: [
                    'variant_id' => $variantId,
                    'stock' => $variant->getStock(),
                    'status' => $variant->getStockStatus()
                ],
                messageKey: 'product.stock_updated'
            );
        } catch (\Exception $e) {
            return $this->apiResponseUtils->error(
                errors: [['message' => $e->getMessage()]],
                messageKey: 'error.default',
                status: 400
            );
        }
    }

    // ===============================================
    // HELPERS
    // ===============================================

    /**
     * Récupère le site courant depuis la requête (multi-tenant).
     */
    private function getCurrentSite(Request $request): Site
    {
        // Option 1 : Depuis le domaine (recommandé en production)
        // $domain = $request->getHost();
        // return $this->siteRepository->findByDomain($domain);

        // Option 2 : Depuis un header custom
        // $siteCode = $request->headers->get('X-Site-Code');
        // return $this->siteRepository->findByCode($siteCode);

        // Option 3 : Depuis query param (temporaire développement)
        $siteCode = $request->query->get('site', 'FR');
        $site = $this->siteRepository->findByCode($siteCode);

        if (!$site) {
            // Fallback : premier site actif
            $sites = $this->siteRepository->findAccessibleSites();
            $site = $sites[0] ?? throw new \RuntimeException('Aucun site disponible.');
        }

        return $site;
    }
}

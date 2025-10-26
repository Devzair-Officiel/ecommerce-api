<?php

declare(strict_types=1);

namespace App\Controller\Product;

use App\Controller\Core\AbstractApiController;
use App\Entity\Site\Site;
use App\Exception\BusinessRuleException;
use App\Exception\ConflictException;
use App\Exception\ValidationException;
use App\Repository\Site\SiteRepository;
use App\Service\Product\CategoryService;
use App\Utils\ApiResponseUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * Contrôleur pour la gestion des catégories via API REST.
 * 
 * Endpoints :
 * - CRUD standard (admin)
 * - Endpoints publics : list, tree, show
 * - Endpoints spécialisés : reorder, move, clone
 */
#[Route('/categories', name: 'api_categories_')]
class CategoryController extends AbstractApiController
{
    public function __construct(
        ApiResponseUtils $apiResponseUtils,
        SerializerInterface $serializer,
        private readonly CategoryService $categoryService,
        private readonly SiteRepository $siteRepository
    ) {
        parent::__construct($apiResponseUtils, $serializer);
    }

    // ===============================================
    // ENDPOINTS PUBLICS
    // ===============================================

    /**
     * Liste paginée des catégories.
     * 
     * Filtres supportés :
     * - search : Recherche textuelle
     * - locale : Langue (fr, en, es)
     * - parent_id : Catégories d'un parent spécifique (null = racines)
     * - active_only : Catégories actives uniquement
     * - with_products : Uniquement les catégories ayant des produits
     * - sortBy / sortOrder : Tri personnalisé
     * 
     * GET /api/v1/categories?locale=fr&active_only=true&page=1&limit=20
     */
    #[Route('', name: 'list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $pagination = $this->getPaginationParams($request);
        $filters = $this->extractFilters($request);

        // Ajouter le site au filtre
        $site = $this->getSiteFromRequest($request);
        $filters['site'] = $site->getId();

        $result = $this->categoryService->search(
            $pagination['page'],
            $pagination['limit'],
            $filters
        );

        return $this->listResponse(
            $result,
            ['category:list', 'date', 'category:parent'],
            'category',
            'api_categories_list'
        );
    }

    /**
     * Arbre hiérarchique des catégories.
     * 
     * Retourne les catégories racines avec leurs enfants.
     * 
     * GET /api/v1/categories/tree?locale=fr
     */
    #[Route('/tree', name: 'tree', methods: ['GET'])]
    public function tree(Request $request): JsonResponse
    {
        $locale = $request->query->get('locale', 'fr');
        $activeOnly = filter_var(
            $request->query->get('active_only', 'true'),
            FILTER_VALIDATE_BOOLEAN
        );

        $site = $this->getSiteFromRequest($request);
        $tree = $this->categoryService->getCategoryTree($site, $locale, $activeOnly);

        $serialized = $this->serialize($tree, ['category:list', 'category:children', 'date']);

        return $this->apiResponseUtils->success(
            data: $serialized,
            messageKey: 'category.tree_retrieved',
            entityKey: 'category'
        );
    }

    /**
     * Catégories populaires (avec le plus de produits).
     * 
     * GET /api/v1/categories/popular?locale=fr&limit=10
     */
    #[Route('/popular', name: 'popular', methods: ['GET'])]
    public function popular(Request $request): JsonResponse
    {
        $locale = $request->query->get('locale', 'fr');
        $limit = min(50, max(1, (int) $request->query->get('limit', 10)));

        $site = $this->getSiteFromRequest($request);
        $categories = $this->categoryService->getPopularCategories($site, $locale, $limit);

        $serialized = $this->serialize($categories, ['category:list', 'date']);

        return $this->apiResponseUtils->success(
            data: $serialized,
            messageKey: 'category.popular_retrieved',
            entityKey: 'category'
        );
    }

    /**
     * Détail d'une catégorie.
     * 
     * GET /api/v1/categories/{id}
     */
    #[Route('/{id}', name: 'show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id): JsonResponse
    {
        $category = $this->categoryService->findEntityById($id);

        return $this->showResponse(
            $category,
            ['category:read', 'category:children', 'date', 'seo'],
            'category'
        );
    }

    /**
     * Détail d'une catégorie par slug.
     * 
     * GET /api/v1/categories/slug/{slug}?locale=fr
     */
    #[Route('/slug/{slug}', name: 'show_by_slug', methods: ['GET'])]
    public function showBySlug(string $slug, Request $request): JsonResponse
    {
        $locale = $request->query->get('locale', 'fr');
        $site = $this->getSiteFromRequest($request);

        $category = $this->categoryService->findBySlug($slug, $site, $locale);

        if (!$category) {
            return $this->notFoundError('category', ['slug' => $slug, 'locale' => $locale]);
        }

        return $this->showResponse(
            $category,
            ['category:read', 'category:parent', 'category:children', 'date', 'seo'],
            'category'
        );
    }

    // ===============================================
    // ENDPOINTS ADMIN (CRUD)
    // ===============================================

    /**
     * Création d'une catégorie.
     * 
     * Body :
     * {
     *   "name": "category.name.new_category",
     *   "locale": "fr",
     *   "description": "Description de la catégorie",
     *   "parent": 1,
     *   "position": 0,
     *   "metaTitle": "Titre SEO",
     *   "metaDescription": "Description SEO"
     * }
     */
    #[Route('', name: 'create', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function create(Request $request): JsonResponse
    {
        $data = $this->getJsonData($request);

        try {
            $this->requireFields($data, ['name', 'locale'], 'Champs requis manquants');

            $site = $this->getSiteFromRequest($request);
            $category = $this->categoryService->createCategory($data, $site);

            return $this->createResponse(
                $category,
                ['category:read', 'category:parent', 'date'],
                'category'
            );
        } catch (ValidationException $e) {
            return $this->validationError($e->getFormattedErrors());
        } catch (ConflictException $e) {
            return $this->apiResponseUtils->error(
                errors: [['field' => 'slug', 'message' => $e->getMessage()]],
                messageKey: 'category.slug_conflict',
                status: 409
            );
        } catch (BusinessRuleException $e) {
            return $this->apiResponseUtils->error(
                errors: [['rule' => $e->getRule(), 'message' => $e->getMessage()]],
                messageKey: $e->getMessageKey(),
                status: 400
            );
        }
    }

    /**
     * Mise à jour d'une catégorie.
     * 
     * PUT/PATCH /api/v1/categories/{id}
     */
    #[Route('/{id}', name: 'update', methods: ['PUT', 'PATCH'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_ADMIN')]
    public function update(int $id, Request $request): JsonResponse
    {
        $data = $this->getJsonData($request);

        try {
            $category = $this->categoryService->updateCategory($id, $data);

            return $this->updateResponse(
                $category,
                ['category:read', 'category:parent', 'date'],
                'category'
            );
        } catch (ValidationException $e) {
            return $this->validationError($e->getFormattedErrors());
        } catch (ConflictException $e) {
            return $this->apiResponseUtils->error(
                errors: [['field' => 'slug', 'message' => $e->getMessage()]],
                messageKey: 'category.slug_conflict',
                status: 409
            );
        } catch (BusinessRuleException $e) {
            return $this->apiResponseUtils->error(
                errors: [['rule' => $e->getRule(), 'message' => $e->getMessage()]],
                messageKey: $e->getMessageKey(),
                status: 400
            );
        }
    }

    /**
     * Suppression d'une catégorie (soft delete).
     * 
     * DELETE /api/v1/categories/{id}
     */
    #[Route('/{id}', name: 'delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(int $id): JsonResponse
    {
        try {
            $category = $this->categoryService->delete($id);

            return $this->deleteResponse(
                ['id' => $id, 'name' => $category->getName()],
                'category'
            );
        } catch (BusinessRuleException $e) {
            return $this->apiResponseUtils->error(
                errors: [['rule' => $e->getRule(), 'message' => $e->getMessage()]],
                messageKey: $e->getMessageKey(),
                status: 400
            );
        }
    }

    // ===============================================
    // ENDPOINTS SPÉCIALISÉS
    // ===============================================

    /**
     * Activation/désactivation d'une catégorie.
     * 
     * PATCH /api/v1/categories/{id}/status
     * Body : { "active": true }
     */
    #[Route('/{id}/status', name: 'toggle_status', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_ADMIN')]
    public function toggleStatus(int $id, Request $request): JsonResponse
    {
        $data = $this->getJsonData($request);
        $active = $this->getBooleanValue($data, 'active', false);

        $category = $this->categoryService->toggleStatus($id, $active);

        return $this->statusReponse(
            ['id' => $id, 'name' => $category->getName(), 'active' => $category->isActive()],
            'category',
            $active
        );
    }

    /**
     * Réorganise les positions des catégories.
     * 
     * POST /api/v1/categories/reorder
     * Body : { "positions": [{"id": 1, "position": 0}, {"id": 2, "position": 1}] }
     */
    #[Route('/reorder', name: 'reorder', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function reorder(Request $request): JsonResponse
    {
        $data = $this->getJsonData($request);

        try {
            $this->requireField($data, 'positions', 'Le tableau des positions est requis');

            if (!is_array($data['positions'])) {
                throw new \InvalidArgumentException('Le champ "positions" doit être un tableau');
            }

            $this->categoryService->reorderCategories($data['positions']);

            return $this->apiResponseUtils->success(
                messageKey: 'category.reordered'
            );
        } catch (\InvalidArgumentException $e) {
            return $this->apiResponseUtils->error(
                errors: [['message' => $e->getMessage()]],
                messageKey: 'validation.failed',
                status: 400
            );
        }
    }

    /**
     * Déplace une catégorie vers un nouveau parent.
     * 
     * POST /api/v1/categories/{id}/move
     * Body : { "newParentId": 2 } ou { "newParentId": null } pour racine
     */
    #[Route('/{id}/move', name: 'move', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_ADMIN')]
    public function move(int $id, Request $request): JsonResponse
    {
        $data = $this->getJsonData($request);

        try {
            $newParentId = $data['newParentId'] ?? null;

            $category = $this->categoryService->moveCategory($id, $newParentId);

            return $this->apiResponseUtils->success(
                data: $this->serialize($category, ['category:read', 'category:parent', 'date']),
                messageKey: 'category.moved',
                entityKey: 'category'
            );
        } catch (BusinessRuleException $e) {
            return $this->apiResponseUtils->error(
                errors: [['rule' => $e->getRule(), 'message' => $e->getMessage()]],
                messageKey: $e->getMessageKey(),
                status: 400
            );
        }
    }

    /**
     * Clone une catégorie vers une autre locale.
     * 
     * POST /api/v1/categories/{id}/clone
     * Body : { "targetLocale": "en" }
     */
    #[Route('/{id}/clone', name: 'clone', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_ADMIN')]
    public function clone(int $id, Request $request): JsonResponse
    {
        $data = $this->getJsonData($request);

        try {
            $this->requireField($data, 'targetLocale', 'La langue cible est requise');

            $clone = $this->categoryService->cloneToLocale($id, $data['targetLocale']);

            return $this->apiResponseUtils->success(
                data: $this->serialize($clone, ['category:read', 'category:parent', 'date']),
                messageKey: 'category.cloned',
                entityKey: 'category',
                status: 201
            );
        } catch (BusinessRuleException $e) {
            return $this->apiResponseUtils->error(
                errors: [['rule' => $e->getRule(), 'message' => $e->getMessage()]],
                messageKey: $e->getMessageKey(),
                status: 400
            );
        } catch (\InvalidArgumentException $e) {
            return $this->missingFieldError('targetLocale');
        }
    }

    /**
     * Statistiques des catégories.
     * 
     * GET /api/v1/categories/stats?locale=fr
     */
    #[Route('/stats', name: 'stats', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function stats(Request $request): JsonResponse
    {
        $locale = $request->query->get('locale', 'fr');
        $site = $this->getSiteFromRequest($request);

        $categoriesWithCounts = $this->categoryService->getCategoriesWithProductCounts($site, $locale);

        return $this->apiResponseUtils->success(
            data: [
                'total' => count($categoriesWithCounts),
                'categories' => $this->serialize($categoriesWithCounts, ['category:list', 'date']),
            ],
            messageKey: 'category.stats_retrieved'
        );
    }

    // ===============================================
    // HELPER
    // ===============================================

    /**
     * Récupère le site depuis la requête (multi-tenant).
     */
    private function getSiteFromRequest(Request $request): Site
    {
        // Option 1 : Depuis le domaine
        // $domain = $request->getHost();
        // $site = $this->siteRepository->findByDomain($domain);

        // Option 2 : Depuis un header
        // $siteCode = $request->headers->get('X-Site-Code');
        // $site = $this->siteRepository->findByCode($siteCode);

        // Option 3 : Depuis le body ou query (temporaire pour développement)
        $siteCode = $request->query->get('site') ?? $request->get('site');
        if ($siteCode) {
            $site = $this->siteRepository->findByCode($siteCode);
            if ($site) {
                return $site;
            }
        }

        // Fallback : Premier site actif
        $sites = $this->siteRepository->findAccessibleSites();
        if (empty($sites)) {
            throw new \RuntimeException('Aucun site disponible.');
        }
        // dd($sites[]);
        return $sites[2];
    }
}
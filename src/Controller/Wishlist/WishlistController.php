<?php

declare(strict_types=1);

namespace App\Controller\Wishlist;

use App\Controller\Core\AbstractApiController;
use App\Repository\Site\SiteRepository;
use App\Service\Wishlist\WishlistService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Contrôleur REST pour la gestion des wishlists.
 * 
 * Endpoints :
 * - GET    /wishlist              : Liste mes wishlists
 * - GET    /wishlist/{id}         : Détails wishlist
 * - POST   /wishlist              : Créer wishlist
 * - DELETE /wishlist/{id}         : Supprimer wishlist
 * - POST   /wishlist/{id}/items   : Ajouter produit
 * - DELETE /wishlist/{id}/items/{itemId} : Supprimer produit
 * - PATCH  /wishlist/{id}/items/{itemId} : Modifier item
 * - POST   /wishlist/{id}/convert : Convertir en panier
 * - POST   /wishlist/{id}/share   : Activer partage
 * - DELETE /wishlist/{id}/share   : Désactiver partage
 * - GET    /wishlist/shared/{token} : Voir wishlist partagée (public)
 */
#[Route('/api/wishlist', name: 'api_wishlist_')]
#[IsGranted('ROLE_USER')]
class WishlistController extends AbstractApiController
{
    public function __construct(
        private readonly WishlistService $wishlistService,
        private readonly SiteRepository $siteRepository
    ) {}

    // ===============================================
    // LISTE WISHLISTS
    // ===============================================

    /**
     * Récupère toutes mes wishlists.
     * 
     * GET /api/wishlist
     * 
     * Réponse 200 :
     * {
     *   "wishlists": [
     *     {"id": 1, "name": "Ma wishlist", "items_count": 5, ...},
     *     {"id": 2, "name": "Noël 2024", "items_count": 3, ...}
     *   ]
     * }
     */
    #[Route('', name: 'list', methods: ['GET'])]
    public function listWishlists(Request $request): JsonResponse
    {
        $site = $this->getSiteFromHeaders($request);
        $user = $this->getUser();

        $wishlists = $this->wishlistService->getUserWishlists($user, $site);

        return $this->json([
            'wishlists' => array_map(fn($w) => $w->getSummary(), $wishlists)
        ], Response::HTTP_OK);
    }

    // ===============================================
    // DÉTAILS WISHLIST
    // ===============================================

    /**
     * Récupère les détails d'une wishlist.
     * 
     * GET /api/wishlist/{id}
     * 
     * Réponse 200 :
     * {
     *   "wishlist": {
     *     "id": 1,
     *     "name": "Ma wishlist",
     *     "items": [...]
     *   }
     * }
     */
    #[Route('/{id}', name: 'get', methods: ['GET'])]
    public function getWishlist(int $id, Request $request): JsonResponse
    {
        $user = $this->getUser();

        try {
            $wishlist = $this->wishlistService->getWishlist($id, $user);

            return $this->json([
                'wishlist' => [
                    'id' => $wishlist->getId(),
                    'name' => $wishlist->getName(),
                    'description' => $wishlist->getDescription(),
                    'is_default' => $wishlist->isDefault(),
                    'is_public' => $wishlist->isPublic(),
                    'share_token' => $wishlist->getShareToken(),
                    'items_count' => $wishlist->getItemsCount(),
                    'total_value' => $wishlist->getTotalValue(),
                    'items' => array_map(fn($item) => $item->toArray(), $wishlist->getItems()->toArray())
                ]
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            return $this->handleBusinessException($e);
        }
    }

    // ===============================================
    // CRÉER WISHLIST
    // ===============================================

    /**
     * Crée une nouvelle wishlist.
     * 
     * POST /api/wishlist
     * 
     * Body :
     * {
     *   "name": "Noël 2024",
     *   "description": "Liste de cadeaux"
     * }
     * 
     * Réponse 201 :
     * {
     *   "wishlist": {...}
     * }
     */
    #[Route('', name: 'create', methods: ['POST'])]
    public function createWishlist(Request $request): JsonResponse
    {
        $site = $this->getSiteFromHeaders($request);
        $user = $this->getUser();

        $data = json_decode($request->getContent(), true);
        $name = trim($data['name'] ?? '');
        $description = trim($data['description'] ?? '') ?: null;

        if (empty($name)) {
            return $this->json([
                'error' => 'name_required',
                'message' => 'Le nom de la wishlist est requis.'
            ], Response::HTTP_BAD_REQUEST);
        }

        try {
            $wishlist = $this->wishlistService->createWishlist($user, $site, $name, $description);

            return $this->json([
                'wishlist' => $wishlist->getSummary(),
                'message' => 'Wishlist créée avec succès.'
            ], Response::HTTP_CREATED);
        } catch (\Exception $e) {
            return $this->handleBusinessException($e);
        }
    }

    // ===============================================
    // SUPPRIMER WISHLIST
    // ===============================================

    /**
     * Supprime une wishlist.
     * 
     * DELETE /api/wishlist/{id}
     * 
     * Réponse 200 :
     * {
     *   "message": "Wishlist supprimée."
     * }
     */
    #[Route('/{id}', name: 'delete', methods: ['DELETE'])]
    public function deleteWishlist(int $id): JsonResponse
    {
        $user = $this->getUser();

        try {
            $wishlist = $this->wishlistService->getWishlist($id, $user);
            $this->wishlistService->deleteWishlist($wishlist, $user);

            return $this->json([
                'message' => 'Wishlist supprimée avec succès.'
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            return $this->handleBusinessException($e);
        }
    }

    // ===============================================
    // AJOUTER PRODUIT
    // ===============================================

    /**
     * Ajoute un produit à une wishlist.
     * 
     * POST /api/wishlist/{id}/items
     * 
     * Body :
     * {
     *   "variant_id": 123,
     *   "priority": 3,
     *   "note": "Taille M",
     *   "quantity": 1
     * }
     * 
     * Réponse 201 :
     * {
     *   "item": {...},
     *   "message": "Produit ajouté à la wishlist."
     * }
     */
    #[Route('/{id}/items', name: 'add_item', methods: ['POST'])]
    public function addItem(int $id, Request $request): JsonResponse
    {
        $user = $this->getUser();

        try {
            $wishlist = $this->wishlistService->getWishlist($id, $user);

            $data = json_decode($request->getContent(), true);
            $variantId = (int) ($data['variant_id'] ?? 0);
            $priority = (int) ($data['priority'] ?? 2);
            $note = $data['note'] ?? null;
            $quantity = (int) ($data['quantity'] ?? 1);

            if ($variantId <= 0) {
                return $this->json([
                    'error' => 'variant_id_required',
                    'message' => 'L\'ID du produit est requis.'
                ], Response::HTTP_BAD_REQUEST);
            }

            $item = $this->wishlistService->addItem($wishlist, $variantId, $priority, $note, $quantity);

            return $this->json([
                'item' => $item->toArray(),
                'wishlist' => $wishlist->getSummary(),
                'message' => 'Produit ajouté à la wishlist.'
            ], Response::HTTP_CREATED);
        } catch (\Exception $e) {
            return $this->handleBusinessException($e);
        }
    }

    // ===============================================
    // SUPPRIMER PRODUIT
    // ===============================================

    /**
     * Supprime un produit de la wishlist.
     * 
     * DELETE /api/wishlist/{id}/items/{itemId}
     * 
     * Réponse 200 :
     * {
     *   "message": "Produit supprimé de la wishlist."
     * }
     */
    #[Route('/{id}/items/{itemId}', name: 'remove_item', methods: ['DELETE'])]
    public function removeItem(int $id, int $itemId): JsonResponse
    {
        $user = $this->getUser();

        try {
            $this->wishlistService->removeItem($itemId, $user);

            return $this->json([
                'message' => 'Produit supprimé de la wishlist.'
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            return $this->handleBusinessException($e);
        }
    }

    // ===============================================
    // MODIFIER ITEM
    // ===============================================

    /**
     * Modifie un item (priorité, note, quantité).
     * 
     * PATCH /api/wishlist/{id}/items/{itemId}
     * 
     * Body :
     * {
     *   "priority": 3,
     *   "note": "Nouvelle note",
     *   "quantity": 2
     * }
     * 
     * Réponse 200 :
     * {
     *   "item": {...}
     * }
     */
    #[Route('/{id}/items/{itemId}', name: 'update_item', methods: ['PATCH'])]
    public function updateItem(int $id, int $itemId, Request $request): JsonResponse
    {
        $user = $this->getUser();
        $data = json_decode($request->getContent(), true);

        try {
            $item = $this->wishlistService->updateItem($itemId, $user, $data);

            return $this->json([
                'item' => $item->toArray(),
                'message' => 'Produit mis à jour.'
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            return $this->handleBusinessException($e);
        }
    }

    // ===============================================
    // CONVERTIR EN PANIER
    // ===============================================

    /**
     * Convertit la wishlist en panier.
     * 
     * POST /api/wishlist/{id}/convert
     * 
     * Ajoute tous les produits disponibles au panier.
     * 
     * Réponse 200 :
     * {
     *   "cart": {...},
     *   "report": {
     *     "total_items": 5,
     *     "added": 4,
     *     "skipped": 1,
     *     "errors": [...]
     *   }
     * }
     */
    #[Route('/{id}/convert', name: 'convert_to_cart', methods: ['POST'])]
    public function convertToCart(int $id): JsonResponse
    {
        $user = $this->getUser();

        try {
            $wishlist = $this->wishlistService->getWishlist($id, $user);
            $result = $this->wishlistService->convertToCart($wishlist);

            return $this->json([
                'cart' => [
                    'id' => $result['cart']->getId(),
                    'summary' => $result['cart']->getSummary()
                ],
                'report' => $result['report'],
                'message' => sprintf(
                    '%d produit(s) ajouté(s) au panier, %d ignoré(s).',
                    $result['report']['added'],
                    $result['report']['skipped']
                )
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            return $this->handleBusinessException($e);
        }
    }

    // ===============================================
    // PARTAGE PUBLIC
    // ===============================================

    /**
     * Active le partage public de la wishlist.
     * 
     * POST /api/wishlist/{id}/share
     * 
     * Réponse 200 :
     * {
     *   "share_token": "550e8400-...",
     *   "share_url": "/wishlist/550e8400-..."
     * }
     */
    #[Route('/{id}/share', name: 'enable_share', methods: ['POST'])]
    public function enableSharing(int $id, Request $request): JsonResponse
    {
        $user = $this->getUser();

        try {
            $wishlist = $this->wishlistService->getWishlist($id, $user);
            $token = $this->wishlistService->enablePublicSharing($wishlist);

            return $this->json([
                'share_token' => $token,
                'share_url' => "/wishlist/{$token}",
                'message' => 'Partage activé.'
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            return $this->handleBusinessException($e);
        }
    }

    /**
     * Désactive le partage public.
     * 
     * DELETE /api/wishlist/{id}/share
     */
    #[Route('/{id}/share', name: 'disable_share', methods: ['DELETE'])]
    public function disableSharing(int $id): JsonResponse
    {
        $user = $this->getUser();

        try {
            $wishlist = $this->wishlistService->getWishlist($id, $user);
            $this->wishlistService->disablePublicSharing($wishlist);

            return $this->json([
                'message' => 'Partage désactivé.'
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            return $this->handleBusinessException($e);
        }
    }

    // ===============================================
    // WISHLIST PARTAGÉE (PUBLIC)
    // ===============================================

    /**
     * Récupère une wishlist partagée (accès public).
     * 
     * GET /api/wishlist/shared/{token}
     * 
     * Pas besoin d'authentification.
     * 
     * Réponse 200 :
     * {
     *   "wishlist": {...}
     * }
     */
    #[Route('/shared/{token}', name: 'get_shared', methods: ['GET'])]
    public function getSharedWishlist(string $token): JsonResponse
    {
        try {
            $wishlist = $this->wishlistService->getSharedWishlist($token);

            return $this->json([
                'wishlist' => [
                    'name' => $wishlist->getName(),
                    'description' => $wishlist->getDescription(),
                    'items_count' => $wishlist->getItemsCount(),
                    'items' => array_map(fn($item) => $item->toArray(), $wishlist->getItems()->toArray())
                ]
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            return $this->handleBusinessException($e);
        }
    }

    // ===============================================
    // HELPERS PRIVÉS
    // ===============================================

    /**
     * Récupère le Site depuis le header X-Site-Id.
     */
    private function getSiteFromHeaders(Request $request)
    {
        $siteId = $request->headers->get('X-Site-Id');

        if (!$siteId) {
            throw new \Exception('Header X-Site-Id requis.', Response::HTTP_BAD_REQUEST);
        }

        $site = $this->siteRepository->find((int) $siteId);

        if (!$site) {
            throw new \Exception('Site non trouvé.', Response::HTTP_NOT_FOUND);
        }

        return $site;
    }

    /**
     * Gère les exceptions métier.
     */
    private function handleBusinessException(\Exception $e): JsonResponse
    {
        $statusCode = match (true) {
            str_contains($e->getMessage(), 'not_found') => Response::HTTP_NOT_FOUND,
            str_contains($e->getMessage(), 'access_denied') => Response::HTTP_FORBIDDEN,
            default => Response::HTTP_BAD_REQUEST,
        };

        return $this->json([
            'error' => get_class($e),
            'message' => $e->getMessage()
        ], $statusCode);
    }
}

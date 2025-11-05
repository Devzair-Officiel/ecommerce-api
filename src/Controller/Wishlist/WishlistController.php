<?php

declare(strict_types=1);

namespace App\Controller\Wishlist;

use App\Entity\Site\Site;
use App\Utils\ApiResponseUtils;
use App\Exception\ValidationException;
use App\Repository\Site\SiteRepository;
use App\Service\Wishlist\WishlistService;
use Symfony\Component\HttpFoundation\Request;
use App\Controller\Core\AbstractApiController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Contrôleur REST pour la gestion des wishlists.
 * 
 * Endpoints :
 * - GET    /wishlists                      : Liste mes wishlists
 * - GET    /wishlists/{id}                 : Détails wishlist
 * - POST   /wishlists                      : Créer wishlist
 * - DELETE /wishlists/{id}                 : Supprimer wishlist
 * - POST   /wishlists/{id}/items           : Ajouter produit
 * - DELETE /wishlists/{id}/items/{itemId}  : Supprimer produit
 * - PATCH  /wishlists/{id}/items/{itemId}  : Modifier item
 * - POST   /wishlists/{id}/convert         : Convertir en panier
 * - POST   /wishlists/{id}/share           : Activer partage
 * - DELETE /wishlists/{id}/share           : Désactiver partage
 * - GET    /wishlists/shared/{token}       : Voir wishlist partagée (public)
 */
#[Route('/wishlists', name: 'api_wishlist_')]
#[IsGranted('ROLE_USER')]
class WishlistController extends AbstractApiController
{
    public function __construct(
        private readonly WishlistService $wishlistService,
        private readonly SiteRepository $siteRepository,
        ApiResponseUtils $apiResponseUtils,
        SerializerInterface $serializer,
    )
    {
        parent::__construct($apiResponseUtils, $serializer);
    }

    // ===============================================
    // LISTE WISHLISTS
    // ===============================================

    /**
     * Récupère toutes mes wishlists.
     * 
     * GET /wishlists
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

        // ✅ Pas de try/catch : le service lance des exceptions typées
        // qui sont automatiquement gérées par ApiExceptionSubscriber
        $wishlists = $this->wishlistService->getUserWishlists($user, $site);

        // ✅ Format standardisé via ApiResponseUtils
        return $this->apiResponseUtils->success(
            data: [
                'wishlists' => array_map(fn($w) => $w->getSummary(), $wishlists)
            ],
            entityKey: 'wishlist',
            status: Response::HTTP_OK
        );
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

        // ✅ Les exceptions (EntityNotFoundException, BusinessRuleException)
        // sont gérées automatiquement
        $wishlist = $this->wishlistService->getWishlist($id, $user);

        return $this->apiResponseUtils->success(
            data: [
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
            ],
            messageKey: 'wishlist',
            entityKey: 'wishlist'
        );
    }

    // ===============================================
    // CRÉER WISHLIST
    // ===============================================

    /**
     * Crée une nouvelle wishlist.
     * 
     * POST /wishlists
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
    public function create(Request $request): JsonResponse
    {
        $site = $this->getSiteFromHeaders($request);
        $user = $this->getUser();
        $data = $this->getJsonData($request);

        try {
            // ✅ Utilise requireField au lieu de validation manuelle
            $this->requireField($data, 'name', 'Le nom de la wishlist est requis');

            $name = trim($data['name']);
            $description = isset($data['description']) && trim($data['description']) !== ''
                ? trim($data['description'])
                : null;

            $wishlist = $this->wishlistService->createWishlist($user, $site, $name, $description);

            // ✅ Utilise createResponse au lieu de success()
            return $this->createResponse(
                $wishlist,
                ['wishlist:read', 'date'],
                'wishlist'
            );
        } catch (\InvalidArgumentException $e) {
            return $this->missingFieldError('name');
        } catch (ValidationException $e) {
            return $this->validationError($e->getFormattedErrors());
        }
    }

    // ===============================================
    // SUPPRIMER WISHLIST
    // ===============================================

    /**
     * Supprime une wishlist.
     * 
     * DELETE /wishlist/{id}
     * 
     * Réponse 200 :
     * {
     *   "message": "Wishlist supprimée."
     * }
     */
    #[Route('/{id}', name: 'delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(int $id): JsonResponse
    {
        $user = $this->getUser();
        $wishlist = $this->wishlistService->getWishlist($id, $user);

        $this->wishlistService->deleteWishlist($wishlist, $user);

        return $this->deleteResponse(
            ['id' => $id, 'name' => $wishlist->getName()],
            'wishlist'
        );
    }

    // ===============================================
    // AJOUTER PRODUIT
    // ===============================================

    /**
     * Ajoute un produit à une wishlist.
     * 
     * POST /wishlists/{id}/items
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
    #[Route('/{id}/items', name: 'add_item', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function addItem(int $id, Request $request): JsonResponse
    {
        $user = $this->getUser();
        $wishlist = $this->wishlistService->getWishlist($id, $user);
        $data = $this->getJsonData($request);

        try {
            $variantId = $this->getIntValue($data, 'variant_id');

            if (!$variantId || $variantId <= 0) {
                return $this->apiResponseUtils->error(
                    errors: [['field' => 'variant_id', 'message' => 'Valid variant_id is required']],
                    messageKey: 'validation.required_field',
                    status: Response::HTTP_BAD_REQUEST
                );
            }

            $priority = $this->getIntValue($data, 'priority', 2);
            $quantity = $this->getIntValue($data, 'quantity', 1);
            $note = isset($data['note']) ? trim($data['note']) : null;

            $item = $this->wishlistService->addItem($wishlist, $variantId, $priority, $note, $quantity);

            return $this->apiResponseUtils->success(
                data: [
                    'item' => $item->toArray(),
                    'wishlist' => $wishlist->getSummary()
                ],
                messageKey: 'entity.created',
                entityKey: 'wishlist_item',
                status: Response::HTTP_CREATED
            );
        } catch (ValidationException $e) {
            return $this->validationError($e->getFormattedErrors());
        }
    }

    // ===============================================
    // SUPPRIMER PRODUIT
    // ===============================================

    /**
     * Supprime un produit de la wishlist.
     * 
     * DELETE /wishlists/{id}/items/{itemId}
     * 
     * Réponse 200 :
     * {
     *   "message": "Produit supprimé de la wishlist."
     * }
     */
    #[Route('/{id}/items/{itemId}', name: 'remove_item', methods: ['DELETE'], requirements: ['id' => '\d+', 'itemId' => '\d+'])]
    public function removeItem(int $id, int $itemId): JsonResponse
    {
        $user = $this->getUser();
        $this->wishlistService->removeItem($itemId, $user);

        return $this->apiResponseUtils->success(
            data: ['item_id' => $itemId],
            messageKey: 'entity.deleted',
            entityKey: 'wishlist_item'
        );
    }

    // ===============================================
    // MODIFIER ITEM
    // ===============================================

    /**
     * Modifie un item (priorité, note, quantité).
     * 
     * PATCH /wishlists/{id}/items/{itemId}
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
    #[Route('/{id}/items/{itemId}', name: 'update_item', methods: ['PATCH'], requirements: ['id' => '\d+', 'itemId' => '\d+'])]
    public function updateItem(int $id, int $itemId, Request $request): JsonResponse
    {
        $user = $this->getUser();
        $data = $this->getJsonData($request);

        try {
            $item = $this->wishlistService->updateItem($itemId, $user, $data);

            return $this->apiResponseUtils->success(
                data: [
                    'item' => $item->toArray(),
                ],
                entityKey: 'wishlist',
                status: Response::HTTP_OK
            );
        } catch (ValidationException $e) {
            return $this->validationError($e->getFormattedErrors());
        }
    }

    // ===============================================
    // CONVERTIR EN PANIER
    // ===============================================

    /**
     * Convertit la wishlist en panier.
     * 
     * POST /wishlist/{id}/convert
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
    #[Route('/{id}/convert', name: 'convert_to_cart', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function convertToCart(int $id): JsonResponse
    {
        $user = $this->getUser();
        $wishlist = $this->wishlistService->getWishlist($id, $user);
        $result = $this->wishlistService->convertToCart($wishlist);

        return $this->apiResponseUtils->success(
            data: [
                'cart' => [
                    'id' => $result['cart']->getId(),
                    'summary' => $result['cart']->getSummary()
                ],
                'report' => $result['report']
            ],
            messageKey: 'wishlist.converted_to_cart',
            messageParams: [
                '%added%' => $result['report']['added'],
                '%skipped%' => $result['report']['skipped']
            ]
        );
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
    private function getSiteFromHeaders(Request $request): Site
    {
        // Option 1 : Depuis le domaine (recommandé en production)
        // $domain = $request->getHost();
        // return $this->siteRepository->findByDomain($domain);

        // Option 2 : Depuis un header custom
        // $siteId = $request->headers->get('X-Site-Id');

        // if (!$siteId) {
        //     throw new \Exception('Header X-Site-Id requis.', 400);
        // }

        // // Injecter SiteRepository
        // $site = $this->siteRepository->find($siteId);

        // if (!$site) {
        //     throw new \Exception('Site non trouvé.', 404);
        // }

        // return $site;

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

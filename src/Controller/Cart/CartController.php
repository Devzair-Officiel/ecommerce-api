<?php

declare(strict_types=1);

namespace App\Controller\Cart;

use App\Entity\Site\Site;
use App\Service\Cart\CartService;
use App\Repository\Site\SiteRepository;
use Symfony\Component\HttpFoundation\Request;
use App\Controller\Core\AbstractApiController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Contrôleur REST pour la gestion des paniers.
 * 
 * Endpoints :
 * - GET    /carts             : Récupérer panier actuel
 * - POST   /carts/items       : Ajouter un produit
 * - PATCH  /carts/items/{id}  : Modifier quantité
 * - DELETE /carts/items/{id}  : Supprimer un produit
 * - DELETE /carts             : Vider le panier
 * - GET    /carts/validate    : Valider avant checkout
 * - POST   /carts/merge       : Fusionner panier invité (connexion)
 * 
 * Authentification :
 * - Invités : JWT avec cart_token
 * - Utilisateurs : JWT standard avec user_id
 * 
 * Headers requis :
 * - X-Site-Id : ID du site (multi-tenant)
 * - X-Currency : Devise (EUR, USD...)
 * - X-Locale : Langue (fr, en, es)
 * - Authorization : Bearer {JWT}
 */
#[Route('/carts', name: 'api_cart_')]
class CartController extends AbstractApiController
{
    public function __construct(
        private readonly CartService $cartService,
        private readonly SiteRepository $siteRepository,
    ) {}

    // ===============================================
    // RÉCUPÉRATION PANIER
    // ===============================================

    /**
     * Récupère le panier actuel de l'utilisateur ou invité.
     * 
     * Headers :
     * - X-Site-Id: 1
     * - X-Currency: EUR
     * - X-Locale: fr
     * - Authorization: Bearer {JWT}
     * 
     * Réponse 200 :
     * {
     *   "cart": {
     *     "id": 42,
     *     "items_count": 3,
     *     "lines_count": 2,
     *     "subtotal": 45.90,
     *     "discount": 0,
     *     "shipping": 5.90,
     *     "total": 51.80,
     *     "currency": "EUR",
     *     "items": [...]
     *   },
     *   "cart_token": "550e8400-..." (si invité)
     * }
     * 
     * Réponse 404 : Si aucun panier
     */
    #[Route('', name: 'get', methods: ['GET'])]
    public function getCart(Request $request): JsonResponse
    {
        // Récupérer contexte
        $site = $this->getSiteFromHeaders($request);
        $currency = $this->getHeaderOrDefault($request, 'X-Currency', 'EUR');
        $locale = $this->getHeaderOrDefault($request, 'X-Locale', 'fr');
        $customerType = $this->getHeaderOrDefault($request, 'X-Customer-Type', 'B2C');

        // Récupérer user ou sessionToken depuis JWT
        $user = $this->getUser();
        $sessionToken = $this->getCartTokenFromJWT($request);

        // Récupérer ou créer panier
        $result = $this->cartService->getOrCreateCart(
            $site,
            $currency,
            $locale,
            $customerType,
            $user,
            $sessionToken
        );

        $cart = $result['cart'];

        return $this->json([
            'cart' => [
                'id' => $cart->getId(),
                'summary' => $cart->getSummary(),
                'items' => $this->serializeItems($cart->getItems()->toArray()),
                'is_guest' => $cart->isGuestCart(),
                'expires_at' => $cart->getExpiresAt()?->format('c'),
            ],
            'cart_token' => $result['token'], // Pour invités
            'is_new' => $result['is_new']
        ], Response::HTTP_OK, [], ['groups' => ['cart:read', 'cart:items']]);
    }

    // ===============================================
    // AJOUT PRODUIT
    // ===============================================

    /**
     * Ajoute un produit au panier.
     * 
     * POST /cart/items
     * 
     * Body :
     * {
     *   "variant_id": 123,
     *   "quantity": 2,
     *   "custom_message": "C'est un cadeau" (optionnel)
     * }
     * 
     * Réponse 201 :
     * {
     *   "item": {...},
     *   "cart": {...}
     * }
     * 
     * Erreurs :
     * - 400 : Données invalides
     * - 404 : Variante non trouvée
     * - 409 : Stock insuffisant
     */
    #[Route('/items', name: 'add_item', methods: ['POST'])]
    public function addItem(Request $request): JsonResponse
    {
        // Récupérer panier actuel
        $site = $this->getSiteFromHeaders($request);
        $user = $this->getUser();
        $sessionToken = $this->getCartTokenFromJWT($request);

        $cart = $this->cartService->getValidCart($site, $user, $sessionToken);

        if (!$cart) {
            // Créer nouveau panier si nécessaire
            $currency = $this->getHeaderOrDefault($request, 'X-Currency', 'EUR');
            $locale = $this->getHeaderOrDefault($request, 'X-Locale', 'fr');
            $customerType = $this->getHeaderOrDefault($request, 'X-Customer-Type', 'B2C');

            $result = $this->cartService->getOrCreateCart(
                $site,
                $currency,
                $locale,
                $customerType,
                $user,
                $sessionToken
            );

            $cart = $result['cart'];
            $token = $result['token'];  // ← Important !
        } else {
            $token = $cart->getSessionToken();  // ← Récupérer le token existant
        }

        // Récupérer données
        $data = json_decode($request->getContent(), true);
        $variantId = (int) ($data['variant_id'] ?? 0);
        $quantity = (int) ($data['quantity'] ?? 1);
        $customMessage = $data['custom_message'] ?? null;

        // Validation basique
        if ($variantId <= 0) {
            return $this->json([
                'error' => 'variant_id_required',
                'message' => 'L\'ID du produit est requis.'
            ], Response::HTTP_BAD_REQUEST);
        }

        if ($quantity < 1 || $quantity > 999) {
            return $this->json([
                'error' => 'invalid_quantity',
                'message' => 'La quantité doit être entre 1 et 999.'
            ], Response::HTTP_BAD_REQUEST);
        }

        // Ajouter au panier
        try {
            $item = $this->cartService->addItem($cart, $variantId, $quantity, $customMessage);

            $response = [
                'item' => $item->toArray(),
                'cart' => [
                    'id' => $cart->getId(),
                    'summary' => $cart->getSummary(),
                ],
                'message' => 'Produit ajouté au panier.'
            ];

            // 🔥 IMPORTANT : Retourner le token pour les invités
            if ($token) {
                $response['token'] = $token;
            }

            return $this->json($response, Response::HTTP_CREATED, [], ['groups' => ['cart:read', 'cart:items']]);
        } catch (\Exception $e) {
            return $this->handleBusinessException($e);
        }
    }

    // ===============================================
    // MODIFICATION QUANTITÉ
    // ===============================================

    /**
     * Modifie la quantité d'un item.
     * 
     * PATCH /cart/items/{id}
     * 
     * Body :
     * {
     *   "quantity": 5
     * }
     * 
     * Réponse 200 :
     * {
     *   "item": {...},
     *   "cart": {...}
     * }
     */
    #[Route('/items/{id}', name: 'update_item', methods: ['PATCH'])]
    public function updateItem(int $id, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $quantity = (int) ($data['quantity'] ?? 0);

        if ($quantity < 1) {
            return $this->json([
                'error' => 'invalid_quantity',
                'message' => 'La quantité doit être supérieure à 0.'
            ], Response::HTTP_BAD_REQUEST);
        }

        try {
            $item = $this->cartService->updateItemQuantity($id, $quantity);
            $cart = $item->getCart();

            return $this->json([
                'item' => $item->toArray(),
                'cart' => [
                    'id' => $cart->getId(),
                    'summary' => $cart->getSummary(),
                ],
                'message' => 'Quantité mise à jour.'
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            return $this->handleBusinessException($e);
        }
    }

    // ===============================================
    // SUPPRESSION PRODUIT
    // ===============================================

    /**
     * Supprime un produit du panier.
     * 
     * DELETE /cart/items/{id}
     * 
     * Réponse 200 :
     * {
     *   "cart": {...},
     *   "message": "Produit supprimé."
     * }
     */
    #[Route('/items/{id}', name: 'remove_item', methods: ['DELETE'])]
    public function removeItem(int $id, Request $request): JsonResponse
    {
        try {
            $this->cartService->removeItem($id);

            // Récupérer panier mis à jour
            $site = $this->getSiteFromHeaders($request);
            $user = $this->getUser();
            $sessionToken = $this->getCartTokenFromJWT($request);

            $cart = $this->cartService->getCart($site, $user, $sessionToken);

            return $this->json([
                'cart' => $cart ? [
                    'id' => $cart->getId(),
                    'summary' => $cart->getSummary(),
                ] : null,
                'message' => 'Produit supprimé du panier.'
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            return $this->handleBusinessException($e);
        }
    }

    // ===============================================
    // VIDER PANIER
    // ===============================================

    /**
     * Vide complètement le panier.
     * 
     * DELETE /cart
     * 
     * Réponse 200 :
     * {
     *   "message": "Panier vidé."
     * }
     */
    #[Route('', name: 'clear', methods: ['DELETE'])]
    public function clearCart(Request $request): JsonResponse
    {
        $site = $this->getSiteFromHeaders($request);
        $user = $this->getUser();
        $sessionToken = $this->getCartTokenFromJWT($request);

        $cart = $this->cartService->getCart($site, $user, $sessionToken);

        if (!$cart) {
            return $this->json([
                'error' => 'cart_not_found',
                'message' => 'Aucun panier trouvé.'
            ], Response::HTTP_NOT_FOUND);
        }

        $this->cartService->clearCart($cart);

        return $this->json([
            'message' => 'Panier vidé.',
            'cart' => [
                'id' => $cart->getId(),
                'summary' => $cart->getSummary(),
            ]
        ], Response::HTTP_OK);
    }

    // ===============================================
    // VALIDATION AVANT CHECKOUT
    // ===============================================

    /**
     * Valide le panier avant de passer commande.
     * 
     * GET /cart/validate
     * 
     * Vérifications :
     * - Variantes toujours disponibles
     * - Stock suffisant
     * - Prix pas changé de > 5%
     * 
     * Réponse 200 :
     * {
     *   "valid": true,
     *   "errors": [],
     *   "warnings": [],
     *   "statistics": {...}
     * }
     * 
     * Réponse 400 : Si panier invalide
     */
    #[Route('/validate', name: 'validate', methods: ['GET'])]
    public function validateCart(Request $request): JsonResponse
    {
        $site = $this->getSiteFromHeaders($request);
        $user = $this->getUser();
        $sessionToken = $this->getCartTokenFromJWT($request);

        $cart = $this->cartService->getValidCart($site, $user, $sessionToken);

        if (!$cart) {
            return $this->json([
                'error' => 'cart_not_found',
                'message' => 'Aucun panier trouvé.'
            ], Response::HTTP_NOT_FOUND);
        }

        if ($cart->isEmpty()) {
            return $this->json([
                'error' => 'cart_empty',
                'message' => 'Le panier est vide.'
            ], Response::HTTP_BAD_REQUEST);
        }

        $statistics = $this->cartService->getCartStatistics($cart);

        $statusCode = $statistics['is_valid_for_checkout']
            ? Response::HTTP_OK
            : Response::HTTP_BAD_REQUEST;

        return $this->json($statistics, $statusCode, [], [
            'groups' => ['cart:read']
        ]);
    }

    // ===============================================
    // FUSION PANIER INVITÉ (CONNEXION)
    // ===============================================

    /**
     * Fusionne un panier invité dans le compte utilisateur.
     * 
     * POST /api/cart/merge
     * 
     * Appelé automatiquement lors de la connexion si panier invité existe.
     * 
     * Body :
     * {
     *   "guest_token": "550e8400-..."
     * }
     * 
     * Réponse 200 :
     * {
     *   "cart": {...},
     *   "message": "Paniers fusionnés."
     * }
     */
    #[Route('/merge', name: 'merge', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function mergeGuestCart(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $guestToken = $data['guest_token'] ?? null;

        if (!$guestToken) {
            return $this->json([
                'error' => 'guest_token_required',
                'message' => 'Le token du panier invité est requis.'
            ], Response::HTTP_BAD_REQUEST);
        }

        $site = $this->getSiteFromHeaders($request);
        $user = $this->getUser();

        try {
            $cart = $this->cartService->mergeGuestCartToUser($guestToken, $user, $site);

            return $this->json([
                'cart' => [
                    'id' => $cart->getId(),
                    'summary' => $cart->getSummary(),
                    'items' => $this->serializeItems($cart->getItems()->toArray()),
                ],
                'message' => 'Paniers fusionnés avec succès.'
            ], Response::HTTP_OK, [], ['groups' => ['cart:read', 'cart:items']]);
        } catch (\Exception $e) {
            return $this->handleBusinessException($e);
        }
    }

    // ===============================================
    // HELPERS PRIVÉS
    // ===============================================

    /**
     * Récupère le Site depuis le header X-Site-Id.
     * 
     * @throws \Exception Si header manquant ou site invalide
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
     * Récupère le cart_token depuis le JWT payload.
     * 
     * Structure JWT invité :
     * {
     *   "sub": "guest",
     *   "cart_token": "550e8400-...",
     *   "exp": 1234567890
     * }
     */
    private function getCartTokenFromJWT(Request $request): ?string
    {
        // TODO: Extraire cart_token du JWT
        // Dépend de votre implémentation JWT (LexikJWTAuthenticationBundle)
        // 
        // Exemple avec payload décodé :
        // $payload = $this->jwtDecoder->decode($request);
        // return $payload['cart_token'] ?? null;

        // Pour l'instant, essayer header custom
        return $request->headers->get('X-Cart-Token');
    }

    /**
     * Récupère une valeur de header ou défaut.
     */
    private function getHeaderOrDefault(Request $request, string $header, string $default): string
    {
        return $request->headers->get($header, $default);
    }

    /**
     * Sérialise les items pour la réponse.
     */
    private function serializeItems(array $items): array
    {
        return array_map(fn($item) => $item->toArray(), $items);
    }

    /**
     * Gère les exceptions métier et retourne une réponse appropriée.
     */
    private function handleBusinessException(\Exception $e): JsonResponse
    {
        // Mapper les exceptions BusinessRuleException vers codes HTTP
        $statusCode = match ($e->getMessage()) {
            'variant_not_found', 'item_not_found', 'guest_cart_not_found' => Response::HTTP_NOT_FOUND,
            'insufficient_stock', 'variant_unavailable', 'cart_expired' => Response::HTTP_CONFLICT,
            default => Response::HTTP_BAD_REQUEST,
        };

        return $this->json([
            'error' => get_class($e),
            'message' => $e->getMessage()
        ], $statusCode);
    }
}

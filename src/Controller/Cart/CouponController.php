<?php

declare(strict_types=1);

namespace App\Controller\Cart;

use App\Entity\Site\Site;
use App\Utils\ApiResponseUtils;
use App\Service\Cart\CartService;
use App\Service\Cart\CouponService;
use App\Repository\Site\SiteRepository;
use Symfony\Component\HttpFoundation\Request;
use App\Controller\Core\AbstractApiController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * Contrôleur REST pour la gestion des coupons de réduction.
 * 
 * Endpoints :
 * - POST   /carts/coupon/apply    : Appliquer un coupon
 * - DELETE /carts/coupon          : Supprimer le coupon
 * - POST   /carts/coupon/validate : Valider un coupon (sans l'appliquer)
 * 
 * Headers requis :
 * - X-Site-Id : ID du site
 * - X-Cart-Token : Token panier invité OU Authorization : Bearer {JWT}
 */
#[Route('/carts/coupons', name: 'api_coupon_')]
class CouponController extends AbstractApiController
{
    public function __construct(
        private readonly CouponService $couponService,
        private readonly CartService $cartService,
        private readonly SiteRepository $siteRepository,
        SerializerInterface $serializer,
        ApiResponseUtils $apiResponseUtils,
    )
    {
        parent::__construct($apiResponseUtils, $serializer);
    }

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $pagination = $this->getPaginationParams($request);
        $filters = $this->extractFilters($request);

        $result = $this->couponService->search(
            $pagination['page'],
            $pagination['limit'],
            $filters
        );

        return $this->listResponse(
            $result,
            ['coupon:read', 'date'],
            'coupon',
            'api_coupon_list'
        );
    }

    // ===============================================
    // APPLIQUER COUPON
    // ===============================================

    /**
     * Applique un code promo au panier.
     * 
     * POST /carts/coupon/apply
     * 
     * Body :
     * {
     *   "code": "WELCOME10"
     * }
     * 
     * Réponse 200 :
     * {
     *   "cart": {...},
     *   "discount": 12.50,
     *   "message": "Code promo appliqué avec succès !"
     * }
     * 
     * Erreurs :
     * - 400 : Code invalide, conditions non remplies
     * - 404 : Code non trouvé
     */
    #[Route('/apply', name: 'apply', methods: ['POST'])]
    public function applyCoupon(Request $request): JsonResponse
    {
        // ✅ Récupération automatique avec validation
        $site = $this->getSiteFromHeaders($request);
        $user = $this->getUser();
        $cart = $this->getCartFromRequest($request);

        if (!$cart) {
            return $this->notFoundError('cart', ['site_id' => $site->getId()]);
        }

        // ✅ Extraction et validation des données JSON avec helper
        $data = $this->getJsonData($request);
        $code = $this->requireJsonField($data, 'code');

        // ✅ Appel service sans try/catch
        // Les BusinessRuleException sont gérées par ApiExceptionSubscriber
        $result = $this->couponService->applyToCart($cart, $code, $site, $user);

        // ✅ Réponse standardisée
        return $this->apiResponseUtils->success(
            data: [
                'cart' => [
                    'id' => $cart->getId(),
                    'summary' => $cart->getSummary(),
                    'coupon' => $result['cart']->getCoupon()?->getSummary()
                ],
                'discount' => $result['discount'],
            ],
            messageKey: 'coupon.applied',
            status: Response::HTTP_OK
        );
    }

    // ===============================================
    // SUPPRIMER COUPON
    // ===============================================

    /**
     * Supprime le code promo du panier.
     * 
     * DELETE /api/cart/coupon
     * 
     * Réponse 200 :
     * {
     *   "cart": {...},
     *   "message": "Code promo supprimé."
     * }
     */
    #[Route('', name: 'remove', methods: ['DELETE'])]
    public function removeCoupon(Request $request): JsonResponse
    {
        $cart = $this->getCartFromRequest($request);

        if (!$cart) {
            return $this->json([
                'error' => 'cart_not_found',
                'message' => 'Aucun panier trouvé.'
            ], Response::HTTP_NOT_FOUND);
        }

        try {
            $this->couponService->removeFromCart($cart);

            return $this->json([
                'cart' => [
                    'id' => $cart->getId(),
                    'summary' => $cart->getSummary(),
                ],
                'message' => 'Code promo supprimé.'
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            return $this->handleBusinessException($e);
        }
    }

    // ===============================================
    // VALIDER COUPON (SANS APPLIQUER)
    // ===============================================

    /**
     * Valide un code promo sans l'appliquer.
     * 
     * POST /carts/coupons/validate
     * 
     * Utilité : Vérifier si un coupon est valide avant de l'appliquer
     * (affichage en temps réel pendant saisie du code).
     * 
     * Body :
     * {
     *   "code": "WELCOME10"
     * }
     * 
     * Réponse 200 :
     * {
     *   "valid": true,
     *   "coupon": {...},
     *   "discount": 12.50,
     *   "checks": {...},
     *   "message": "Vous économiserez 12.50€ avec ce code."
     * }
     */
    #[Route('/validate', name: 'validate', methods: ['POST'])]
    public function validateCoupon(Request $request): JsonResponse
    {
        $site = $this->getSiteFromHeaders($request);
        $user = $this->getUser();
        $cart = $this->getCartFromRequest($request);

        if (!$cart) {
            return $this->json([
                'error' => 'cart_not_found',
                'message' => 'Aucun panier trouvé.'
            ], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true);
        $code = trim($data['code'] ?? '');

        if (empty($code)) {
            return $this->json([
                'error' => 'code_required',
                'message' => 'Le code promo est requis.'
            ], Response::HTTP_BAD_REQUEST);
        }

        $report = $this->couponService->validateCoupon($cart, $code, $site, $user);

        return $this->json($report, Response::HTTP_OK);
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
     * Récupère le panier depuis la requête.
     */
    private function getCartFromRequest(Request $request)
    {
        $site = $this->getSiteFromHeaders($request);
        $user = $this->getUser();
        $sessionToken = $request->headers->get('X-Cart-Token');

        return $this->cartService->getCart($site, $user, $sessionToken);
    }

    /**
     * Gère les exceptions métier.
     */
    private function handleBusinessException(\Exception $e): JsonResponse
    {
        $statusCode = match (true) {
            str_contains($e->getMessage(), 'not_found') => Response::HTTP_NOT_FOUND,
            str_contains($e->getMessage(), 'invalid') => Response::HTTP_BAD_REQUEST,
            str_contains($e->getMessage(), 'exhausted') => Response::HTTP_CONFLICT,
            default => Response::HTTP_BAD_REQUEST,
        };

        return $this->json([
            'error' => get_class($e),
            'message' => $e->getMessage()
        ], $statusCode);
    }
}

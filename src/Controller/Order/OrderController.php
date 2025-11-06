<?php

declare(strict_types=1);

namespace App\Controller\Order;

use App\Entity\Cart\Cart;
use App\Entity\Site\Site;
use App\Enum\Order\OrderStatus;
use App\Utils\ApiResponseUtils;
use App\Service\Order\OrderService;
use App\Exception\ValidationException;
use App\Repository\Cart\CartRepository;
use App\Exception\BusinessRuleException;
use App\Exception\EntityNotFoundException;
use Symfony\Component\HttpFoundation\Request;
use App\Controller\Core\AbstractApiController;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Contrôleur REST pour la gestion des commandes.
 * 
 * Endpoints :
 * - GET    /orders                    : Liste paginée (admin)
 * - GET    /orders/{id}                : Détail commande
 * - GET    /orders/reference/{ref}     : Recherche par référence
 * - POST   /orders/checkout            : Créer commande depuis panier
 * - PATCH  /orders/{id}/status         : Changer statut
 * - POST   /orders/{id}/cancel         : Annuler commande
 * - POST   /orders/{id}/refund         : Rembourser commande
 * - GET    /orders/me                  : Commandes de l'utilisateur connecté
 * - GET    /orders/statistics          : Statistiques (admin)
 * 
 * Permissions :
 * - Liste/stats : ROLE_ADMIN
 * - Checkout : Authentifié (USER)
 * - Détail : Propriétaire ou ADMIN
 * - Changement statut : ROLE_ADMIN
 */
#[Route('/orders', name: 'api_orders_')]
class OrderController extends AbstractApiController
{
    public function __construct(
        ApiResponseUtils $apiResponseUtils,
        SerializerInterface $serializer,
        private EntityManagerInterface $em,
        private readonly OrderService $orderService,
        private readonly CartRepository $cartRepository,
    ) {
        parent::__construct($apiResponseUtils, $serializer);
    }

    // ===============================================
    // CRUD STANDARD
    // ===============================================

    /**
     * Liste paginée des commandes (admin).
     * 
     * GET /orders?page=1&limit=20&status=pending&user_id=5
     * 
     * Filtres supportés :
     * - search : Recherche par référence
     * - status : pending, confirmed, processing, shipped...
     * - statuses : Tableau de statuts (ex: statuses[]=pending&statuses[]=confirmed)
     * - user_id : Filtrer par utilisateur
     * - site_id : Filtrer par site
     * - currency : EUR, USD, GBP...
     * - min_total / max_total : Montant minimum/maximum
     * - created_from / created_to : Plage de dates création
     * - validated_from / validated_to : Plage de dates validation
     * - sortBy : id, reference, status, grandTotal, createdAt, validatedAt
     * - sortOrder : ASC, DESC
     */
    #[Route('', name: 'list', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function list(Request $request): JsonResponse
    {
        $pagination = $this->getPaginationParams($request, defaultLimit: 20, maxLimit: 100);
        $filters = $this->extractFilters($request);

        $result = $this->orderService->search(
            $pagination['page'],
            $pagination['limit'],
            $filters
        );

        return $this->listResponse(
            $result,
            ['order:list', 'date'],
            'order',
            'api_orders_list'
        );
    }

    /**
     * Détail d'une commande.
     * 
     * GET /orders/42
     * 
     * Permissions :
     * - Propriétaire de la commande
     * - ROLE_ADMIN
     */
    #[Route('/{id}', name: 'show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id): JsonResponse
    {
        try {
            $order = $this->orderService->findEntityById($id);

            // Vérification permission : propriétaire ou admin
            $this->denyAccessUnlessGranted('ORDER_VIEW', $order);

            return $this->showResponse(
                $order,
                ['order:read', 'order:items', 'order:history', 'date'],
                'order'
            );
        } catch (EntityNotFoundException $e) {
            return $this->notFoundError('order', ['id' => $id]);
        }
    }

    /**
     * Recherche par référence.
     * 
     * GET /orders/reference/2025-01-00142
     * 
     * Public (avec vérification email pour invités).
     */
    #[Route('/reference/{reference}', name: 'show_by_reference', methods: ['GET'])]
    public function showByReference(string $reference, Request $request): JsonResponse
    {
        $order = $this->orderService->findByReference($reference);

        if (!$order) {
            return $this->notFoundError('order', ['reference' => $reference]);
        }

        // Vérification permission
        $this->denyAccessUnlessGranted('ORDER_VIEW', $order);

        return $this->showResponse(
            $order,
            ['order:read', 'order:items', 'date'],
            'order'
        );
    }

    /**
     * Commandes de l'utilisateur connecté.
     * 
     * GET /orders/me?limit=10
     */
    #[Route('/me', name: 'my_orders', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function myOrders(Request $request): JsonResponse
    {
        $user = $this->getUser();
        $limit = min(50, max(1, (int)$request->query->get('limit', 20)));

        $orders = $this->orderService->findUserOrders($user, $limit);

        $serialized = $this->serialize($orders, ['order:list', 'date']);

        return $this->apiResponseUtils->success(
            data: $serialized,
            entityKey: 'orders'
        );
    }

    // ===============================================
    // CHECKOUT (CRÉATION COMMANDE)
    // ===============================================

    /**
     * Créer une commande depuis le panier (checkout).
     * 
     * POST /orders/checkout
     * 
     * Body :
     * {
     *   "cart_id": 42,
     *   "shipping_address": {
     *     "firstName": "Jean",
     *     "lastName": "Dupont",
     *     "street": "123 Rue Bio",
     *     "postalCode": "75001",
     *     "city": "Paris",
     *     "countryCode": "FR",
     *     "phone": "+33123456789"
     *   },
     *   "billing_address": { ... },
     *   "customer_message": "Livrer après 18h SVP",
     *   "payment_method": "stripe"
     * }
     * 
     * Réponse 201 :
     * {
     *   "success": true,
     *   "message": "Commande créée avec succès",
     *   "order": {
     *     "id": 123,
     *     "reference": "2025-01-00142",
     *     "status": "pending",
     *     "grand_total": 51.80,
     *     ...
     *   },
     *   "payment_intent_client_secret": "pi_xxx_secret_yyy" (si Stripe)
     * }
     */
    #[Route('/checkout', name: 'checkout', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function checkout(Request $request): JsonResponse
    {
        $data = $this->getJsonData($request);

        try {
            // Validation champs requis
            $this->requireFields($data, ['cart_id', 'shipping_address', 'billing_address'], 'Champs requis manquants');

            // Récupérer le panier
            $cart = $this->cartRepository->find($data['cart_id']);

            if (!$cart) {
                return $this->notFoundError('cart', ['id' => $data['cart_id']]);
            }

            // Vérifier que le panier appartient à l'utilisateur
            if ($cart->getUser() !== $this->getUser() && !$cart->isGuestCart()) {
                return $this->apiResponseUtils->error(
                    errors: [['message' => 'Ce panier ne vous appartient pas']],
                    messageKey: 'cart.access_denied',
                    status: 403
                );
            }

            // Métadonnées (IP, user-agent pour RGPD)
            $metadata = [
                'ip_address' => $request->getClientIp(),
                'user_agent' => $request->headers->get('User-Agent'),
                'payment_method' => $data['payment_method'] ?? 'unknown',
            ];

            // Créer la commande
            $order = $this->orderService->createFromCart(
                cart: $cart,
                shippingAddress: $data['shipping_address'],
                billingAddress: $data['billing_address'],
                customerMessage: $data['customer_message'] ?? null,
                metadata: $metadata
            );

            // Réponse avec la commande créée
            $serialized = $this->serialize($order, ['order:read', 'order:items', 'date']);

            // TODO: Créer Payment Intent Stripe et retourner client_secret
            // $paymentIntent = $this->stripeService->createPaymentIntent($order);
            // $serialized['payment_intent_client_secret'] = $paymentIntent->client_secret;

            return $this->apiResponseUtils->created(
                data: $serialized,
                entityKey: 'order'
            );
        } catch (BusinessRuleException $e) {
            return $this->apiResponseUtils->error(
                errors: [['message' => $e->getMessage()]],
                messageKey: $e->getRule(),
                status: 400
            );
        } catch (ValidationException $e) {
            return $this->validationError($e->getFormattedErrors());
        }
    }

    // ===============================================
    // CHANGEMENT STATUT
    // ===============================================

    /**
     * Change le statut d'une commande.
     * 
     * PATCH /orders/42/status
     * 
     * Body :
     * {
     *   "status": "confirmed",
     *   "reason": "Paiement validé",
     *   "metadata": {
     *     "transaction_id": "pi_3abc123",
     *     "tracking_number": "LA123456789FR"
     *   }
     * }
     * 
     * Statuts disponibles :
     * - confirmed : Paiement validé
     * - processing : En préparation
     * - shipped : Expédié
     * - delivered : Livré
     * - completed : Terminé
     * - cancelled : Annulé
     * - refunded : Remboursé
     * - on_hold : En attente
     */
    #[Route('/{id}/status', name: 'change_status', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_ADMIN')]
    public function changeStatus(int $id, Request $request): JsonResponse
    {
        $data = $this->getJsonData($request);

        try {
            $this->requireField($data, 'status', 'Le statut est requis');

            // Valider que le statut existe
            $newStatus = OrderStatus::tryFrom($data['status']);
            if (!$newStatus) {
                return $this->apiResponseUtils->error(
                    errors: [['field' => 'status', 'message' => 'Statut invalide']],
                    messageKey: 'order.invalid_status',
                    status: 400
                );
            }

            // Changer le statut
            $order = $this->orderService->changeOrderStatus(
                orderId: $id,
                newStatus: $newStatus,
                changedBy: $this->getUser(),
                changedByType: 'admin',
                reason: $data['reason'] ?? null,
                metadata: $data['metadata'] ?? null
            );

            $serialized = $this->serialize($order, ['order:read', 'date']);

            return $this->apiResponseUtils->success(
                data: $serialized,
                messageKey: 'order.status_changed',
                entityKey: 'order'
            );
        } catch (BusinessRuleException $e) {
            return $this->apiResponseUtils->error(
                errors: [['message' => $e->getMessage()]],
                messageKey: $e->getRule(),
                status: 400
            );
        }
    }

    // ===============================================
    // ACTIONS SPÉCIALISÉES
    // ===============================================

    /**
     * Annule une commande.
     * 
     * POST /orders/42/cancel
     * 
     * Body (optionnel) :
     * {
     *   "reason": "Client a changé d'avis"
     * }
     * 
     * Permissions :
     * - Propriétaire (si commande annulable par client)
     * - ROLE_ADMIN
     */
    #[Route('/{id}/cancel', name: 'cancel', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function cancel(int $id, Request $request): JsonResponse
    {
        $data = $this->getJsonData($request);

        try {
            $user = $this->getUser();
            $order = $this->orderService->findEntityById($id);

            // Vérification permission
            $isAdmin = in_array('ROLE_ADMIN', $user->getRoles(), true);
            $isOwner = $order->getUser() === $user;

            if (!$isAdmin && !$isOwner) {
                return $this->apiResponseUtils->error(
                    errors: [['message' => 'Accès refusé']],
                    messageKey: 'order.access_denied',
                    status: 403
                );
            }

            // Annuler
            $order = $this->orderService->cancelOrder(
                orderId: $id,
                cancelledBy: $user,
                cancelledByType: $isAdmin ? 'admin' : 'customer',
                reason: $data['reason'] ?? 'Annulation demandée'
            );

            $serialized = $this->serialize($order, ['order:read', 'date']);

            return $this->apiResponseUtils->success(
                data: $serialized,
                messageKey: 'order.cancelled',
                entityKey: 'order'
            );
        } catch (BusinessRuleException $e) {
            return $this->apiResponseUtils->error(
                errors: [['message' => $e->getMessage()]],
                messageKey: $e->getRule(),
                status: 400
            );
        } catch (EntityNotFoundException $e) {
            return $this->notFoundError('order', ['id' => $id]);
        }
    }

    /**
     * Rembourse une commande.
     * 
     * POST /orders/42/refund
     * 
     * Body (optionnel) :
     * {
     *   "reason": "Produit défectueux",
     *   "amount": 51.80 (optionnel, montant partiel)
     * }
     * 
     * Permissions : ROLE_ADMIN uniquement
     */
    #[Route('/{id}/refund', name: 'refund', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_ADMIN')]
    public function refund(int $id, Request $request): JsonResponse
    {
        $data = $this->getJsonData($request);

        try {
            // TODO: Gérer remboursement partiel si amount fourni
            $refundMetadata = [];
            if (isset($data['amount'])) {
                $refundMetadata['refund_amount'] = $data['amount'];
                $refundMetadata['refund_type'] = 'partial';
            } else {
                $refundMetadata['refund_type'] = 'full';
            }

            $order = $this->orderService->refundOrder(
                orderId: $id,
                refundedBy: $this->getUser(),
                reason: $data['reason'] ?? 'Remboursement demandé',
                refundMetadata: $refundMetadata
            );

            $serialized = $this->serialize($order, ['order:read', 'date']);

            return $this->apiResponseUtils->success(
                data: $serialized,
                messageKey: 'order.refunded',
                entityKey: 'order'
            );
        } catch (BusinessRuleException $e) {
            return $this->apiResponseUtils->error(
                errors: [['message' => $e->getMessage()]],
                messageKey: $e->getRule(),
                status: 400
            );
        } catch (EntityNotFoundException $e) {
            return $this->notFoundError('order', ['id' => $id]);
        }
    }

    /**
     * Met une commande en attente (ON_HOLD).
     * 
     * POST /api/orders/42/hold
     * 
     * Body :
     * {
     *   "reason": "Adresse de livraison invalide"
     * }
     */
    #[Route('/{id}/hold', name: 'hold', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_ADMIN')]
    public function putOnHold(int $id, Request $request): JsonResponse
    {
        $data = $this->getJsonData($request);

        try {
            $this->requireField($data, 'reason', 'La raison est requise');

            $order = $this->orderService->putOnHold(
                orderId: $id,
                reason: $data['reason'],
                admin: $this->getUser()
            );

            $serialized = $this->serialize($order, ['order:read', 'date']);

            return $this->apiResponseUtils->success(
                data: $serialized,
                messageKey: 'order.on_hold',
                entityKey: 'order'
            );
        } catch (BusinessRuleException $e) {
            return $this->apiResponseUtils->error(
                errors: [['message' => $e->getMessage()]],
                messageKey: $e->getRule(),
                status: 400
            );
        } catch (EntityNotFoundException $e) {
            return $this->notFoundError('order', ['id' => $id]);
        }
    }

    // ===============================================
    // STATISTIQUES (ADMIN)
    // ===============================================

    /**
     * Statistiques des commandes.
     * 
     * GET /orders/statistics?from=2025-01-01&to=2025-01-31&site_id=1
     * 
     * Réponse :
     * {
     *   "total_orders": 142,
     *   "total_revenue": 12450.50,
     *   "average_order_value": 87.68,
     *   "status_distribution": {
     *     "pending": { "count": 5, "label": "En attente" },
     *     "confirmed": { "count": 120, "label": "Confirmée" },
     *     ...
     *   }
     * }
     */
    #[Route('/statistics', name: 'statistics', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function statistics(Request $request): JsonResponse
    {
        // Dates par défaut : mois en cours
        $from = $request->query->get('from')
            ? new \DateTimeImmutable($request->query->get('from'))
            : new \DateTimeImmutable('first day of this month');

        $to = $request->query->get('to')
            ? new \DateTimeImmutable($request->query->get('to'))
            : new \DateTimeImmutable('last day of this month');

        // Site optionnel
        $siteId = $request->query->get('site_id');
        $site = $siteId ? $this->em->find(Site::class, $siteId) : null;

        // Récupérer statistiques
        $stats = $this->orderService->getStatistics($from, $to, $site);

        // Ajouter répartition par statut
        $stats['status_distribution'] = $this->orderService
            ->getStatusDistribution($site, $from, $to, 'createdAt');

        return $this->apiResponseUtils->success(
            data: $stats,
            messageKey: 'statistics.retrieved',
            entityKey: 'statistics'
        );
    }

    // ===============================================
    // HELPERS
    // ===============================================

    /**
     * Vérifie les permissions d'accès à une commande.
     * 
     * Règles :
     * - ROLE_ADMIN : accès total
     * - Propriétaire : accès à ses propres commandes
     * - Invité : accès via email + référence (TODO)
     */
    // private function assertOrderAccess(string $attribute, mixed $subject): void
    // {
    //     $user = $this->getUser();

    //     // Admin : toujours autorisé
    //     if (in_array('ROLE_ADMIN', $user?->getRoles() ?? [], true)) {
    //         return;
    //     }

    //     // Utilisateur authentifié : vérifier propriété
    //     if ($subject instanceof \App\Entity\Order\Order) {
    //         if ($subject->getUser() === $user) {
    //             return;
    //         }
    //     }

    //     // Sinon, refuser
    //     throw new \Symfony\Component\Security\Core\Exception\AccessDeniedException('Accès refusé');
    // }
}

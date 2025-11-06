<?php

declare(strict_types=1);

namespace App\Service\Order;

use App\Entity\Cart\Cart;
use App\Entity\Order\Order;
use App\Entity\Order\OrderItem;
use App\Entity\Site\Site;
use App\Entity\User\User;
use App\Enum\Order\OrderStatus;
use App\Exception\BusinessRuleException;
use App\Repository\Order\OrderRepository;
use App\Service\Core\AbstractService;
use App\Service\Core\RelationProcessor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Service métier pour la gestion des commandes.
 * 
 * Responsabilités :
 * - Conversion Cart → Order (checkout)
 * - Gestion du cycle de vie (changement statut)
 * - Annulation et remboursement
 * - Calcul des totaux figés
 * - Création des snapshots (adresses, coupon, client)
 * 
 * Workflow principal :
 * 1. createFromCart() : Convertit un panier validé en commande
 * 2. changeOrderStatus() : Fait évoluer la commande dans son cycle de vie
 * 3. cancel() / refund() : Gestion des cas exceptionnels
 */
class OrderService extends AbstractService
{
    public function __construct(
        EntityManagerInterface $em,
        SerializerInterface $serializer,
        ValidatorInterface $validator,
        RelationProcessor $relationProcessor,
        private readonly OrderRepository $orderRepository,
    ) {
        parent::__construct($em, $serializer, $validator, $relationProcessor);
    }

    protected function getEntityClass(): string
    {
        return Order::class;
    }

    protected function getRepository(): OrderRepository
    {
        return $this->orderRepository;
    }

    // ===============================================
    // CRÉATION DEPUIS PANIER (CHECKOUT)
    // ===============================================

    /**
     * Crée une commande depuis un panier validé.
     * 
     * Workflow :
     * 1. Valider que le panier n'est pas vide
     * 2. Valider stock disponible pour tous les items
     * 3. Créer Order avec snapshots figés
     * 4. Créer OrderItems depuis CartItems
     * 5. Calculer et figer les totaux (HT, TVA, TTC)
     * 6. Vider le panier
     * 
     * @param Cart $cart Panier à convertir
     * @param array $shippingAddress Adresse de livraison
     * @param array $billingAddress Adresse de facturation
     * @param string|null $customerMessage Message client optionnel
     * @param array $metadata Métadonnées (IP, user-agent...)
     * @return Order Commande créée avec status = PENDING
     * @throws BusinessRuleException Si panier vide ou stock insuffisant
     */
    public function createFromCart(
        Cart $cart,
        array $shippingAddress,
        array $billingAddress,
        ?string $customerMessage = null,
        array $metadata = []
    ): Order {
        return $this->em->wrapInTransaction(function () use (
            $cart,
            $shippingAddress,
            $billingAddress,
            $customerMessage,
            $metadata
        ) {
            // Validation métier
            $this->validateCartBeforeCheckout($cart);

            // Générer référence unique
            $reference = $this->orderRepository->generateUniqueReference();

            // Créer la commande
            $order = new Order();
            $order->setReference($reference);
            $order->setSite($cart->getSite());
            $order->setUser($cart->getUser());
            $order->setStatus(OrderStatus::PENDING);
            $order->setCurrency($cart->getCurrency());
            $order->setLocale($cart->getLocale());
            $order->setCustomerType($cart->getCustomerType());

            // Snapshots adresses
            $order->setShippingAddress($shippingAddress);
            $order->setBillingAddress($billingAddress);

            // Snapshot client
            $order->setCustomerSnapshot($this->buildCustomerSnapshot($cart));

            // Snapshot coupon
            if ($cart->getCoupon() !== null) {
                $order->setCoupon($cart->getCoupon());
                $order->setAppliedCoupon([
                    'code' => $cart->getCoupon()->getCode(),
                    'type' => $cart->getCoupon()->getType(),
                    'value' => $cart->getCoupon()->getValue(),
                    'description' => $cart->getCoupon()->getDescription(),
                ]);
            }

            // Totaux figés
            $subtotal = $cart->getSubtotal();
            $discountAmount = $cart->getDiscountAmount();
            $shippingCost = $cart->getShippingCost();

            $order->setSubtotal($subtotal);
            $order->setDiscountAmount($discountAmount);
            $order->setShippingCost($shippingCost);

            // TVA (pour l'instant taux unique, évolutif vers multi-taux)
            $taxRate = 20.0; // TODO: Récupérer depuis config site ou produit
            $taxableAmount = $subtotal - $discountAmount;
            $taxAmount = round($taxableAmount * ($taxRate / 100), 2);

            $order->setTaxRate($taxRate);
            $order->setTaxAmount($taxAmount);

            // Total TTC
            $grandTotal = $taxableAmount + $taxAmount + $shippingCost;
            $order->setGrandTotal($grandTotal);

            // Message client
            $order->setCustomerMessage($customerMessage);

            // Métadonnées
            $order->setMetadata($metadata);

            // Créer les items
            foreach ($cart->getItems() as $cartItem) {
                $orderItem = OrderItem::createFromCartItem($cartItem);
                $order->addItem($orderItem);
            }

            // Validation Doctrine
            $this->validateEntity($order);

            // Persister
            $this->em->persist($order);
            $this->em->flush();

            // Vider le panier
            $cart->clear();
            $this->em->flush();

            return $order;
        });
    }

    // ===============================================
    // GESTION DU CYCLE DE VIE
    // ===============================================

    /**
     * Change le statut d'une commande avec création d'historique.
     * 
     * @param int $orderId ID de la commande
     * @param OrderStatus $newStatus Nouveau statut
     * @param User|null $changedBy Utilisateur qui fait le changement (null = système)
     * @param string $changedByType Type d'acteur (system, customer, admin)
     * @param string|null $reason Raison du changement
     * @param array|null $metadata Métadonnées additionnelles
     * @return Order Commande mise à jour
     * @throws BusinessRuleException Si transition invalide
     */
    public function changeOrderStatus(
        int $orderId,
        OrderStatus $newStatus,
        ?User $changedBy = null,
        string $changedByType = 'system',
        ?string $reason = null,
        ?array $metadata = null
    ): Order {
        return $this->em->wrapInTransaction(function () use (
            $orderId,
            $newStatus,
            $changedBy,
            $changedByType,
            $reason,
            $metadata
        ) {
            $order = $this->findEntityById($orderId);

            // Vérification transition autorisée
            if (!$order->getStatus()->canTransitionTo($newStatus)) {
                $this->throwBuisinessRule(
                    'invalid_status_transition',
                    sprintf(
                        'Cannot transition from %s to %s',
                        $order->getStatus()->value,
                        $newStatus->value
                    )
                );
            }

            // Logique métier selon le nouveau statut
            $this->applyStatusSideEffects($order, $newStatus);

            // Changer le statut avec historique
            $order->changeStatus($newStatus, $changedBy, $changedByType, $reason, $metadata);

            $this->em->flush();

            return $order;
        });
    }

    /**
     * Confirme le paiement d'une commande.
     * 
     * Workflow typique après webhook Stripe :
     * - PENDING → CONFIRMED
     * - Décrémente le stock
     * - Envoie email de confirmation
     * 
     * @param int $orderId ID de la commande
     * @param array $paymentMetadata Infos paiement (transaction_id, method...)
     */
    public function confirmPayment(int $orderId, array $paymentMetadata = []): Order
    {
        return $this->changeOrderStatus(
            orderId: $orderId,
            newStatus: OrderStatus::CONFIRMED,
            changedBy: null,
            changedByType: 'system',
            reason: 'Paiement confirmé',
            metadata: $paymentMetadata
        );
    }

    /**
     * Marque une commande comme en préparation.
     * 
     * Workflow typique :
     * - CONFIRMED → PROCESSING
     * - Stock déjà décrémenté
     * - Picking en cours
     */
    public function markAsProcessing(int $orderId, ?User $admin = null): Order
    {
        return $this->changeOrderStatus(
            orderId: $orderId,
            newStatus: OrderStatus::PROCESSING,
            changedBy: $admin,
            changedByType: 'admin',
            reason: 'Commande en préparation'
        );
    }

    /**
     * Marque une commande comme expédiée.
     * 
     * @param int $orderId ID de la commande
     * @param string|null $trackingNumber Numéro de suivi
     * @param string|null $carrier Transporteur
     * @param User|null $admin Admin qui expédie
     */
    public function markAsShipped(
        int $orderId,
        ?string $trackingNumber = null,
        ?string $carrier = null,
        ?User $admin = null
    ): Order {
        $metadata = [];
        if ($trackingNumber) {
            $metadata['tracking_number'] = $trackingNumber;
        }
        if ($carrier) {
            $metadata['carrier'] = $carrier;
        }

        return $this->changeOrderStatus(
            orderId: $orderId,
            newStatus: OrderStatus::SHIPPED,
            changedBy: $admin,
            changedByType: 'admin',
            reason: 'Colis expédié',
            metadata: $metadata
        );
    }

    /**
     * Marque une commande comme livrée.
     * 
     * @param int $orderId ID de la commande
     * @param User|null $confirmedBy Qui confirme (admin ou système si transporteur)
     */
    public function markAsDelivered(int $orderId, ?User $confirmedBy = null): Order
    {
        return $this->changeOrderStatus(
            orderId: $orderId,
            newStatus: OrderStatus::DELIVERED,
            changedBy: $confirmedBy,
            changedByType: $confirmedBy ? 'admin' : 'system',
            reason: 'Commande livrée'
        );
    }

    // ===============================================
    // ANNULATION & REMBOURSEMENT
    // ===============================================

    /**
     * Annule une commande.
     * 
     * Actions :
     * - Change statut → CANCELLED
     * - Re-crédite le stock si déjà décrémenté
     * - Peut déclencher remboursement automatique selon statut
     * 
     * @param int $orderId ID de la commande
     * @param User|null $cancelledBy Qui annule (client, admin, système)
     * @param string $cancelledByType Type d'acteur
     * @param string|null $reason Raison de l'annulation
     * @return Order Commande annulée
     * @throws BusinessRuleException Si commande non annulable
     */
    public function cancelOrder(
        int $orderId,
        ?User $cancelledBy = null,
        string $cancelledByType = 'customer',
        ?string $reason = null
    ): Order {
        return $this->em->wrapInTransaction(function () use (
            $orderId,
            $cancelledBy,
            $cancelledByType,
            $reason
        ) {
            $order = $this->findEntityById($orderId);

            // Vérifier si annulable
            if (!$order->isCancellable()) {
                $this->throwBuisinessRule(
                    'order_not_cancellable',
                    sprintf(
                        'Cannot cancel order in status %s',
                        $order->getStatus()->value
                    )
                );
            }

            // Re-créditer le stock si nécessaire
            if ($order->getStatus()->shouldDecrementStock()) {
                $this->restoreStock($order);
            }

            // Changer le statut
            $order->changeStatus(
                OrderStatus::CANCELLED,
                $cancelledBy,
                $cancelledByType,
                $reason ?? 'Commande annulée'
            );

            $this->em->flush();

            // TODO: Déclencher remboursement si déjà payée
            // TODO: Envoyer email de confirmation d'annulation

            return $order;
        });
    }

    /**
     * Rembourse une commande.
     * 
     * Actions :
     * - Change statut → REFUNDED
     * - Re-crédite le stock
     * - Déclenche remboursement Stripe
     * 
     * @param int $orderId ID de la commande
     * @param User|null $refundedBy Admin qui rembourse
     * @param string|null $reason Raison du remboursement
     * @param array $refundMetadata Infos remboursement (refund_id...)
     * @return Order Commande remboursée
     * @throws BusinessRuleException Si commande non remboursable
     */
    public function refundOrder(
        int $orderId,
        ?User $refundedBy = null,
        ?string $reason = null,
        array $refundMetadata = []
    ): Order {
        return $this->em->wrapInTransaction(function () use (
            $orderId,
            $refundedBy,
            $reason,
            $refundMetadata
        ) {
            $order = $this->findEntityById($orderId);

            // Vérifier si remboursable
            if (!$order->isRefundable()) {
                $this->throwBuisinessRule(
                    'order_not_refundable',
                    sprintf(
                        'Cannot refund order in status %s',
                        $order->getStatus()->value
                    )
                );
            }

            // Re-créditer le stock
            $this->restoreStock($order);

            // Changer le statut
            $order->changeStatus(
                OrderStatus::REFUNDED,
                $refundedBy,
                'admin',
                $reason ?? 'Commande remboursée',
                $refundMetadata
            );

            $this->em->flush();

            // TODO: Déclencher remboursement Stripe
            // TODO: Envoyer email de confirmation de remboursement

            return $order;
        });
    }

    /**
     * Met une commande en attente (ON_HOLD).
     * 
     * Cas d'usage :
     * - Adresse invalide
     * - Stock insuffisant après paiement
     * - Problème détecté nécessitant intervention
     */
    public function putOnHold(
        int $orderId,
        string $reason,
        ?User $admin = null
    ): Order {
        return $this->changeOrderStatus(
            orderId: $orderId,
            newStatus: OrderStatus::ON_HOLD,
            changedBy: $admin,
            changedByType: 'admin',
            reason: $reason
        );
    }

    // ===============================================
    // RECHERCHE & STATISTIQUES
    // ===============================================

    /**
     * Trouve une commande par sa référence.
     */
    public function findByReference(string $reference): ?Order
    {
        return $this->orderRepository->findByReference($reference);
    }

    /**
     * Trouve les commandes d'un utilisateur.
     */
    public function findUserOrders(User $user, int $limit = 20): array
    {
        return $this->orderRepository->findByUser($user, $limit);
    }

    /**
     * Trouve les commandes d'un site.
     */
    public function findSiteOrders(Site $site, int $limit = 20): array
    {
        return $this->orderRepository->findBySite($site, $limit);
    }

    /**
     * Calcule le CA sur une période.
     */
    public function calculateRevenue(
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        ?Site $site = null
    ): float {
        return $this->orderRepository->calculateRevenue($from, $to, $site);
    }

    /**
     * Statistiques globales.
     */
    public function getStatistics(
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        ?Site $site = null
    ): array {
        return $this->orderRepository->getStatistics($from, $to, $site);
    }

    public function getStatusDistribution(?Site $site = null, ?\DateTimeImmutable $from = null, ?\DateTimeImmutable $to = null, string $dateField = 'createdAt'): array
    {
        return $this->orderRepository->getStatusDistribution($site, $from, $to, $dateField);
    }

    // ===============================================
    // VALIDATION & HELPERS PRIVÉS
    // ===============================================

    /**
     * Valide qu'un panier peut être converti en commande.
     */
    private function validateCartBeforeCheckout(Cart $cart): void
    {
        // Panier vide
        if ($cart->isEmpty()) {
            $this->throwBuisinessRule('empty_cart', 'Le panier est vide');
        }

        // Vérifier stock pour chaque item
        foreach ($cart->getItems() as $item) {
            if (!$item->isOrderable()) {
                $this->throwBuisinessRule(
                    'item_not_orderable',
                    sprintf(
                        'Le produit "%s" n\'est plus disponible',
                        $item->getDisplayName()
                    )
                );
            }
        }

        // Vérifier prix non changés (seuil 5%)
        foreach ($cart->getItems() as $item) {
            if ($item->hasPriceChanged(5)) {
                $this->throwBuisinessRule(
                    'price_changed',
                    sprintf(
                        'Le prix de "%s" a changé. Veuillez rafraîchir votre panier.',
                        $item->getDisplayName()
                    )
                );
            }
        }
    }

    /**
     * Construit le snapshot client depuis le panier.
     */
    private function buildCustomerSnapshot(Cart $cart): array
    {
        $user = $cart->getUser();

        if ($user) {
            return [
                'email' => $user->getEmail(),
                'firstName' => $user->getFirstName(),
                'lastName' => $user->getLastName(),
                'phone' => $user->getPhone(),
                'isGuest' => false,
            ];
        }

        // Invité : données minimales
        return [
            'email' => 'guest@example.com', // À récupérer depuis le checkout form
            'firstName' => '',
            'lastName' => '',
            'phone' => '',
            'isGuest' => true,
        ];
    }

    /**
     * Applique les effets de bord lors d'un changement de statut.
     */
    private function applyStatusSideEffects(Order $order, OrderStatus $newStatus): void
    {
        // Décrémente le stock si nécessaire
        if ($newStatus->shouldDecrementStock() && !$order->getStatus()->shouldDecrementStock()) {
            $this->decrementStock($order);
        }

        // Re-crédite le stock si nécessaire
        if ($newStatus->shouldRestoreStock()) {
            $this->restoreStock($order);
        }

        // TODO: Déclencher notifications si nécessaire
        if ($newStatus->shouldNotifyCustomer()) {
            // $this->sendOrderNotification($order, $newStatus);
        }
    }

    /**
     * Décrémente le stock pour tous les items de la commande.
     */
    private function decrementStock(Order $order): void
    {
        foreach ($order->getItems() as $item) {
            $variant = $item->getVariant();
            if ($variant) {
                $variant->decrementStock($item->getQuantity());
            }
        }
    }

    /**
     * Re-crédite le stock pour tous les items de la commande.
     */
    private function restoreStock(Order $order): void
    {
        foreach ($order->getItems() as $item) {
            $variant = $item->getVariant();
            if ($variant) {
                $variant->incrementStock($item->getQuantity());
            }
        }
    }

    /**
     * Configuration des relations (aucune gérée manuellement pour Order).
     */
    protected function getRelationConfig(): array
    {
        return [
            // Les relations Order → OrderItem sont gérées via cascade persist
            // Les relations Order → OrderStatusHistory aussi
        ];
    }
}

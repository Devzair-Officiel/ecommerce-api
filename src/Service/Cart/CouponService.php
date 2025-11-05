<?php

declare(strict_types=1);

namespace App\Service\Cart;

use App\Entity\Cart\Cart;
use App\Entity\Site\Site;
use App\Entity\User\User;
use App\Entity\Cart\Coupon;
use App\Service\Core\AbstractService;
use App\Service\Core\RelationProcessor;
use App\Exception\BusinessRuleException;
use Doctrine\ORM\EntityManagerInterface;
use App\Repository\Cart\CouponRepository;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Service métier pour la gestion des coupons de réduction.
 * 
 * Responsabilités :
 * - Validation d'éligibilité
 * - Application/suppression du coupon au panier
 * - Calcul de la réduction
 * - Incrémentation compteur d'utilisation
 * - Gestion des règles métier
 */
class CouponService extends AbstractService
{
    public function __construct(
        EntityManagerInterface $em,
        SerializerInterface $serializer,
        ValidatorInterface $validator,
        RelationProcessor $relationProcessor,
        private readonly CouponRepository $couponRepository
    ) {
        parent::__construct($em, $serializer, $validator, $relationProcessor);
    }

    protected function getEntityClass(): string
    {
        return Coupon::class;
    }

    protected function getRepository(): CouponRepository
    {
        return $this->couponRepository;
    }

    // ===============================================
    // APPLICATION COUPON AU PANIER
    // ===============================================

    /**
     * Applique un coupon à un panier.
     * 
     * Workflow complet :
     * 1. Chercher le coupon par code
     * 2. Valider le coupon (existe, actif, non expiré)
     * 3. Valider l'éligibilité (utilisateur, type client, montant min)
     * 4. Appliquer le coupon au panier
     * 5. Retourner le panier mis à jour
     * 
     * @param Cart $cart Panier cible
     * @param string $code Code promo
     * @param Site $site Site concerné
     * @param User|null $user Utilisateur (null si invité)
     * @return array ['cart' => Cart, 'discount' => float, 'message' => string]
     * @throws BusinessRuleException Si coupon invalide
     */
    public function applyToCart(Cart $cart, string $code, Site $site, ?User $user = null): array
    {
        // 1. Chercher le coupon
        $coupon = $this->couponRepository->findValidByCode($code, $site);

        if (!$coupon) {
            throw new BusinessRuleException(
                'coupon_not_found',
                sprintf('Le code promo "%s" n\'existe pas ou n\'est plus valide.', $code)
            );
        }

        // 2. Vérifier si déjà appliqué
        if ($cart->getCoupon()?->getId() === $coupon->getId()) {
            throw new BusinessRuleException(
                'coupon_already_applied',
                'Ce code promo est déjà appliqué à votre panier.'
            );
        }

        // 3. Valider éligibilité
        $this->validateEligibility($cart, $coupon, $user);

        // 4. Appliquer le coupon
        $cart->setCoupon($coupon);
        $this->em->flush();

        $discount = $cart->getDiscountAmount();

        return [
            'cart' => $cart,
            'discount' => $discount,
            'message' => sprintf(
                'Code promo "%s" appliqué avec succès ! Vous économisez %.2f€.',
                $coupon->getCode(),
                $discount
            )
        ];
    }

    /**
     * Supprime le coupon d'un panier.
     * 
     * @param Cart $cart Panier concerné
     * @return Cart Panier mis à jour
     */
    public function removeFromCart(Cart $cart): Cart
    {
        if ($cart->getCoupon() === null) {
            throw new BusinessRuleException(
                'no_coupon_applied',
                'Aucun code promo appliqué à ce panier.'
            );
        }

        $cart->setCoupon(null);
        $this->em->flush();

        return $cart;
    }

    // ===============================================
    // VALIDATION D'ÉLIGIBILITÉ
    // ===============================================

    /**
     * Valide tous les critères d'éligibilité du coupon.
     * 
     * Vérifications :
     * - Montant minimum du panier
     * - Type de client autorisé
     * - Nombre d'utilisations restantes
     * - Utilisations par utilisateur
     * - Première commande uniquement (si applicable)
     * 
     * @param Cart $cart Panier concerné
     * @param Coupon $coupon Coupon à valider
     * @param User|null $user Utilisateur (null si invité)
     * @throws BusinessRuleException Si non éligible
     */
    private function validateEligibility(Cart $cart, Coupon $coupon, ?User $user = null): void
    {
        // 1. Vérifier validité globale
        if (!$coupon->isValid()) {
            throw new BusinessRuleException(
                'coupon_invalid',
                'Ce code promo n\'est plus valide.'
            );
        }

        // 2. Vérifier panier non vide
        if ($cart->isEmpty()) {
            throw new BusinessRuleException(
                'cart_empty',
                'Votre panier est vide.'
            );
        }

        // 3. Vérifier montant minimum
        $subtotal = $cart->getSubtotal();
        if (!$coupon->meetsMinimumAmount($subtotal)) {
            $minimum = $coupon->getMinimumAmount();
            throw new BusinessRuleException(
                'minimum_amount_not_met',
                sprintf(
                    'Montant minimum requis : %.2f€ (actuel : %.2f€).',
                    $minimum,
                    $subtotal
                )
            );
        }

        // 4. Vérifier type de client
        $customerType = $cart->getCustomerType();
        if (!$coupon->isValidForCustomerType($customerType)) {
            throw new BusinessRuleException(
                'customer_type_not_allowed',
                'Ce code promo n\'est pas disponible pour votre type de compte.'
            );
        }

        // 5. Vérifier limite d'utilisation globale
        if ($coupon->isExhausted()) {
            throw new BusinessRuleException(
                'coupon_exhausted',
                'Ce code promo a été utilisé le nombre maximum de fois.'
            );
        }

        // 6. Si utilisateur connecté, vérifier limites utilisateur
        if ($user !== null) {
            $userUsages = $this->couponRepository->countUsagesByUser($coupon, $user);

            if (!$coupon->canUserUse($userUsages)) {
                throw new BusinessRuleException(
                    'user_limit_reached',
                    'Vous avez déjà utilisé ce code promo le nombre maximum de fois.'
                );
            }

            // TODO: Vérifier firstOrderOnly après création de Order
            // if ($coupon->isFirstOrderOnly() && $user->getOrdersCount() > 0) {
            //     throw new BusinessRuleException(
            //         'first_order_only',
            //         'Ce code promo est réservé à la première commande uniquement.'
            //     );
            // }
        }
    }

    /**
     * Valide un coupon avant application (retourne rapport détaillé).
     * 
     * Contrairement à validateEligibility(), cette méthode ne lance pas d'exception.
     * Elle retourne un rapport avec toutes les vérifications.
     * 
     * @param Cart $cart Panier concerné
     * @param string $code Code promo
     * @param Site $site Site concerné
     * @param User|null $user Utilisateur
     * @return array Rapport de validation
     */
    public function validateCoupon(Cart $cart, string $code, Site $site, ?User $user = null): array
    {
        $report = [
            'valid' => false,
            'coupon' => null,
            'checks' => [],
            'discount' => 0.0,
            'message' => null
        ];

        // 1. Chercher le coupon
        $coupon = $this->couponRepository->findByCode($code, $site);

        if (!$coupon) {
            $report['message'] = 'Code promo introuvable.';
            $report['checks']['exists'] = false;
            return $report;
        }

        $report['coupon'] = $coupon->getSummary();
        $report['site'] = $coupon->getSite();
        $report['checks']['exists'] = true;

        // 2. Vérifier validité
        $report['checks']['is_valid'] = $coupon->isValid();
        $report['checks']['is_expired'] = $coupon->isExpired();
        $report['checks']['is_exhausted'] = $coupon->isExhausted();

        // 3. Vérifier panier
        $report['checks']['cart_not_empty'] = !$cart->isEmpty();

        // 4. Vérifier montant minimum
        $subtotal = $cart->getSubtotal();
        $report['checks']['meets_minimum'] = $coupon->meetsMinimumAmount($subtotal);
        $report['checks']['minimum_amount'] = $coupon->getMinimumAmount();
        $report['checks']['cart_subtotal'] = $subtotal;

        // 5. Vérifier type client
        $customerType = $cart->getCustomerType();
        $report['checks']['customer_type_allowed'] = $coupon->isValidForCustomerType($customerType);

        // 6. Vérifier utilisateur
        if ($user) {
            $userUsages = $this->couponRepository->countUsagesByUser($coupon, $user);
            $report['checks']['user_usages'] = $userUsages;
            $report['checks']['can_user_use'] = $coupon->canUserUse($userUsages);
        }

        // 7. Calculer réduction si éligible
        $allChecksPassed = $report['checks']['is_valid']
            && $report['checks']['cart_not_empty']
            && $report['checks']['meets_minimum']
            && $report['checks']['customer_type_allowed']
            && (!$user || $report['checks']['can_user_use'] ?? true);

        if ($allChecksPassed) {
            $report['valid'] = true;
            $report['discount'] = $coupon->calculateDiscount($subtotal);
            $report['message'] = sprintf(
                'Vous économiserez %.2f€ avec ce code promo.',
                $report['discount']
            );
        } else {
            $report['message'] = $this->getValidationErrorMessage($report['checks'], $coupon);
        }

        return $report;
    }

    /**
     * Génère un message d'erreur à partir des vérifications échouées.
     */
    private function getValidationErrorMessage(array $checks, Coupon $coupon): string
    {
        if (!$checks['is_valid']) {
            if ($checks['is_expired']) {
                return 'Ce code promo est expiré.';
            }
            if ($checks['is_exhausted']) {
                return 'Ce code promo a été utilisé le nombre maximum de fois.';
            }
            return 'Ce code promo n\'est plus valide.';
        }

        if (!$checks['cart_not_empty']) {
            return 'Votre panier est vide.';
        }

        if (!$checks['meets_minimum']) {
            return sprintf(
                'Montant minimum requis : %.2f€ (actuel : %.2f€).',
                $checks['minimum_amount'] ?? 0,
                $checks['cart_subtotal'] ?? 0
            );
        }

        if (!$checks['customer_type_allowed']) {
            return 'Ce code promo n\'est pas disponible pour votre type de compte.';
        }

        if (isset($checks['can_user_use']) && !$checks['can_user_use']) {
            return 'Vous avez déjà utilisé ce code promo le nombre maximum de fois.';
        }

        return 'Impossible d\'appliquer ce code promo.';
    }

    // ===============================================
    // INCRÉMENTATION UTILISATION
    // ===============================================

    /**
     * Incrémente le compteur d'utilisation d'un coupon.
     * 
     * Appelé après création d'une commande validée.
     * 
     * @param Coupon $coupon Coupon utilisé
     */
    public function incrementUsage(Coupon $coupon): void
    {
        $coupon->incrementUsageCount();
        $this->em->flush();
    }

    // ===============================================
    // RÉCUPÉRATION & STATISTIQUES
    // ===============================================

    /**
     * Récupère un coupon par code (pour affichage admin).
     * 
     * @param string $code Code promo
     * @param Site $site Site concerné
     * @return Coupon|null
     */
    public function findByCode(string $code, Site $site): ?Coupon
    {
        return $this->couponRepository->findByCode($code, $site);
    }

    /**
     * Récupère tous les coupons actifs d'un site.
     * 
     * @param Site $site Site concerné
     * @return Coupon[]
     */
    public function getActiveCoupons(Site $site): array
    {
        return $this->couponRepository->findActiveBySite($site);
    }

    /**
     * Récupère les coupons les plus utilisés.
     * 
     * @param Site $site Site concerné
     * @param int $limit Nombre de résultats
     * @return Coupon[]
     */
    public function getMostUsedCoupons(Site $site, int $limit = 10): array
    {
        return $this->couponRepository->findMostUsed($site, $limit);
    }

    // ===============================================
    // CRUD ADMIN
    // ===============================================

    /**
     * Crée un nouveau coupon.
     * 
     * @param array $data Données du coupon
     * @return Coupon Coupon créé
     */
    public function createCoupon(array $data): Coupon
    {
        $coupon = new Coupon();

        // Mapper les données
        $this->hydrateCoupon($coupon, $data);

        // Valider
        $this->validateEntity($coupon);

        // Persister
        $this->em->persist($coupon);
        $this->em->flush();

        return $coupon;
    }

    /**
     * Met à jour un coupon existant.
     * 
     * @param int $id ID du coupon
     * @param array $data Données à mettre à jour
     * @return Coupon Coupon mis à jour
     */
    public function updateCoupon(int $id, array $data): Coupon
    {
        $coupon = $this->couponRepository->find($id);

        if (!$coupon) {
            throw new BusinessRuleException('coupon_not_found', 'Coupon non trouvé.');
        }

        $this->hydrateCoupon($coupon, $data);
        $this->validateEntity($coupon);
        $this->em->flush();

        return $coupon;
    }

    /**
     * Hydrate un coupon avec les données.
     */
    private function hydrateCoupon(Coupon $coupon, array $data): void
    {
        if (isset($data['code'])) {
            $coupon->setCode($data['code']);
        }

        if (isset($data['type'])) {
            $coupon->setType($data['type']);
        }

        if (isset($data['value'])) {
            $coupon->setValue($data['value']);
        }

        if (isset($data['minimum_amount'])) {
            $coupon->setMinimumAmount($data['minimum_amount']);
        }

        if (isset($data['maximum_discount'])) {
            $coupon->setMaximumDiscount($data['maximum_discount']);
        }

        if (isset($data['valid_from'])) {
            $coupon->setValidFrom(new \DateTimeImmutable($data['valid_from']));
        }

        if (isset($data['valid_until'])) {
            $coupon->setValidUntil(new \DateTimeImmutable($data['valid_until']));
        }

        if (isset($data['max_usages'])) {
            $coupon->setMaxUsages($data['max_usages']);
        }

        if (isset($data['max_usages_per_user'])) {
            $coupon->setMaxUsagesPerUser($data['max_usages_per_user']);
        }

        if (isset($data['first_order_only'])) {
            $coupon->setFirstOrderOnly($data['first_order_only']);
        }

        if (isset($data['allowed_customer_types'])) {
            $coupon->setAllowedCustomerTypes($data['allowed_customer_types']);
        }

        if (isset($data['public_message'])) {
            $coupon->setPublicMessage($data['public_message']);
        }

        if (isset($data['internal_note'])) {
            $coupon->setInternalNote($data['internal_note']);
        }

        if (isset($data['site_id'])) {
            // Récupérer le site
            // TODO: Injecter SiteRepository si nécessaire
        }
    }
}

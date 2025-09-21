<?php

// ===============================================
// src/Validator/BusinessRuleValidator.php - Nouveau validateur custom
// ===============================================

declare(strict_types=1);

namespace App\Validator;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

/**
 * Validateur pour les règles métier complexes.
 * 
 * Centralise la validation métier qui ne peut pas être exprimée
 * avec les contraintes Symfony standard.
 */
class BusinessRuleValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof BusinessRule) {
            throw new UnexpectedTypeException($constraint, BusinessRule::class);
        }

        if (null === $value || '' === $value) {
            return;
        }

        // Déléguer à la méthode de validation spécifique
        $method = $constraint->method;
        if (method_exists($this, $method)) {
            $this->$method($value, $constraint);
        }
    }

    /**
     * Valide qu'un prix original est supérieur au prix actuel.
     */
    private function validateOriginalPrice(mixed $value, BusinessRule $constraint): void
    {
        $entity = $this->context->getObject();

        if (!$entity || !method_exists($entity, 'getPrice')) {
            return;
        }

        $currentPrice = $entity->getPrice();
        if ($currentPrice && (float) $value <= (float) $currentPrice) {
            $this->context->buildViolation($constraint->message)
                ->setParameter('{{ current_price }}', $currentPrice)
                ->addViolation();
        }
    }

    /**
     * Valide qu'un stock est cohérent avec le stock minimum.
     */
    private function validateStockConsistency(mixed $value, BusinessRule $constraint): void
    {
        $entity = $this->context->getObject();

        if (!$entity || !method_exists($entity, 'getMinStock')) {
            return;
        }

        $minStock = $entity->getMinStock();
        if ($minStock && (int) $value < $minStock && $value > 0) {
            $this->context->buildViolation($constraint->message)
                ->setParameter('{{ min_stock }}', (string) $minStock)
                ->addViolation();
        }
    }
}

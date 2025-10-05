<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Exception pour les règles métier violées.
 * 
 * Utilisée pour les validations business qui ne peuvent pas être
 * exprimées par des contraintes Symfony (ex: email unique, règles complexes).
 */
class BusinessRuleException extends AppException
{
    public function __construct(
        string $rule,
        string $message = '',
        array $context = []
    ) {
        $message = $message ?: "Business rule violation: {$rule}";
        parent::__construct($message, array_merge($context, ['rule' => $rule]));
    }

    public function getStatusCode(): int
    {
        return 400; // Bad Request
    }

    public function getMessageKey(): string
    {
        return 'business_rule.violation';
    }

    public function getRule(): string
    {
        return $this->context['rule'];
    }
}
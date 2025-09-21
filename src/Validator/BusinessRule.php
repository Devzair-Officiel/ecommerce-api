<?php

declare(strict_types=1);

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * Contrainte pour valider les règles métier complexes.
 */
#[\Attribute]
class BusinessRule extends Constraint
{
    public string $message = 'validation.business_rule.default';
    public string $method = '';

    public function __construct(string $method, string $message, mixed $options = null, array $groups, mixed $payload = null)
    {
        parent::__construct($options, $groups, $payload);

        $this->method = $method;
        if ($message !== null) {
            $this->message = $message;
        }
    }
}
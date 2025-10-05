<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Exception pour les opérations non permises sur une ressource.
 */
class OperationNotAllowedException extends AppException
{
    public function __construct(
        string $operation,
        string $resource,
        string $reason = '',
        array $context = []
    ) {
        $message = "Operation '{$operation}' not allowed on '{$resource}'";
        if ($reason) {
            $message .= ": {$reason}";
        }

        parent::__construct($message, array_merge($context, [
            'operation' => $operation,
            'resource' => $resource,
            'reason' => $reason
        ]));
    }

    public function getStatusCode(): int
    {
        return 422; // Unprocessable Entity
    }

    public function getMessageKey(): string
    {
        return 'operation.not_allowed';
    }
}
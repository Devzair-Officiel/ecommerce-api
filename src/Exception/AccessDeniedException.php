<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Exception pour les accès non autorisés.
 */
class AccessDeniedException extends AppException
{
    public function __construct(
        string $resource = '',
        string $action = '',
        array $context = []
    ) {
        $message = "Access denied";
        if ($resource && $action) {
            $message .= " for action '{$action}' on resource '{$resource}'";
        }

        parent::__construct($message, array_merge($context, [
            'resource' => $resource,
            'action' => $action
        ]));
    }

    public function getStatusCode(): int
    {
        return 403; // Forbidden
    }

    public function getMessageKey(): string
    {
        return 'access.denied';
    }
}
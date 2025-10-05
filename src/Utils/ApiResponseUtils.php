<?php

declare(strict_types=1);

namespace App\Utils;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Utilitaire principal pour standardiser les réponses API.
 * 
 * Responsabilités :
 * - Formatage uniforme des réponses JSON
 * - Messages traduits avec entités dynamiques
 * - Méthodes CRUD spécialisées
 * 
 * Délègue la gestion de pagination à ResponsePaginationHelper.
 */
final class ApiResponseUtils
{
    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly ResponsePaginationHelper $paginationHelper
    ) {}

    /**
     * Génère une réponse JSON standardisée.
     */
    public function create(
        mixed $data,
        bool $success,
        string $messageKey,
        array $messageParams = [],
        int $status = JsonResponse::HTTP_OK,
        array $errors = [],
        ?string $routeName = null,
        array $routeParams = []
    ): JsonResponse {
        $message = $messageKey ? $this->translator->trans($messageKey, $messageParams) : null;

        $response = [
            'success' => $success,
            'message' => $message,
            'status' => $status,
            'errors' => $success ? null : $errors,
        ];

        // Délégation de la gestion pagination
        if ($this->paginationHelper->isPaginated($data)) {
            $response += $this->paginationHelper->buildPaginatedResponse($data, $routeName, $routeParams);
        } elseif ($data !== null) {
            $response['data'] = $data;
        }

        return new JsonResponse($response, $status);
    }

    /**
     * Réponse de succès générique.
     */
    public function success(
        mixed $data = null,
        string $messageKey = 'success.default',
        array $messageParams = [],
        int $status = JsonResponse::HTTP_OK,
        ?string $entityKey = null,
        ?string $routeName = null,
        array $routeParams = []
    ): JsonResponse {
        if ($entityKey) {
            $messageParams['%entity%'] = $this->translator->trans("entity_name.$entityKey");
        }

        return $this->create(
            data: $data,
            success: true,
            messageKey: $messageKey,
            messageParams: $messageParams,
            status: $status,
            routeName: $routeName,
            routeParams: $routeParams
        );
    }

    /**
     * Réponse d'erreur générique.
     */
    public function error(
        array $errors = [],
        string $messageKey = 'error.default',
        array $messageParams = [],
        int $status = JsonResponse::HTTP_BAD_REQUEST,
        ?string $entityKey = null
    ): JsonResponse {
        if ($entityKey) {
            $messageParams['%entity%'] = $this->translator->trans("entity_name.$entityKey");
        }

        return $this->create(
            data: null,
            success: false,
            messageKey: $messageKey,
            messageParams: $messageParams,
            status: $status,
            errors: $errors
        );
    }

    // ===============================================
    // MÉTHODES CRUD SPÉCIALISÉES
    // ===============================================

    public function created(mixed $data, string $entityKey): JsonResponse
    {
        return $this->success(
            data: $data,
            messageKey: 'entity.created',
            status: Response::HTTP_CREATED,
            entityKey: $entityKey
        );
    }

    public function updated(mixed $data, string $entityKey): JsonResponse
    {
        return $this->success(
            data: $data,
            messageKey: 'entity.updated',
            entityKey: $entityKey
        );
    }

    public function deleted(mixed $data, string $entityKey): JsonResponse
    {
        return $this->success(
            data: $data,
            messageKey: 'entity.deleted',
            entityKey: $entityKey
        );
    }

    public function retrieved(mixed $data, string $entityKey): JsonResponse
    {
        return $this->success(
            data: $data,
            messageKey: 'entity.retrieved',
            entityKey: $entityKey
        );
    }

    public function listRetrieved(mixed $data, string $entityKey, string $routeName, array $routeParams = []): JsonResponse
    {
        return $this->success(
            data: $data,
            messageKey: 'entity.list_retrieved',
            entityKey: $entityKey,
            routeName: $routeName,
            routeParams: $routeParams
        );
    }

    public function notFound(string $entityKey, array $criteria = []): JsonResponse
    {
        return $this->error(
            errors: [['criteria' => $criteria]],
            messageKey: 'entity.not_found',
            status: Response::HTTP_NOT_FOUND,
            entityKey: $entityKey
        );
    }

    public function validationFailed(array $errors): JsonResponse
    {
        return $this->error(
            errors: $errors,
            messageKey: 'validation.failed',
            status: Response::HTTP_UNPROCESSABLE_ENTITY
        );
    }

    public function accessDenied(): JsonResponse
    {
        return $this->error(
            errors: [['message' => 'Access denied']],
            messageKey: 'error.access_denied',
            status: Response::HTTP_FORBIDDEN
        );
    }

    public function statusChanged(mixed $data, string $entityKey, bool $valid): JsonResponse
    {
        $messageKey = $valid ? 'entity.activated' : 'entity.deactivated';

        return $this->success(
            data: $data,
            messageKey: $messageKey,
            entityKey: $entityKey
        );
    }
}

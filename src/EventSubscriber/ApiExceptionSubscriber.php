<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Exception\{
    AppException,
    ValidationException,
    EntityNotFoundException,
    BusinessRuleException,
    ConflictException
};
use App\Utils\ApiResponseUtils;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Contracts\Translation\TranslatorInterface;


/**
 * Subscriber pour gérer automatiquement toutes les exceptions de l'API.
 * 
 * Responsabilités :
 * - Convertir les exceptions en réponses JSON standardisées
 * - Logger les erreurs selon leur gravité
 * - Gérer les exceptions Doctrine (contraintes uniques, etc.)
 * - Formater les messages d'erreur pour l'utilisateur final
 * 
 * Avantages du Subscriber :
 * - Auto-configuration (pas de YAML nécessaire)
 * - Priorité définie dans le code
 * - Maintenabilité (toute la logique au même endroit)
 */
class ApiExceptionSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly ApiResponseUtils $apiResponseUtils,
        private readonly LoggerInterface $logger,
        private readonly TranslatorInterface $translator
    ) {}

    /**
     * Configuration des événements écoutés.
     * 
     * Priorité 0 : exécuté après les listeners Symfony de sécurité
     * mais avant les listeners de debug.
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::EXCEPTION => ['onKernelException', 0],
        ];
    }

    /**
     * Gestionnaire principal des exceptions.
     */
    public function onKernelException(ExceptionEvent $event): void
    {
        // Traiter uniquement les requêtes vers /api/*
        if (!$this->isApiRequest($event)) {
            return;
        }

        $exception = $event->getThrowable();

        // Log de l'exception selon sa gravité
        $this->logException($exception);

        // Conversion en réponse JSON
        $response = $this->createJsonResponse($exception);

        $event->setResponse($response);
    }

    // ===============================================
    // CRÉATION DES RÉPONSES JSON
    // ===============================================

    private function createJsonResponse(\Throwable $exception): JsonResponse
    {
        return match (true) {
            // Exceptions métier de l'application
            $exception instanceof ValidationException => $this->handleValidationException($exception),
            $exception instanceof EntityNotFoundException => $this->handleEntityNotFoundException($exception),
            $exception instanceof ConflictException => $this->handleConflictException($exception),
            $exception instanceof BusinessRuleException => $this->handleBusinessRuleException($exception),
            $exception instanceof AppException => $this->handleAppException($exception),

            // Exceptions Doctrine
            $exception instanceof UniqueConstraintViolationException => $this->handleUniqueConstraintViolation($exception),

            // Exceptions HTTP Symfony
            $exception instanceof HttpExceptionInterface => $this->handleHttpException($exception),

            // Autres exceptions (500)
            default => $this->handleGenericException($exception),
        };
    }

    /**
     * Gestion des erreurs de validation.
     */
    private function handleValidationException(ValidationException $exception): JsonResponse
    {
        return $this->apiResponseUtils->validationFailed(
            $exception->getFormattedErrors()
        );
    }

    /**
     * Gestion des entités non trouvées.
     */
    private function handleEntityNotFoundException(EntityNotFoundException $exception): JsonResponse
    {
        return $this->apiResponseUtils->notFound(
            $exception->getContext()['entity'],
            $exception->getCriteria()
        );
    }

    /**
     * Gestion des conflits (ex: email déjà utilisé).
     */
    private function handleConflictException(ConflictException $exception): JsonResponse
    {
        $context = $exception->getContext();

        return $this->apiResponseUtils->error(
            errors: [[
                'field' => $context['conflict_field'],
                'value' => $context['conflict_value'],
                'message' => $exception->getMessage()
            ]],
            messageKey: 'resource.conflict',
            messageParams: [
                '%resource%' => $context['resource'],
                '%field%' => $context['conflict_field'],
                '%value%' => $context['conflict_value']
            ],
            status: $exception->getStatusCode()
        );
    }

    /**
     * Gestion des règles métier violées.
     */
    private function handleBusinessRuleException(BusinessRuleException $exception): JsonResponse
    {
        return $this->apiResponseUtils->error(
            errors: [[
                'rule' => $exception->getRule(),
                'message' => $exception->getMessage()
            ]],
            messageKey: $exception->getMessageKey(),
            messageParams: $exception->getMessageParameters(),
            status: $exception->getStatusCode()
        );
    }

    /**
     * Gestion des autres exceptions métier (AppException).
     */
    private function handleAppException(AppException $exception): JsonResponse
    {
        return $this->apiResponseUtils->error(
            errors: [['message' => $exception->getMessage()]],
            messageKey: $exception->getMessageKey(),
            messageParams: $exception->getMessageParameters(),
            status: $exception->getStatusCode()
        );
    }

    /**
     * Gestion des contraintes uniques Doctrine.
     * 
     * Convertit les erreurs DB en messages utilisateur compréhensibles.
     */
    private function handleUniqueConstraintViolation(UniqueConstraintViolationException $exception): JsonResponse
    {
        // Extraire le nom de la contrainte si possible
        $message = $exception->getMessage();
        $field = $this->extractFieldFromConstraint($message);

        return $this->apiResponseUtils->error(
            errors: [[
                'field' => $field,
                'message' => "This {$field} is already in use"
            ]],
            messageKey: 'database.unique_constraint',
            status: 409
        );
    }

    /**
     * Gestion des exceptions HTTP Symfony.
     */
    private function handleHttpException(HttpExceptionInterface $exception): JsonResponse
    {
        return $this->apiResponseUtils->error(
            errors: [['message' => $exception->getMessage()]],
            messageKey: 'http.error',
            status: $exception->getStatusCode()
        );
    }

    /**
     * Gestion des exceptions génériques (500).
     */
    private function handleGenericException(\Throwable $exception): JsonResponse
    {
        // En production, ne pas exposer les détails de l'erreur
        $message = $this->isDebugMode()
            ? $exception->getMessage()
            : 'An unexpected error occurred';

        return $this->apiResponseUtils->error(
            errors: [['message' => $message]],
            messageKey: 'error.internal',
            status: 500
        );
    }

    // ===============================================
    // LOGGING DES EXCEPTIONS
    // ===============================================

    private function logException(\Throwable $exception): void
    {
        $level = $this->getLogLevel($exception);

        $this->logger->log($level, $exception->getMessage(), [
            'exception' => $exception,
            'class' => get_class($exception),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $exception->getTraceAsString(),
        ]);
    }

    /**
     * Détermine le niveau de log selon le type d'exception.
     */
    private function getLogLevel(\Throwable $exception): string
    {
        return match (true) {
            // Erreurs utilisateur = warning (pas grave)
            $exception instanceof ValidationException => 'warning',
            $exception instanceof EntityNotFoundException => 'info',
            $exception instanceof ConflictException => 'warning',
            $exception instanceof BusinessRuleException => 'warning',

            // Erreurs HTTP < 500 = warning
            $exception instanceof HttpExceptionInterface && $exception->getStatusCode() < 500 => 'warning',

            // Erreurs serveur = error (grave)
            default => 'error',
        };
    }

    // ===============================================
    // MÉTHODES UTILITAIRES
    // ===============================================

    /**
     * Vérifie si la requête est une requête API.
     */
    private function isApiRequest(ExceptionEvent $event): bool
    {
        return str_starts_with($event->getRequest()->getPathInfo(), '/api/');
    }

    /**
     * Extrait le nom du champ depuis le message d'erreur de contrainte unique.
     */
    private function extractFieldFromConstraint(string $message): string
    {
        // Regex pour extraire le nom de la contrainte
        // Ex: "Duplicate entry 'test@example.com' for key 'UNIQ_IDENTIFIER_EMAIL'"
        if (preg_match('/for key [\'"](.+?)[\'"]/i', $message, $matches)) {
            $constraintName = $matches[1];

            // Extraire le nom du champ depuis le nom de la contrainte
            // Ex: "UNIQ_IDENTIFIER_EMAIL" -> "email"
            if (preg_match('/_([a-z]+)$/i', $constraintName, $fieldMatches)) {
                return strtolower($fieldMatches[1]);
            }
        }

        return 'field';
    }

    /**
     * Vérifie si on est en mode debug.
     */
    private function isDebugMode(): bool
    {
        return isset($_ENV['APP_ENV']) && $_ENV['APP_ENV'] === 'dev';
    }
}

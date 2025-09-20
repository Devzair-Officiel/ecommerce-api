<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Utils\ApiResponseUtils;
use App\Exception\PaginationException;
use App\Exception\ValidationException;
use App\Exception\EntityNotFoundException;
use App\Exception\FileNotProvidedException;
use Symfony\Component\HttpFoundation\Response;
use App\Exception\EntityDeletionProhibitedException;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Subscriber pour gérer les exceptions API et normaliser les réponses d'erreur.
 * 
 * - Convertit certaines exceptions en réponses JSON standardisées.
 * - Traduit les messages d'erreur pour améliorer l'expérience utilisateur.
 */
class ApiExceptionSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private TranslatorInterface $translator,
        private ApiResponseUtils $apiResponseUtils,
    ) {}

    /**
     * Gère les exceptions et les transforme en réponses JSON.
     *
     * @param ExceptionEvent $event L'événement contenant l'exception levée.
     */
    public function onKernelException(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();

        ///////////    Gestion des erreurs de validation    //////////////
        if ($exception instanceof ValidationException) {
            $translateErrors = $this->translateValidationErrors($exception->getErrors());

            $response = $this->apiResponseUtils->error(
                errors: $translateErrors,
                messageKey: $exception->getMessage(),
                messageParams: $exception->getTranslationParameters(),
                status: $exception->getStatusCode()
            );

            $event->setResponse($response);
            return;
        }

        /////////    Gestion des erreurs de pagination    //////////////
        if ($exception instanceof PaginationException) {
            $response = $this->apiResponseUtils->error(
                errors: [
                    [
                        'setting' => 'pagination',
                        'message' => $this->translator->trans(
                            $exception->getMessage(),
                            $exception->getTranslationParameters()
                        ),
                    ],
                ],
                messageKey: $exception->getMessage(),
                messageParams: $exception->getTranslationParameters(),
                status: $exception->getStatusCode()
            );

            $event->setResponse($response);
            return;
        }

        ////////////    Gestion des erreurs d'arguments invalides   ///////////////
        if ($exception instanceof \InvalidArgumentException) {
            $response = $this->apiResponseUtils->error(
                errors: [
                    ['field' => 'json', 'message' => $exception->getMessage()]
                ],
                messageKey: 'error.invalid_request',
                status: Response::HTTP_BAD_REQUEST
            );

            $event->setResponse($response);
            return;
        }

        ///////////    Gestion des entités non trouvées    ////////////////
        if ($exception instanceof EntityNotFoundException) {

            // Traduire le nom de l'entité
            $translationParameters = $exception->getTranslationParameters();

            // Traduire la clé de l'entité (si présente)
            if (isset($translationParameters['%entity%'])) {
                $translationParameters['%entity%'] = $this->translator->trans('entity_name.' . $translationParameters['%entity%']);
            }

            // Ajouter l'ID ou d'autres paramètres à la réponse si disponible
            $errors = [
                [
                    'code' => $exception->getStatusCode(),
                    'message' => $this->translator->trans($exception->getMessage(), $translationParameters),
                ]
            ];

            // Ajouter l'ID si disponible
            if (isset($translationParameters['id'])) {
                $errors[0]['id'] = $translationParameters['id']; // Ajoute l'ID à l'erreur
            }

            $response = $this->apiResponseUtils->error(
                errors: $errors,
                messageKey: $exception->getMessage(),
                messageParams: $translationParameters,
                status: $exception->getStatusCode()
            );

            $event->setResponse($response);
            return;
        }

        ///////////    Gestion des entités non Supprimable à cause de relations existantes   ////////////////
        if ($exception instanceof EntityDeletionProhibitedException) {
            $translationParameters = $exception->getTranslationParameters();

            // Traduction des entités si présentes
            if (isset($translationParameters['%entity%'])) {
                $translationParameters['%entity%'] = $this->translator->trans('entity_name.' . $translationParameters['%entity%']);
            }

            if (isset($translationParameters['%related_entity%'])) {
                $translationParameters['%related_entity%'] = $this->translator->trans('entity_name.' . $translationParameters['%related_entity%']);
            }

            $response = $this->apiResponseUtils->error(
                errors: [
                    [
                        'message' => $this->translator->trans(
                            $exception->getMessage(),
                            $translationParameters
                        ),
                    ]
                ],
                messageKey: $exception->getMessage(),
                messageParams: $translationParameters,
                status: $exception->getStatusCode()
            );

            $event->setResponse($response);
            return;
        }

        ///////////    Gestion des fichiers non fourni lors de l'upload    ////////////////
        if ($exception instanceof FileNotProvidedException) {
            $response = $this->apiResponseUtils->error(
                errors: [
                    [
                        'field' => 'file',
                        'message' => $this->translator->trans($exception->getMessage(), $exception->getTranslationParameters())
                    ]
                ],
                messageKey: $exception->getMessage(),
                messageParams: $exception->getTranslationParameters(),
                status: $exception->getStatusCode()
            );
            $event->setResponse($response);
            return;
        }

        ///////////    Gestion des erreurs de requête incorrecte    ///////////////
        if ($exception instanceof BadRequestHttpException) {
            $response = $this->apiResponseUtils->error(
                errors: [
                    [
                        'code' => Response::HTTP_BAD_REQUEST,
                        'message' => $this->translator->trans($exception->getMessage())
                    ]
                ],
                messageKey: $exception->getMessage(),
                status: Response::HTTP_BAD_REQUEST
            );

            $event->setResponse($response);
            return;
        }
    }

    /**
     * Traduit les erreurs de validation.
     *
     * @param array<int, array<string, mixed>> $errors Liste des erreurs de validation.
     * @return array<int, array<string, string>> Erreurs traduites.
     */
    private function translateValidationErrors(array $errors): array
    {
        return array_map(
            fn($error) => [
                'field' => $error['field'],
                'message' => $this->translator->trans($error['message'], $error['params'] ?? [])
            ],
            $errors
        );
    }

    /**
     * Définit les événements écoutés par le subscriber.
     *
     * @return array<string, array<int, mixed>> Liste des événements et de leurs priorités.
     */
    public static function getSubscribedEvents(): array
    {
        return [ExceptionEvent::class => ['onKernelException', 10]];
    }
}

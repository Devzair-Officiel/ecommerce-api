<?php

declare(strict_types=1);

namespace App\Utils;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Standardisation des réponses API 
 * Principalement utilisée dans les contrôleurs pour uniformiser les réponses envoyées au client tout en centralisant la logique liée à la traduction des messages.
 */
final class ApiResponseUtils
{
    public function __construct(private TranslatorInterface $translator) {}

    /**
     * Traduit un message de réponse avec un nom d'entité dynamique.
     *
     * @param string $messageKey     La clé de traduction du message.
     * @param string $entityKey      La clé de traduction de l'entité (par ex. "user", "team").
     * @param array  $messageParams  Paramètres additionnels à injecter dans le message.
     */
    public function translateWithEntity(string $messageKey, string $entityKey, array $messageParams = []): string
    {
        // Traduire le nom de l'entité
        $entityName = $this->translator->trans("entity_name.$entityKey");

        // Injecter le nom de l'entité dans les paramètres
        $messageParams['%entity%'] = $entityName;

        // Traduire le message principal
        return $this->translator->trans($messageKey, $messageParams);
    }

    /**
     * Génère une réponse JSON standardisée.
     *
     * @param mixed $data           Les données à inclure dans la réponse (ex : un DTO, un tableau ou null).
     * @param bool $success         Indique si l'opération a réussi ou non.
     * @param string|null $message  Un message facultatif (succès ou erreur).
     * @param int $status           Le code HTTP de la réponse.
     * @param array $errors         Liste des erreurs si l'opération a échoué.
     *
     * @return JsonResponse
     */
    public function create(
        mixed $data = null,
        bool $success = true,
        string $messageKey = null,
        array $messageParams = [],
        int $status = JsonResponse::HTTP_OK,
        array $errors = []
    ): JsonResponse {
        // Traduire le message si une clé est fournie
        $message = $messageKey ? $this->translator->trans($messageKey, $messageParams) : null;
    
        // Déterminer le type de réponse en fonction de la présence ou non de données paginées
        $response = [
            'success' => $success,
            'message' => $message,
            'status' => $status,
            'errors' => $success ? null : $errors,
        ];
    
        if (is_array($data) && isset($data['items'], $data['page'], $data['total_items'])) {
            // Réponse paginée
            $response += [
                'page' => $data['page'],
                'limit' => $data['limit'],
                'total_items' => $data['total_items'],
                'total_pages' => $data['total_pages'],
                'total_items_found' => $data['total_items_found'],
                'data' => $data['items'],
                'links' => $data['links'],
            ];
        } elseif ($data !== null) {
            // Réponse individuelle
            $response['data'] = $data;
        }
    
        // Retourne une réponse JSON
        return new JsonResponse($response, $status);
    }

    /**
     * Réponse pour les cas de succès.
     *
     * @param mixed $data           Les données à inclure.
     * @param string|null $message  Un message facultatif.
     * @param int $status           Le code HTTP, par défaut 200 (OK).
     *
     * @return JsonResponse
     */
    public function success(mixed $data = null, string $messageKey = 'success.default', array $messageParams = [], int $status = JsonResponse::HTTP_OK, string $entityKey = null): JsonResponse
    {
        // Si une entité est spécifiée, traduire son nom et l'ajouter aux paramètres
        if ($entityKey) {
            $messageParams['%entity%'] = $this->translator->trans("entity_name.$entityKey");
        }

        return $this->create(data: $data, success: true, messageKey: $messageKey, messageParams: $messageParams, status: $status);
    }

    /**
     * Réponse pour les cas d'échec.
     *
     * @param array $errors         Liste des erreurs (exemple : validations ou messages d'erreur).
     * @param string|null $message  Un message facultatif.
     * @param int $status           Le code HTTP, par défaut 400 (Bad Request).
     *
     * @return JsonResponse
     */
    public function error(array $errors = [], string $messageKey = 'error.default', array $messageParams = [], int $status = JsonResponse::HTTP_BAD_REQUEST, string $entityKey = null): JsonResponse
    {
        // Si une entité est spécifiée, traduire son nom et l'ajouter aux paramètres
        if ($entityKey) {
            $messageParams['%entity%'] = $this->translator->trans("entity_name.$entityKey");
        }

        return $this->create(data: null, success: false, messageKey: $messageKey, messageParams: $messageParams, status: $status, errors: $errors);
    }
}

<?php

declare(strict_types=1);

namespace App\Controller;

use App\Utils\ApiResponseUtils;
use App\Utils\SerializationUtils;
use App\Utils\DeserializationUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Classe abstraite servant de base pour les contrôleurs API.
 *
 * Cette classe fournit des fonctionnalités communes pour :
 * - La gestion des requêtes paginées avec filtres.
 * - La standardisation des réponses JSON.
 * - La sérialisation et désérialisation des entités.
 * 
 * Les classes enfants doivent définir :
 * - `getSerializationGroup()` : Le groupe de sérialisation de l'entité.
 * - `getEntityName()` : Le nom de l'entité concernée.
 */
abstract class AbstractApiController extends AbstractController
{
    public function __construct(
        protected ApiResponseUtils $apiResponseUtils,
        protected SerializationUtils $serializationUtils,
        protected DeserializationUtils $deserializationUtils
    ) {}

    /**
     * Gère les requêtes paginées avec filtres et sérialisation.
     *
     * @param Request                $request         La requête HTTP contenant les paramètres de pagination et de filtrage.
     * @param callable               $serviceMethod   Fonction retournant les résultats paginés sous forme de tableau.
     * @param string                 $routeName       Nom de la route pour générer les liens de pagination.
     * @param UrlGeneratorInterface  $urlGenerator    Générateur d'URL pour les liens de pagination.
     * @param string[]               $additionalGroups Groupes de sérialisation supplémentaires (optionnels).
     *
     * @return JsonResponse Réponse JSON structurée contenant les résultats paginés.
     */
    protected function handlePaginatedRequest(Request $request, callable $serviceMethod, string $routeName, UrlGeneratorInterface $urlGenerator, array $additionalGroups  = []): JsonResponse
    {
        $queryParams = $request->query->all();

        $page = (int) ($queryParams['page'] ?? 1);
        $limit = (int) ($queryParams['limit'] ?? 10);
        $filters = $this->extractAndValidate($queryParams, $this->getAllowedFilterKeys());

        $result = $serviceMethod($page, $limit, $filters);

        $pagination = $result['pagination'];
        $items = $result['items']; // Dynamique selon l'entité
        $totalItemsFound = $result['totalItemsFound'];

        // Combine les groupes par défaut et les groupes supplémentaire
        $serializerGroups = array_merge([$this->getSerializationGroup()], $additionalGroups);

        $serializedItems = $this->serializationUtils->serialize($items, $serializerGroups);

        $data = [
            'page' => $pagination->getPage(),
            'limit' => $pagination->getLimit(),
            'total_items' => $pagination->getTotalItems(),
            'total_pages' => $pagination->getTotalPages(),
            'total_items_found' => $totalItemsFound,
            'items' => $serializedItems,
            'links' => $pagination->generateLinks($routeName, $urlGenerator, $queryParams),
        ];

        return $this->apiResponseUtils->success(data: $data, messageKey: 'entity.list_retrieved', entityKey: $this->getEntityName());
    }

    /**
     * Retourne la liste des filtres autorisés pour la ressource.
     */
    abstract protected function getAllowedFilterKeys(): array;

    /**
     * Extrait et valide les filtres d'une requête HTTP.
     *
     * @param array $queryParams Les paramètres de requête (généralement $request->query->all()).
     * @param array $allowedKeys La liste blanche des clés autorisées.
     *
     * @return array Filtres nettoyés (sans null/vides et avec uniquement les clés autorisées).
     *
     * @throws BadRequestHttpException Si un paramètre non autorisé est détecté.
     */
    protected function extractAndValidate(array $queryParams, array $allowedKeys): array
    {
        // Cherche les clés interdites
        $unexpectedKeys = array_diff(array_keys($queryParams), $allowedKeys);

        if (!empty($unexpectedKeys)) {
            throw new BadRequestHttpException(sprintf(
                'Les paramètres suivants ne sont pas autorisés : %s',
                implode(', ', $unexpectedKeys)
            ));
        }

        // Nettoie les filtres : supprime les champs vides ou nuls
        return array_filter(
            $queryParams,
            fn($v) => $v !== null && !(is_string($v) && trim($v) === '')
        );
    }

    /**
     * Retourne le groupe de sérialisation de l'entité.
     *
     * @return string Nom du groupe de sérialisation.
     */
    abstract protected function getSerializationGroup(): string;

    /**
     * Retourne le nom de l'entité associée.
     *
     * @return string Nom de l'entité (ex. 'user', 'article').
     */
    abstract protected function getEntityName(): string;
}

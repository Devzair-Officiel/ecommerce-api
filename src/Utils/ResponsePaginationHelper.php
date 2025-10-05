<?php

declare(strict_types=1);

namespace App\Utils;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;


/**
 * Helper spécialisé dans la gestion des réponses paginées.
 * 
 * Responsabilité unique : construire les structures de pagination
 * pour les réponses API en utilisant PaginationUtils.
 */
final class ResponsePaginationHelper
{
    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator
    ) {}

    /**
     * Vérifie si les données sont paginées.
     */
    public function isPaginated(mixed $data): bool
    {
        return is_array($data) && (
            $this->isPaginatedWithCriteria($data) ||
            $this->isPaginatedComplete($data)
        );
    }

    /**
     * Construit la structure de réponse paginée.
     */
    public function buildPaginatedResponse(array $data, ?string $routeName, array $routeParams = []): array
    {
        if ($this->isPaginatedWithCriteria($data)) {
            return $this->buildFromCriteria($data, $routeName, $routeParams);
        }

        if ($this->isPaginatedComplete($data)) {
            return $this->extractExistingPagination($data, $routeName, $routeParams);
        }

        return [];
    }

    /**
     * Format : ['items' => [], 'total_items' => int, 'criteria' => object, 'filters' => []]
     */
    private function isPaginatedWithCriteria(array $data): bool
    {
        return isset($data['items'], $data['total_items'], $data['criteria']);
    }

    /**
     * Format : ['items' => [], 'page' => int, 'total_items' => int]
     */
    private function isPaginatedComplete(array $data): bool
    {
        return isset($data['items'], $data['page'], $data['total_items']);
    }

    /**
     * Construit pagination à partir des critères de recherche.
     */
    private function buildFromCriteria(array $data, ?string $routeName, array $routeParams): array
    {
        $criteria = $data['criteria'];
        $filters = $data['filters'] ?? [];

        $currentPage = (int) ceil($criteria->offset / $criteria->limit) + 1;
        $totalPages = (int) ceil($data['total_items'] / $criteria->limit);

        $response = [
            'page' => $currentPage,
            'limit' => $criteria->limit,
            'total_items' => $data['total_items'],
            'total_pages' => $totalPages,
            'total_items_found' => count($data['items']),
            'data' => $data['items'],
        ];

        if ($routeName) {
            // Instanciation de PaginationUtils localement
            $pagination = new PaginationUtils($currentPage, $criteria->limit, $data['total_items']);
            $allParams = array_merge($routeParams, $filters);
            $response['links'] = $pagination->generateLinks($routeName, $this->urlGenerator, $allParams);
        }

        return $response;
    }

    /**
     * Extrait et complète une pagination déjà formatée avec génération des liens HATEOAS.
     */
    private function extractExistingPagination(array $data, ?string $routeName, array $routeParams): array
    {
        $page = $data['page'];
        $limit = $data['limit'] ?? 20;
        $totalItems = $data['total_items'];
        $totalPages = $data['total_pages'] ?? (int) ceil($totalItems / $limit);

        $response = [
            'page' => $page,
            'limit' => $limit,
            'total_items' => $totalItems,
            'total_pages' => $totalPages,
            'total_items_found' => $data['total_items_found'] ?? count($data['items']),
            'data' => $data['items'],
        ];

        // Génération des liens HATEOAS si route fournie
        if ($routeName && $totalItems > 0) {
            $pagination = new PaginationUtils($page, $limit, $totalItems);
            $response['links'] = $pagination->generateLinks($routeName, $this->urlGenerator, $routeParams);
        } else {
            $response['links'] = null;
        }

        return $response;
    }
}
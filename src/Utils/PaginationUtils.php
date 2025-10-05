<?php

declare(strict_types=1);

namespace App\Utils;

use App\Exception\PaginationException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Utilitaire pour gérer la pagination dans l'API.
 * 
 * Responsabilités :
 * - Validation des paramètres de pagination
 * - Calculs des offsets et pages totales
 * - Génération des liens HATEOAS pour navigation
 */
class PaginationUtils
{
    private int $totalPages;
    private const MAX_LIMIT = 100;
    private const DEFAULT_LIMIT = 20;

    /**
     * @param int $page Numéro de la page demandée
     * @param int $limit Nombre d'éléments par page
     * @param int $totalItems Nombre total d'éléments paginés
     *
     * @throws PaginationException Si les paramètres sont invalides
     */
    public function __construct(
        private int $page,
        private int $limit,
        private int $totalItems
    ) {
        $this->validateAndNormalize();
        $this->totalPages = $this->totalItems > 0 ? (int) ceil($this->totalItems / $this->limit) : 0;
    }

    /**
     * Factory method pour créer une instance avec paramètres sécurisés.
     */
    public static function createSafe(?int $page, ?int $limit, int $totalItems): self
    {
        return new self(
            max(1, $page ?? 1),
            min(self::MAX_LIMIT, max(1, $limit ?? self::DEFAULT_LIMIT)),
            max(0, $totalItems)
        );
    }

    /** Calcule l'offset pour la requête SQL */
    public function getOffset(): int
    {
        return ($this->page - 1) * $this->limit;
    }

    /** Retourne le nombre total de pages */
    public function getTotalPages(): int
    {
        return $this->totalPages;
    }

    /** Retourne la page actuelle */
    public function getPage(): int
    {
        return $this->page;
    }

    /** Retourne la limite d'éléments par page */
    public function getLimit(): int
    {
        return $this->limit;
    }

    /** Retourne le nombre total d'éléments */
    public function getTotalItems(): int
    {
        return $this->totalItems;
    }

    /** Vérifie si il y a une page précédente */
    public function hasPrevious(): bool
    {
        return $this->page > 1;
    }

    /** Vérifie si il y a une page suivante */
    public function hasNext(): bool
    {
        return $this->page < $this->totalPages;
    }

    /** Retourne le numéro de la première page */
    public function getFirstPage(): int
    {
        return 1;
    }

    /** Retourne le numéro de la dernière page */
    public function getLastPage(): int
    {
        return max(1, $this->totalPages);
    }

    /** Retourne les informations de pagination sous forme de tableau */
    public function toArray(): array
    {
        return [
            'current_page' => $this->page,
            'per_page' => $this->limit,
            'total_items' => $this->totalItems,
            'total_pages' => $this->totalPages,
            'has_previous' => $this->hasPrevious(),
            'has_next' => $this->hasNext(),
            'from' => $this->getOffset() + 1,
            'to' => min($this->getOffset() + $this->limit, $this->totalItems)
        ];
    }

    /**
     * Génère les liens HATEOAS pour la pagination.
     *
     * @param string $routeName Nom de la route pour la pagination
     * @param UrlGeneratorInterface $urlGenerator Service Symfony pour générer des URLs
     * @param array<string, mixed> $queryParams Paramètres supplémentaires de la requête
     *
     * @return array<string, string|null> Tableau contenant les liens de pagination
     */
    public function generateLinks(string $routeName, UrlGeneratorInterface $urlGenerator, array $queryParams = []): array
    {
        $baseParams = ['limit' => $this->limit];
        $params = array_merge($baseParams, $queryParams);

        $links = [
            'self' => $urlGenerator->generate($routeName, array_merge($params, ['page' => $this->page])),
            'first' => $urlGenerator->generate($routeName, array_merge($params, ['page' => 1])),
            'last' => $urlGenerator->generate($routeName, array_merge($params, ['page' => $this->getLastPage()]))
        ];

        // Liens conditionnels
        if ($this->hasPrevious()) {
            $links['prev'] = $urlGenerator->generate($routeName, array_merge($params, ['page' => $this->page - 1]));
        }

        if ($this->hasNext()) {
            $links['next'] = $urlGenerator->generate($routeName, array_merge($params, ['page' => $this->page + 1]));
        }

        return $links;
    }

    /**
     * Vérifie si la page demandée existe.
     *
     * @throws PaginationException Si la page demandée dépasse le nombre total de pages
     */
    public function validatePage(): void
    {
        if ($this->page > $this->totalPages && $this->totalItems > 0) {
            throw new PaginationException(
                messageKey: 'pagination.page_out_of_range',
                statusCode: 404,
                translationParameters: [
                    '%page%' => $this->page,
                    '%totalPages%' => $this->totalPages
                ]
            );
        }
    }

    /**
     * Valide et normalise les paramètres de pagination.
     */
    private function validateAndNormalize(): void
    {
        if ($this->page < 1) {
            throw new PaginationException(
                messageKey: 'pagination.invalid_page',
                statusCode: 400,
                translationParameters: ['%value%' => $this->page]
            );
        }

        if ($this->limit < 1) {
            throw new PaginationException(
                messageKey: 'pagination.invalid_limit',
                statusCode: 400,
                translationParameters: ['%value%' => $this->limit]
            );
        }

        if ($this->limit > self::MAX_LIMIT) {
            throw new PaginationException(
                messageKey: 'pagination.limit_exceeded',
                statusCode: 400,
                translationParameters: ['%limit%' => self::MAX_LIMIT]
            );
        }

        if ($this->totalItems < 0) {
            throw new PaginationException(
                messageKey: 'pagination.invalid_total',
                statusCode: 400
            );
        }
    }
}

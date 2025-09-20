<?php

declare(strict_types=1);

namespace App\Utils;

use App\Exception\PaginationException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Utilitaire pour gérer la pagination dans l'API.
 * 
 * - Calcule les offsets et le nombre total de pages.
 * - Valide les paramètres de pagination (`page`, `limit`).
 * - Génère des liens HATEOAS pour faciliter la navigation.
 */
class PaginationUtils
{
    private int $totalPages;

    private const MAX_LIMIT = 100;

    /**
     * @param int $page Numéro de la page demandée.
     * @param int $limit Nombre d'éléments par page.
     * @param int $totalItems Nombre total d'éléments paginés.
     *
     * @throws PaginationException Si les paramètres sont invalides.
     */
    public function __construct(
        private int $page,
        private int $limit,
        private int $totalItems
    ) {
        $this->validateParameters();
        $this->totalPages = (int) ceil($this->totalItems / $this->limit);
    }

    public function getOffset(): int
    {
        return ($this->page - 1) * $this->limit;
    }

    public function getTotalPages(): int
    {
        return $this->totalPages;
    }

    public function getPage(): int
    {
        return $this->page;
    }

    public function getLimit(): int
    {
        return $this->limit;
    }

    public function getTotalItems(): int
    {
        return $this->totalItems;
    }

    /**
     * Génère les liens HATEOAS pour la pagination.
     *
     * @param string $routeName Nom de la route pour la pagination.
     * @param UrlGeneratorInterface $urlGenerator Service Symfony pour générer des URLs.
     * @param array<string, mixed> $queryParams Paramètres supplémentaires de la requête.
     *
     * @return array<string, string|null> Tableau contenant les liens de pagination.
     */
    public function generateLinks(string $routeName, UrlGeneratorInterface $urlGenerator, array $queryParams = []): array
    {
        $baseParams = ['page' => $this->page, 'limit' => $this->limit];
        $params = array_merge($baseParams, $queryParams);

        return [
            'self' => $urlGenerator->generate($routeName, $params),
            'first' => $urlGenerator->generate($routeName, array_merge($params, ['page' => 1])),
            'last' => $urlGenerator->generate($routeName, array_merge($params, ['page' => $this->totalPages])),
            'prev' => $this->page > 1 ? $urlGenerator->generate($routeName, array_merge($params, ['page' => $this->page - 1])) : null,
            'next' => $this->page < $this->totalPages ? $urlGenerator->generate($routeName, array_merge($params, ['page' => $this->page + 1])) : null,
        ];
    }

    /**
     * Valide les paramètres de pagination (`page`, `limit`).
     *
     * @throws PaginationException Si un paramètre est invalide.
     */
    private function validateParameters(): void
    {
        if ($this->page < 1) {
            throw new PaginationException(
                messageKey: 'pagination.invalid_page',
                statusCode: 400,
                translationParameters: ['%field%' => 'page', '%value%' => $this->page]
            );
        }

        if ($this->limit < 1) {
            throw new PaginationException(
                messageKey: 'pagination.invalid_limit',
                statusCode: 400,
                translationParameters: ['%field%' => 'limit', '%value%' => $this->limit]
            );
        }

        if ($this->limit > self::MAX_LIMIT) {
            throw new PaginationException(
                messageKey: 'pagination.limit_exceeded',
                statusCode: 400,
                translationParameters: ['%limit%' => self::MAX_LIMIT]
            );
        }
    }

    /**
     * Vérifie si la page demandée existe.
     *
     * @throws PaginationException Si la page demandée dépasse le nombre total de pages.
     */
    public function validatePage(): void
    {
        if ($this->page > $this->totalPages && $this->totalItems !== 0) {
            throw new PaginationException(
                messageKey: 'pagination.invalid_page',
                statusCode: 404,
                translationParameters: [
                    '%page%' => $this->page,
                    '%totalPages%' => $this->totalPages
                ]
            );
        }
    }
}

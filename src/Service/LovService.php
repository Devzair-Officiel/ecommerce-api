<?php

declare(strict_types=1);

namespace App\Service;

use App\Utils\PaginationUtils;
use App\Utils\JsonValidationUtils;
use Doctrine\ORM\EntityManagerInterface;

class LovService
{
    public function __construct(private EntityManagerInterface $entityManager, private JsonValidationUtils $jsonValidationUtils) {}

    /**
     * Récupérer toutes les LOV avec pagination et filtres.
     */
    public function getLovs(string $entityClass, int $page, int $limit, array $filters = []): array
    {
        
        $repository = $this->entityManager->getRepository($entityClass);
        $totalItems = $repository->count([]);
        $pagination = new PaginationUtils($page, $limit, $totalItems);
        $pagination->validatePage();

        $items = $repository->findWithPaginationAndFilters($pagination->getOffset(), $pagination->getLimit(), $filters);

        return [
            'pagination' => new PaginationUtils($page, $limit, $items['totalItemsFound']),
            'items' => $items['items'],
            'totalItemsFound' => $items['totalItemsFound'],
        ];
    }

    /**
     * Récupérer une entité LOV par ID.
     */
    public function getOne(string $entityClass, int $id): ?object
    {
        return $this->entityManager->getRepository($entityClass)->find($id);
    }

    /**
     * Créer une nouvelle LOV.
     */
    public function create(object $entity, array $jsonData): void
    {
        // Valider les clés JSON
        $invalidKeys = $this->jsonValidationUtils->validateKeys($jsonData, $entity::class);

        if (!empty($invalidKeys)) {
            // Lever une exception si des clés JSON sont invalides
            throw new \InvalidArgumentException(
                implode(', ', $invalidKeys)
            );
        }

        $this->entityManager->persist($entity);
        $this->entityManager->flush();
    }

    /**
     * Mettre à jour une entité LOV existante.
     */
    public function update(object $entity, $jsonData): object
    {
        // Valider les clés JSON
        $invalidKeys = $this->jsonValidationUtils->validateKeys($jsonData, $entity::class);

        if (!empty($invalidKeys)) {
            // Lever une exception si des clés JSON sont invalides
            throw new \InvalidArgumentException(
                implode(', ', $invalidKeys)
            );
        }

        // L'entité a déjà été désérialisée et validée, on persiste simplement les modifications
        $this->entityManager->persist($entity);
        $this->entityManager->flush();

        return $entity;
    }

    /**
     * Supprimer une LOV existante.
     */
    public function delete(object $entity): void
    {
        $this->entityManager->remove($entity);
        $this->entityManager->flush();
    }
}

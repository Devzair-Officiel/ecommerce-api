<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\ORM\EntityManagerInterface;
use App\Exception\EntityNotFoundException;
use App\Service\Processing\EntityProcessor;
use App\Service\Processing\RelationManager;

/**
 * Service abstrait simplifié avec les responsabilités essentielles uniquement.
 * 
 * Responsabilités :
 * - Recherche d'entités avec gestion d'erreurs
 * - Persistance et suppression
 * - Accès aux services spécialisés via injection
 */
abstract class AbstractService
{
    public function __construct(
        protected EntityManagerInterface $em,
        protected EntityProcessor $entityProcessor,
        protected RelationManager $relationManager,
    ) {}

    /**
     * Trouve une entité par critères avec exception typée si non trouvée.
     */
    protected function findEntityByCriteria(string $entityClass, array $criteria): object
    {
        $entity = $this->em->getRepository($entityClass)->findOneBy($criteria);

        if (!$entity) {
            throw new EntityNotFoundException(
                $this->getEntityName($entityClass),
                $criteria
            );
        }

        return $entity;
    }

    /**
     * Sauvegarde une entité avec gestion d'erreur.
     */
    protected function saveEntity(object $entity): void
    {
        try {
            $this->em->persist($entity);
            $this->em->flush();
        } catch (\Exception $e) {
            $this->em->rollback();
            throw new \RuntimeException('Failed to save entity: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Supprime une entité avec gestion d'erreur.
     */
    protected function removeEntity(object $entity): void
    {
        try {
            $this->em->remove($entity);
            $this->em->flush();
        } catch (\Exception $e) {
            $this->em->rollback();
            throw new \RuntimeException('Failed to remove entity: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Exécute une opération dans une transaction.
     */
    protected function executeInTransaction(callable $operation): mixed
    {
        $this->em->beginTransaction();

        try {
            $result = $operation();
            $this->em->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->em->rollback();
            throw $e;
        }
    }

    // === DÉLÉGATION AUX SERVICES SPÉCIALISÉS ===

    protected function processEntityFromJson(string $json, string $entityClass, ?object $existing = null): object
    {
        return $this->entityProcessor->processFromJson($json, $entityClass, $existing);
    }

    protected function processRelations(object $entity, array $relationConfig, array $data): void
    {
        $this->relationManager->processEntityRelations($entity, $relationConfig, $data);
    }

    protected function updateRelations(object $entity, array $relationConfig, array $data): void
    {
        $this->relationManager->updateEntityRelations($entity, $relationConfig, $data);
    }

    // === UTILITAIRES PRIVÉS ===

    private function getEntityName(string $entityClass): string
    {
        return strtolower(basename(str_replace('\\', '/', $entityClass)));
    }
}

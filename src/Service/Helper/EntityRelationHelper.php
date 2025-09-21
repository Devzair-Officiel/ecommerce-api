<?php

declare(strict_types=1);

namespace App\Service\Helper;

/**
 * Helper pour simplifier la configuration des relations d'entités.
 * 
 * Extrait la logique de configuration depuis AbstractService
 * pour la rendre réutilisable et testable.
 */
class EntityRelationHelper
{
    /**
     * Crée une configuration de relation standard.
     */
    public static function createConfig(
        string $targetEntity,
        string $identifierKey = 'id',
        bool $allowCreation = false
    ): array {
        return [
            'targetEntity' => $targetEntity,
            'identifierKey' => $identifierKey,
            'allowCreation' => $allowCreation
        ];
    }

    /**
     * Crée plusieurs configurations en une fois.
     * 
     * @param array $configs Format: ['field' => ['entity' => ClassName, 'key' => 'id', 'create' => false]]
     */
    public static function createMultipleConfigs(array $configs): array
    {
        $result = [];

        foreach ($configs as $field => $config) {
            $result[$field] = self::createConfig(
                $config['entity'],
                $config['key'] ?? 'id',
                $config['create'] ?? false
            );
        }

        return $result;
    }

    /**
     * Configuration rapide pour des relations standard (par ID).
     */
    public static function createStandardConfigs(array $fieldToEntityMap): array
    {
        $configs = [];

        foreach ($fieldToEntityMap as $field => $entityClass) {
            $configs[$field] = self::createConfig($entityClass);
        }

        return $configs;
    }
}

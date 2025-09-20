<?php 

declare(strict_types=1);

namespace App\Utils;

use ReflectionClass;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Utilitaire pour la validation et l'hydratation des objets à partir de JSON.
 * 
 * - Vérifie si les clés du JSON correspondent aux propriétés d'une classe (DTO ou entité).
 * - Génère des messages d'erreur pour les clés JSON non reconnues.
 * - Permet d'hydrater un objet en validant les données avant.
 */
class JsonValidationUtils
{
    private TranslatorInterface $translator;

    public function __construct(TranslatorInterface $translator)
    {
        $this->translator = $translator;
    }

    /**
     * Valide les clés JSON par rapport aux propriétés d'une classe.
     *
     * @param array $jsonData            Les données JSON à valider.
     * @param string $className          Nom complet de la classe (DTO ou entité).
     * @param array $excludedProperties  Propriétés à exclure de la validation.
     * 
     * @return array                     Retourne les clés invalides.
     */
    public function validateKeys(array $jsonData, string $className, array $excludedProperties = []): array
    {
        // Utiliser la réflexion pour obtenir les propriétés de la classe
        $reflectionClass = new ReflectionClass($className);
        $classProperties = array_map(
            fn($property) => $property->getName(),
            $reflectionClass->getProperties()
        );

        // Exclure certaines propriétés (ex: "id", "createdAt", "updatedAt")
        $classProperties = array_diff($classProperties, $excludedProperties);

        // Identifier les clés invalides dans le JSON
        $invalidKeys = array_diff(array_keys($jsonData), $classProperties);

        // Retourner les clés invalides traduites
        return array_map(
            fn($key) => $this->translator->trans('validation.invalid_key', ['%key%' => $key]),
            $invalidKeys
        );
    }

    /**
     * Valide et hydrate une instance de classe à partir des données JSON.
     *
     * @param array $jsonData            Les données JSON à utiliser.
     * @param string $className          Nom complet de la classe (DTO ou entité).
     * @param array $excludedProperties  Propriétés à exclure de la validation.
     * 
     * @return object                    Instance de la classe hydratée.
     * 
     * @throws \InvalidArgumentException Si des clés JSON invalides sont détectées.
     */
    public function validateAndHydrate(array $jsonData, string $className, array $excludedProperties = []): object
    {
        // Validation des clés JSON
        $invalidKeys = $this->validateKeys($jsonData, $className, $excludedProperties);

        if (!empty($invalidKeys)) {
            throw new \InvalidArgumentException(
                implode(', ', $invalidKeys)
            );
        }

        // Création de l'instance de la classe
        $instance = new $className();

        // Hydratation des propriétés
        $reflectionClass = new ReflectionClass($className);
        foreach ($jsonData as $key => $value) {
            if ($reflectionClass->hasProperty($key)) {
                $property = $reflectionClass->getProperty($key);
                $property->setAccessible(true);
                $property->setValue($instance, $value);
            }
        }

        return $instance;
    }
}
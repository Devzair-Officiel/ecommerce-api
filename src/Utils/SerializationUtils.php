<?php

namespace App\Utils;

use Symfony\Component\Serializer\SerializerInterface;

/**
 * Utilitaire pour la sérialisation des données en JSON avec gestion des groupes.
 * 
 * - Convertit des objets ou tableaux en JSON en appliquant les groupes de sérialisation.
 * - Retourne les données sous forme de tableau PHP.
 */
class SerializationUtils
{
    public function __construct(private SerializerInterface $serializer) {}

    /**
     * Sérialise des données en JSON en utilisant les groupes spécifiés.
     *
     * @param mixed $data   Données à sérialiser.
     * @param array $groups Groupes de sérialisation à appliquer.
     *
     * @return array        Données sérialisées sous forme de tableau.
     */
    public function serialize(mixed $data, array $groups): array
    {
        $json = $this->serializer->serialize($data, 'json', ['groups' => $groups]);
        return json_decode($json, true);
    }
}

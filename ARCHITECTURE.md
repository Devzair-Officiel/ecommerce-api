********************* Détails techniques sur la structure de l'application *************************

    -   Composants principaux de l'application.
    -   Description de la logique métier et des workflows.

///////////////////////////////////////////////////////////////////////////
        /////////////////////////////////////////////////////


////////////////////////////////    Composants principaux de l'application   //////////////////////////////////////

1. Contrôleurs :
    - Servent de point d'entrée pour les requêtes HTTP.
    - S'appuient sur des services pour exécuter la logique métier et utilisent des utilitaires pour préparer et standardiser les réponses.

2. Services :
    - Centralisent la logique métier.
    - Appellent les repositories pour manipuler les données et appliquent les règles métier spécifiques.

3. Repositories :
    - Fournissent un accès abstrait à la base de données.
    - Encapsulent les requêtes complexes ou spécifiques.

4. Utilitaires :
    - ApiResponseUtils : Standardise les réponses API.
    - DeserializationUtils : Désérialise et valide les données entrantes.
    - JsonValidationUtils : Verifie si des clés des champs de l'entité ou du DTO n'existe pas.
    - PaginationUtils : Simplifie la gestion de la pagination
    - SerializationUtils : Simplifie la sérialisation en JSON.
    - ValidationUtils : Valide les contraintes et formate les erreurs.

5. DTOs :
    - Transportent les données entre les couches, garantissant la validation et la cohérence des données entrées. (donnéees sensible)

6. Exceptions personnalisées :
    - Gèrent les erreurs spécifiques (comme les erreurs de validation) de manière uniforme.


Exemple : Création d’un utilisateur via l’endpoint /users.

1. Requête HTTP :
    - L'utilisateur envoie une requête POST avec un corps JSON contenant les données utilisateur.

2. Contrôleur :
    - Le contrôleur (UserController) utilise DeserializationUtils pour désérialiser et valider les données JSON en un UserRegistrationDTO.
    - Il transmet ce DTO à UserService pour exécuter la logique métier.

3. Service :
    - UserService utilise UserRepository pour vérifier l'existence d'un utilisateur (par ex. doublon d'email) et pour persister le nouvel utilisateur dans la base de données.

4. Repository :
    - UserRepository exécute les opérations nécessaires sur la base de données via Doctrine.

5. Réponse :
    - SerializationUtils est utilisé pour transformer l'objet utilisateur en JSON.
    - ApiResponseUtils standardise la réponse API avant de la renvoyer au client.

                    //////////////////////////////////////////////////
                            //////////////////////////////


////////////////////////////    Description de la logique métier et des workflows   ///////////////////////////////////////



                    //////////////////////////////////////////////////
                            //////////////////////////////


********************* Meilleures pratiques pour collaborer et maintenir le projet *************************

    -   Normes de codage (exemple : PSR-12).
    -   Organisation des fichiers et dossiers.
    -   Instructions pour ajouter de nouvelles fonctionnalités ou corriger des bugs.
    -   Rappel des outils utilisés (PHPStan, PHPUnit, etc.).
    -   Exemple d'un commit clair et structuré.

///////////////////////////////////////////////////////////////////////////
        /////////////////////////////////////////////////////

////////////////////////////////    Normes de codage   //////////////////////////////////////

Résumé des règles importantes de PSR-12 :

1. Fichiers PHP :
    Tous les fichiers PHP commencent par <?php et doivent déclarer declare(strict_types=1);.
    Aucun code ou caractère (y compris les espaces) ne doit précéder cette déclaration.

2. Indentation :
    Utilisez 4 espaces pour l'indentation.

3. Longueur des lignes :
    Limitez la longueur des lignes à 120 caractères pour une meilleure lisibilité.

4. Nommage des classes :
    Utilisez PascalCase pour les noms de classes (exemple : UserService).

5. Nommage des méthodes et fonctions :
    Utilisez camelCase pour les noms de méthodes et fonctions (exemple : getUserById).

6. Espaces :
    Ajoutez une ligne vide après la déclaration de namespace et après l'import des use.
    Placez un espace après chaque virgule dans les listes d'arguments ou de paramètres.

7. Accolades :
    Ouvrez les accolades sur la même ligne que la déclaration

8. Types et retour de fonctions :

    Déclarez explicitement les types des arguments et des valeurs de retour (exemple : string, int, array, etc.).
    Utilisez void si la fonction ne retourne rien.

                    /////////////////////////////////////////////////////////
                            ///////////////////////////////////

////////////////////////////////    Organisation des fichiers et dossiers   //////////////////////////////////////

1. Respectez les conventions Symfony :

    - src/ : Code source de l'application.
    - tests/ : Tests unitaires et fonctionnels.
    - public/ : Point d'entrée HTTP (fichiers comme index.php).

2. Classement par domaines :

    - Organisez vos fichiers en fonction des modules métiers.
    - Exemple pour une entité User :
        . Entités : src/Entity/User.php.
        . Services : src/Service/UserService.php.
        . Repositories : src/Repository/UserRepository.php.
        . Controllers : src/Controller/UserController.php.

                    /////////////////////////////////////////////////////////
                            ///////////////////////////////////

////////////////////////////////    Exemple d'un commit clair et structuré   //////////////////////////////////////

Structure recommandée :

- type : Le type de modification (voir liste des types ci-dessous).
- scope : La partie affectée du code ou du projet (ex. user, article, CQ, tests, etc.).
- message résumé : Un résumé clair (max 50-72 caractères) expliquant la modification.
- description optionnelle : Une description plus détaillée (facultative) si nécessaire.

Liste des types courants :

    feat : Ajout d'une nouvelle fonctionnalité.
    fix : Correction d'un bug.
    refactor : Modification du code pour le rendre plus propre ou performant (sans changer le comportement).
    perf : Optimisation des performances.
    docs : Mise à jour de la documentation.
    test : Ajout ou modification de tests.
    chore : Modifications diverses (dépendances, configurations, etc.).
    style : Modifications liées au style de code (indentation, formatage, etc.).
    build : Changements affectant la configuration de build ou les dépendances.

Voici un exemple d'un commit bien structuré :

feat_user: ajout de la gestion des rôles multiples pour les utilisateurs

- Ajout de la possibilité d'assigner plusieurs rôles à un utilisateur.
- Mise à jour du DTO `UserRegistrationDTO` pour accepter un tableau de rôles.
- Modification de `UserService::registerUser` pour gérer les rôles multiples.
- Ajout de tests unitaires dans `UserServiceTest`.

BREAKING CHANGE: La structure de la base de données a changé, avec une nouvelle table pivot `user_roles`.

Détails des parties du commit :

    Ligne principale :
        feat_user: ajout de la gestion des rôles multiples pour les utilisateurs
            Type : feat (ajout d'une nouvelle fonctionnalité).
            Scope : user (module affecté).
            Message résumé : Explique en une phrase la fonctionnalité ajoutée.

    Description supplémentaire (facultative) :
        Liste claire et concise des modifications apportées :
            Ajout de la nouvelle fonctionnalité.
            Mise à jour des DTO et méthodes impactées.
            Tests ajoutés pour couvrir les changements.

    Note importante (si applicable) :
        BREAKING CHANGE : Indique qu'il y a une rupture de compatibilité, comme une modification de la base de données ou des API.
*********************   Vue d'ensemble du projet *************************

    -   Description générale de l'application.
    -   Objectifs principaux.
    -   Instructions d'installation et de configuration.
    -   Commandes principales pour démarrer le projet (exemple : migrations, fixtures, etc.).
    -   Lien vers d'autres documentations.

///////////////////////////////////////////////////////////////////////////
        /////////////////////////////////////////////////////

////////////////////////////////    Commandes principales pour démarrer le projet   //////////////////////////////////////

Récuperer le depot sur GitLab, la branche "dev".

- Installer les dependances         ->  composer install
- Configurer le fichier .env
- Créer la base de données          ->  php bin/console doctrine:database:create
- Exécuter les migrations           ->  php bin/console doctrine:migrations:migrate
- Exécuter les migrations           ->  php bin/console doctrine:schema:update --force
- Charger les données (fixtures)    ->  php bin/console doctrine:fixtures:load
- Recharger les fixtures            ->  php bin/console doctrine:fixtures:load --purge-with-truncate
⚠️ Attention : cette commande supprime les données existantes avant de recharger les nouvelles.
un user admin est généré avec le dernier id ; email: admin@gmail.com , mdp: azerty
- Exécuter les tests unitaires (facultatif) ->  php bin/phpunit

Configuration de l'authentification JWT:
- Les clés privées et publiques utilisées pour signer et vérifier les tokens JWT sont stockées dans le dossier suivant : config/jwt/
    . Clé privée : private.pem
    . Clé publique : public.pem
Note : Si les clés doivent être régénérées (par exemple, pour des raisons de sécurité), utilisez les commandes suivantes :
    -> php bin/console lexik:jwt:generate-keypair
Doc : https://github.com/lexik/LexikJWTAuthenticationBundle/blob/3.x/Resources/doc/index.rst#installation

Configuration pour permettre au frontend de consommer l'API:
- Activer et configurer CORS (Cross-Origin Resource Sharing)
    ###> nelmio/cors-bundle ###
    CORS_ALLOW_ORIGIN='^https?://(localhost|127\.0\.0\.1)(:[0-9]+)?$'
    ###< nelmio/cors-bundle ###

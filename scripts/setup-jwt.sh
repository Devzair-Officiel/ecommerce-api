#!/bin/bash
# Rendre le script exécutable : chmod +x setup-jwt.sh
# executer script : scripts/setup-jwt.sh
# Script d'installation et configuration JWT pour le projet

echo "🔐 Configuration JWT Authentication"
echo "===================================="

# 1. Installation du bundle Lexik JWT
echo ""
echo "📦 Installation de lexik/jwt-authentication-bundle..."
composer require lexik/jwt-authentication-bundle

# 2. Génération des clés JWT (paires publique/privée)
echo ""
echo "🔑 Génération des clés JWT..."
php bin/console lexik:jwt:generate-keypair

# Les clés sont créées dans config/jwt/
# - private.pem (clé privée pour signer les tokens)
# - public.pem (clé publique pour vérifier les tokens)

# 3. Définir les permissions correctes (lecture seule)
echo ""
echo "🔒 Configuration des permissions..."
chmod 600 config/jwt/private.pem
chmod 644 config/jwt/public.pem

# 4. Vérifier que les clés sont ignorées par Git
if ! grep -q "config/jwt/*.pem" .gitignore; then
    echo "config/jwt/*.pem" >> .gitignore
    echo "✅ Clés JWT ajoutées au .gitignore"
fi

# 5. Créer la migration User
echo ""
echo "📝 Création de la migration User..."
php bin/console make:migration

# 6. Exécuter la migration
echo ""
echo "⚡ Exécution de la migration..."
php bin/console doctrine:migrations:migrate -n

# 7. Charger les fixtures
echo ""
echo "🌱 Chargement des fixtures User..."
php bin/console doctrine:fixtures:load --append

echo ""
echo "✅ Configuration JWT terminée !"
echo ""
echo "📌 Informations importantes :"
echo "   - Clés JWT générées dans : config/jwt/"
echo "   - Passphrase définie dans : .env (JWT_PASSPHRASE)"
echo "   - Endpoint de login : POST /api/auth/login"
echo ""
echo "🧪 Test de connexion :"
echo '   curl -X POST http://localhost:8000/api/auth/login '\''
echo '     -H "Content-Type: application/json" \'
echo '     -d '"'"'{"email":"admin@boutique-bio.fr","password":"Admin123!"}'"'"
echo ""
echo "🔐 Le token JWT sera retourné dans la réponse."
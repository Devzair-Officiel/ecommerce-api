#!/bin/bash

# Script d'installation de l'extension PHP intl sur Ubuntu
# Adapté pour PHP 8.3+ (ajuste la version selon ton environnement)

echo "🔧 Installation de l'extension PHP intl..."

# Déterminer la version PHP installée
PHP_VERSION=$(php -r "echo PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;")
echo "📦 Version PHP détectée: $PHP_VERSION"

# Installation du package
sudo apt update
sudo apt install -y php${PHP_VERSION}-intl

# Vérification de l'installation
if php -m | grep -q intl; then
    echo "✅ Extension intl installée avec succès!"
    echo ""
    echo "📋 Informations intl:"
    php -r "echo 'ICU Version: ' . INTL_ICU_VERSION . PHP_EOL;"
else
    echo "❌ Erreur lors de l'installation"
    exit 1
fi

# Redémarrer les services si nécessaire
if systemctl is-active --quiet php${PHP_VERSION}-fpm; then
    echo "🔄 Redémarrage de PHP-FPM..."
    sudo systemctl restart php${PHP_VERSION}-fpm
fi

if systemctl is-active --quiet apache2; then
    echo "🔄 Redémarrage d'Apache..."
    sudo systemctl restart apache2
fi

if systemctl is-active --quiet nginx; then
    echo "🔄 Redémarrage de Nginx..."
    sudo systemctl restart nginx
fi

echo ""
echo "✨ Installation terminée! Tu peux maintenant utiliser la validation de locales."

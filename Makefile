# Makefile pour le projet E-commerce
# Usage : make [commande]

.PHONY: help install db-reset fixtures start stop test

# Couleurs pour l'affichage
GREEN  := \033[0;32m
YELLOW := \033[1;33m
NC     := \033[0m

help: ## Affiche l'aide
	@echo "$(GREEN)Commandes disponibles :$(NC)"
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  $(YELLOW)%-20s$(NC) %s\n", $$1, $$2}'

install: ## Installation complète du projet
	@echo "$(GREEN)📦 Installation des dépendances...$(NC)"
	composer install
	@echo "$(GREEN)🔑 Génération des clés JWT...$(NC)"
	php bin/console lexik:jwt:generate-keypair --skip-if-exists
	@echo "$(GREEN)✅ Installation terminée !$(NC)"

db-reset: ## Réinitialise la base de données (drop + create + migrate)
	@echo "$(GREEN)🗑️  Suppression de la base...$(NC)"
	php bin/console doctrine:database:drop --force --if-exists
	@echo "$(GREEN)🏗️  Création de la base...$(NC)"
	php bin/console doctrine:database:create
	@echo "$(GREEN)⚡ Exécution des migrations...$(NC)"
	php bin/console doctrine:migrations:migrate --no-interaction
	@echo "$(GREEN)✅ Base de données réinitialisée !$(NC)"

fixtures: db-reset ## Charge les fixtures (avec reset de la base)
	@echo "$(GREEN)🌱 Chargement des fixtures...$(NC)"
	php bin/console doctrine:fixtures:load --no-interaction
	@echo "$(GREEN)✅ Fixtures chargées !$(NC)"
	@echo ""
	@echo "$(YELLOW)👤 Comptes de test créés :$(NC)"
	@echo "   Super Admin : superadmin@boutique-bio.fr (SuperAdmin123!)"
	@echo "   Admin FR    : admin@boutique-bio.fr (Admin123!)"
	@echo "   Admin BE    : admin@boutique-bio.be (Admin123!)"

fixtures-append: ## Charge les fixtures sans reset (append)
	@echo "$(GREEN)🌱 Ajout de fixtures...$(NC)"
	php bin/console doctrine:fixtures:load --append --no-interaction
	@echo "$(GREEN)✅ Fixtures ajoutées !$(NC)"

db-validate: ## Valide le schéma Doctrine
	@echo "$(GREEN)🔍 Validation du schéma...$(NC)"
	php bin/console doctrine:schema:validate

start: ## Démarre le serveur Symfony
	@echo "$(GREEN)🚀 Démarrage du serveur...$(NC)"
	symfony server:start -d
	@echo "$(GREEN)✅ Serveur démarré sur http://localhost:8000$(NC)"

stop: ## Arrête le serveur Symfony
	@echo "$(YELLOW)⏹️  Arrêt du serveur...$(NC)"
	symfony server:stop
	@echo "$(GREEN)✅ Serveur arrêté$(NC)"

test: ## Lance les tests
	@echo "$(GREEN)🧪 Exécution des tests...$(NC)"
	php bin/phpunit

cache-clear: ## Vide le cache Symfony
	@echo "$(GREEN)🗑️  Vidage du cache...$(NC)"
	php bin/console cache:clear
	@echo "$(GREEN)✅ Cache vidé !$(NC)"

migration-create: ## Crée une nouvelle migration
	@echo "$(GREEN)📝 Création d'une migration...$(NC)"
	php bin/console make:migration

migration-migrate: ## Exécute les migrations en attente
	@echo "$(GREEN)⚡ Exécution des migrations...$(NC)"
	php bin/console doctrine:migrations:migrate --no-interaction

jwt-generate: ## Génère les clés JWT
	@echo "$(GREEN)🔑 Génération des clés JWT...$(NC)"
	php bin/console lexik:jwt:generate-keypair
	@echo "$(GREEN)✅ Clés JWT générées !$(NC)"

setup: install db-reset fixtures jwt-generate ## Setup complet du projet (install + db + fixtures + jwt)
	@echo ""
	@echo "$(GREEN)╔════════════════════════════════════════════════════════════╗$(NC)"
	@echo "$(GREEN)║          ✅ SETUP COMPLET TERMINÉ AVEC SUCCÈS             ║$(NC)"
	@echo "$(GREEN)╚════════════════════════════════════════════════════════════╝$(NC)"
	@echo ""
	@echo "$(YELLOW)🚀 Pour démarrer le serveur : make start$(NC)"
	@echo "$(YELLOW)📖 Pour voir l'aide          : make help$(NC)"
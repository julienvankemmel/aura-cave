.DEFAULT_GOAL := help
# .env.local (dev, non versionné) prime sur .env
ENV_FILE := $(if $(wildcard .env.local),.env.local,.env)
COMPOSE ?= docker compose --env-file $(ENV_FILE)

.PHONY: help envfile install up down seed lint test security keys

help: ## Liste les commandes
	@grep -E '^[a-z-]+:.*##' $(MAKEFILE_LIST) | awk -F':.*## ' '{printf "  %-10s %s\n", $$1, $$2}'

envfile:
	@test -f .env.local || test -f .env || { cp .env.example .env; echo ".env créé depuis .env.example : renseignez les secrets (make keys)."; }

install: ## Installe les dépendances locales (composer, uv, pnpm)
	cd backend && composer install
	cd agent && uv sync
	cd frontend && pnpm install --frozen-lockfile

up: envfile ## Build et démarre la stack
	$(COMPOSE) up -d --build --wait

down: ## Arrête la stack
	$(COMPOSE) down

seed: ## Migrations, accessoires du vin et import du jeu de données Kaggle (embeddings 384d)
	$(COMPOSE) exec backend php bin/console doctrine:migrations:migrate --no-interaction
	$(COMPOSE) exec backend php bin/console app:seed-catalog --prune
	$(COMPOSE) exec backend php bin/console app:import-wine-dataset --prune

keys: ## Génère la clé libsodium SODIUM_PRIVATE_KEY (chiffrement au repos des coordonnées)
	@php -r '$$k=sodium_crypto_box_keypair();echo "SODIUM_PRIVATE_KEY=".base64_encode(sodium_crypto_box_secretkey($$k)).PHP_EOL;'

lint: ## PHPStan (niv. 8) + php-cs-fixer + Ruff + Mypy --strict + TSC + ESLint
	cd backend && vendor/bin/phpstan analyse --memory-limit=512M && vendor/bin/php-cs-fixer fix --dry-run --diff
	cd agent && uv run ruff check . && uv run ruff format --check . && uv run mypy --strict
	cd frontend && pnpm typecheck && pnpm lint

test: ## PHPUnit (postgres requis, démarré auto) + Pytest + Vitest
	$(COMPOSE) up -d --wait postgres
	cd backend && vendor/bin/phpunit
	cd agent && uv run pytest
	cd frontend && pnpm test

security: ## Trivy (fs + config) + audit composer/uv/pnpm
	./infra/security-scan.sh

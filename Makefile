.PHONY: run serve snapshot help \
	deploy prod-check prod-cache-clear prod-migrate prod-nginx-reload \
	prod-ps prod-logs prod-shell prod-console prod-snapshot

help:
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-30s\033[0m %s\n", $$1, $$2}'

run: ## Run the project on localhost:8001
	symfony serve --port=8001

serve: run ## Alias for run

snapshot: ## Create a SQL snapshot of the current database (saved in snapshots/)
	@mkdir -p snapshots
	@DB_URL=$$(grep -h '^DATABASE_URL=' .env.local .env 2>/dev/null | head -1 | sed -E 's/^DATABASE_URL=//; s/^"//; s/"$$//; s/\?.*//'); \
	if [ -z "$$DB_URL" ]; then echo "DATABASE_URL introuvable dans .env.local/.env" >&2; exit 1; fi; \
	FILE=snapshots/snapshot_$$(date +%Y-%m-%d_%H-%M-%S).sql; \
	pg_dump "$$DB_URL" --data-only --inserts > $$FILE; \
	echo "Snapshot saved: $$FILE"

# ---------------------------------------------------------------------------
# Production (droplet DigitalOcean) — runbook complet : docs/PRODUCTION.md
#
# Ces cibles s'exécutent depuis la machine de dev OU un runner CI/CD via SSH.
# Surchargables : make deploy PROD_SSH=user@host PROD_DIR=/chemin/du/repo
# (un runner CI fournit sa propre clé SSH et passe PROD_SSH=root@134.209.226.113)
# ---------------------------------------------------------------------------
PROD_SSH     ?= droplet
PROD_DIR     ?= /root/workspace_dar/trading-tracker
PROD_URL     ?= https://trading-tracker.freeddns.org
PROD_COMPOSE  = docker compose -f compose.prod.yaml

deploy: ## Déploiement complet en prod : reset sur origin/main, rebuild app, migrations, cache:clear, healthcheck
	ssh $(PROD_SSH) 'set -e; cd $(PROD_DIR) \
		&& git fetch origin main \
		&& git reset --hard origin/main \
		&& $(PROD_COMPOSE) up -d --build app \
		&& $(PROD_COMPOSE) exec -T app php bin/console doctrine:migrations:migrate -n \
		&& $(PROD_COMPOSE) exec -T app php bin/console cache:clear'
	@$(MAKE) --no-print-directory prod-check

prod-check: ## Vérifie que la prod répond (HTTP 2xx/3xx attendu)
	@code=$$(curl -s -o /dev/null -w '%{http_code}' --max-time 30 $(PROD_URL)); \
	echo "$(PROD_URL) -> HTTP $$code"; \
	case $$code in 2*|3*) exit 0 ;; *) exit 1 ;; esac

prod-cache-clear: ## Vide le cache Symfony en prod (⚠ obligatoire après tout déploiement — cache Twig persisté dans le volume app_var)
	ssh $(PROD_SSH) 'cd $(PROD_DIR) && $(PROD_COMPOSE) exec -T app php bin/console cache:clear'

prod-migrate: ## Applique les migrations Doctrine en prod (sans interaction)
	ssh $(PROD_SSH) 'cd $(PROD_DIR) && $(PROD_COMPOSE) exec -T app php bin/console doctrine:migrations:migrate -n'

prod-nginx-reload: ## Applique un changement de docker/nginx/default.conf (bind mount : pas de rebuild, simple recreate)
	ssh $(PROD_SSH) 'set -e; cd $(PROD_DIR) \
		&& git fetch origin main \
		&& git reset --hard origin/main \
		&& $(PROD_COMPOSE) up -d --force-recreate nginx'

prod-ps: ## État des conteneurs en prod
	ssh $(PROD_SSH) 'cd $(PROD_DIR) && $(PROD_COMPOSE) ps'

prod-logs: ## Suit les logs du conteneur app en prod (100 dernières lignes)
	ssh $(PROD_SSH) 'cd $(PROD_DIR) && $(PROD_COMPOSE) logs -f --tail=100 app'

prod-shell: ## Ouvre un shell dans le conteneur app en prod
	ssh -t $(PROD_SSH) 'cd $(PROD_DIR) && $(PROD_COMPOSE) exec app sh'

prod-console: ## Console Symfony en prod, ex : make prod-console CMD="app:news:generate"
	ssh $(PROD_SSH) 'cd $(PROD_DIR) && $(PROD_COMPOSE) exec -T app php bin/console $(CMD)'

prod-snapshot: ## Dump SQL de la base de PROD, rapatrié dans snapshots/ (⚠ ne jamais commiter)
	@mkdir -p snapshots
	@FILE=snapshots/prod_snapshot_$$(date +%Y-%m-%d_%H-%M-%S).sql; \
	ssh $(PROD_SSH) 'cd $(PROD_DIR) && $(PROD_COMPOSE) exec -T database pg_dump -U trading_user trading_data --data-only --inserts' > $$FILE; \
	echo "Snapshot prod saved: $$FILE"

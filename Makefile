.PHONY: run serve snapshot help

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

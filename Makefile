.PHONY: run serve snapshot help

DB_USER=kiady
DB_PASS=galaxys3mini
DB_HOST=127.0.0.1
DB_PORT=5432
DB_NAME=trading_data

help:
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-30s\033[0m %s\n", $$1, $$2}'

run: ## Run the project on localhost:8001
	symfony serve --port=8001

serve: run ## Alias for run

snapshot: ## Create a SQL snapshot of the current database (saved in snapshots/)
	@mkdir -p snapshots
	@FILE=snapshots/snapshot_$$(date +%Y-%m-%d_%H-%M-%S).sql; \
	PGPASSWORD=$(DB_PASS) pg_dump -h $(DB_HOST) -p $(DB_PORT) -U $(DB_USER) --data-only --inserts $(DB_NAME) > $$FILE; \
	echo "Snapshot saved: $$FILE"

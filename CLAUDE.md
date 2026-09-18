# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Trading Tracker is a Symfony 7.3 web application for tracking and analyzing trades.
PostgreSQL + Doctrine ORM, Twig + Stimulus.js/Hotwired Turbo frontend (Asset Mapper,
no build step), screenshots on Cloudflare R2.

**Production is live** at https://trading-tracker.freeddns.org (DigitalOcean droplet,
Docker Compose). The old Mac mini deployment is obsolete.

## Documentation map

Read these before diving into the code — they are written to orient an AI quickly:

| Doc | Contents |
|---|---|
| [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) | Stack, ER diagram of all entities, request flow, auth (form + Firebase), R2 screenshot pipeline, routes |
| [docs/PRODUCTION.md](docs/PRODUCTION.md) | **Prod runbook**: infra diagram, deploy procedure (with the cache:clear trap), SSL/renewal, env vars, troubleshooting |
| [docs/API.md](docs/API.md) | REST API reference (`/api/*`) |
| [ctrader/README.md](ctrader/README.md) | The two cTrader cBots: auto-tracking of positions via the API, and the rollover stop-loss guard (17:00 New York) |
| [docs/plans/](docs/plans/) | Historical/feature plans. `macmini-deployment.md`, `oracle-cloud-deployment.md`, `railway-deployment.md` are **obsolete** (superseded by the droplet); others describe shipped or planned features |

## Common Commands

```bash
# Start development server on localhost:8001
make run

# SQL snapshot of the local database (reads DATABASE_URL from .env.local/.env)
make snapshot

# Run tests
vendor/bin/phpunit
vendor/bin/phpunit tests/path/to/TestClass.php     # single file
vendor/bin/phpunit --filter testMethodName          # single method

# Database migrations
symfony console doctrine:migrations:migrate
symfony console doctrine:migrations:diff   # Generate migration from entity changes

# Load fixtures — ALWAYS --append (see pitfalls below)
symfony console doctrine:fixtures:load --append

# Clear cache
symfony console cache:clear

# Install dependencies (auto-runs cache:clear, assets:install, importmap:install)
composer install

# Deploy to production (fetch/reset, rebuild, migrations, cache:clear, healthcheck)
make deploy

# Other prod targets: prod-check, prod-cache-clear, prod-migrate, prod-logs,
# prod-ps, prod-shell, prod-console CMD="...", prod-nginx-reload, prod-snapshot

# Regenerate a daily news wrap in prod (normally done by a 23:59 cron on the
# droplet — claude -p runs INSIDE the app container, see docs/PRODUCTION.md)
make prod-console CMD="app:news:generate --date=2026-09-14"
```

Production procedure details (and the cache:clear trap): see
[docs/PRODUCTION.md](docs/PRODUCTION.md#déployer-un-changement).

## ⚠ Critical pitfalls

1. **The local dev database contains REAL trading data** (it was the source of the
   prod data). Never drop it, never run `doctrine:fixtures:load` without
   `--append` (a purge would wipe real trades). Fixtures must stay idempotent.
2. **Prod deploys need `cache:clear`**: on the droplet, `var/` lives in a named
   volume that survives rebuilds, so the compiled Twig cache goes stale. Rebuild
   alone is not enough. Full procedure in docs/PRODUCTION.md.
3. **Never commit** `.env.local`, `.env.test`, SQL dumps, or any credential — the
   repo is **public**. The committed `.env` holds only placeholder defaults;
   real secrets live in `.env.local` (dev) and in the droplet's `.env` (prod).
4. **Git history was rewritten on 2026-09-15** (twice, to purge leaked secrets).
   Any clone older than that must be re-cloned or `git fetch && git reset --hard
   origin/main` — never `git pull` across the rewrite.
5. **Only one real user account** (id=2) and its email address is fictitious.
   Be careful with auth or migration logic that matches users by email.

## Architecture in one paragraph

The core entity is `Trade` (owner `User`; classification via `TradeType` and
`Trend`; many-to-many `Timeframe`/`Confluence`; screenshots via
`TradeScreenshot`; mistakes via `TradeError`). `status` is derived from dates
(watchlist → open → closed) and gain fields are computed on save. Controllers live
in `src/Controller/` (plus `Api/` and `Admin/`), custom queries in
`src/Repository/` (stats), business logic in `src/Service/` (`FileUploader`
compresses with GD and uploads to R2). Full details and diagrams:
[docs/ARCHITECTURE.md](docs/ARCHITECTURE.md).

## Authentication

Form-based login (email as identifier) plus Firebase "Sign in with Google"
(inactive in prod while `FIREBASE_*` env vars are empty). Roles: ROLE_USER,
ROLE_ADMIN, ROLE_MODERATOR, ROLE_TRADER. All routes except `/login`, `/register`,
and `/` require ROLE_USER.

## Database

PostgreSQL with Doctrine ORM. Local connection configured in `.env.local`
(`DATABASE_URL`). Docker Compose available for a local PostgreSQL instance:
`docker compose up -d` (dev stack `compose.yaml` — the prod stack is
`compose.prod.yaml`, droplet only).

## File Uploads

Trade screenshots are compressed by the `FileUploader` service and stored on
**Cloudflare R2** (not on local disk); templates build image URLs from the
`screenshots_base_url` Twig global.

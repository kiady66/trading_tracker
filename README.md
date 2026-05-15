# Trading Tracker

A personal trading journal built with Symfony 7.3. Log trades, track performance, and analyze your results with filters and charts.

## Features

- Trade lifecycle management (watchlist → open → closed)
- Screenshot uploads per trade (execution, management, closing)
- Statistics dashboard with date range and confluence filters
- Cumulative P&L charts
- Day-of-week performance breakdown
- REST API for external integrations

## Requirements

- PHP 8.2+
- PostgreSQL 15+
- Symfony CLI
- Docker (optional, for local database)

## Getting Started

### 1. Clone and install dependencies

```bash
git clone <repo-url>
cd trading-tracker
composer install
```

### 2. Configure the environment

```bash
cp .env .env.local
```

Edit `.env.local` and set your database connection:

```env
DATABASE_URL=postgresql://user:password@127.0.0.1:5432/trading_data?serverVersion=15&charset=utf8
```

### 3. Start the database

```bash
# With Docker
docker compose up -d

# Or use your own PostgreSQL instance
```

### 4. Create the schema and load fixtures

```bash
symfony console doctrine:migrations:migrate
symfony console doctrine:fixtures:load   # optional — loads sample data
```

### 5. Run the dev server

```bash
make run
# Starts on http://localhost:8001
```

## Common Commands

```bash
# Dev server
make run

# Run tests
vendor/bin/phpunit

# Generate a migration after entity changes
symfony console doctrine:migrations:diff
symfony console doctrine:migrations:migrate

# Create an admin user
symfony console app:create-admin

# Clear cache
symfony console cache:clear

# Database snapshot
make snapshot
```

## Documentation

- [Architecture](docs/ARCHITECTURE.md)
- [REST API](docs/API.md)

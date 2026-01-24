# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Trading Tracker is a Symfony 7.3 web application for tracking and analyzing trades. It uses PostgreSQL for data storage, Doctrine ORM for database operations, and Twig with Stimulus.js/Hotwired Turbo for the frontend.

## Common Commands

```bash
# Start development server on localhost:8001
make run

# Run tests
vendor/bin/phpunit

# Run a single test file
vendor/bin/phpunit tests/path/to/TestClass.php

# Run a single test method
vendor/bin/phpunit --filter testMethodName

# Database migrations
symfony console doctrine:migrations:migrate
symfony console doctrine:migrations:diff   # Generate migration from entity changes

# Load fixtures (test data)
symfony console doctrine:fixtures:load

# Clear cache
symfony console cache:clear

# Install dependencies (auto-runs cache:clear, assets:install, importmap:install)
composer install
```

## Architecture

### Domain Model

The core domain revolves around `Trade` entity which has relationships with:
- **User** (many-to-one) - trade owner
- **TradeType**, **Trend**, **Result** (many-to-one) - trade classification
- **Timeframe**, **Confluence**, **Setup** (many-to-many) - flexible categorization
- **TradeScreenshot** (one-to-many) - execution/management/closing screenshots
- **TradeError** (many-to-one) - mistake tracking

### Directory Structure

- `src/Controller/` - HTTP controllers (TradeController, StatsController, SecurityController, etc.)
- `src/Entity/` - Doctrine entities with attribute-based ORM mapping
- `src/Repository/` - Custom query methods for entities
- `src/Form/` - Symfony form types
- `src/Service/` - Business logic (FileUploader for screenshot handling)
- `src/Command/` - CLI commands (CreateAdminCommand)
- `templates/` - Twig templates organized by feature
- `assets/controllers/` - Stimulus JavaScript controllers

### Authentication

Form-based login using Symfony Security with email as identifier. User roles: ROLE_USER, ROLE_ADMIN, ROLE_MODERATOR, ROLE_TRADER. All routes except `/login`, `/register`, and `/` require ROLE_USER.

### Frontend

Uses Symfony Asset Mapper (no webpack/npm build step). JavaScript interactivity via Stimulus.js controllers and Hotwired Turbo for AJAX navigation.

## Database

PostgreSQL 15+ with Doctrine ORM. Connection configured in `.env`. Docker Compose available for local PostgreSQL instance:

```bash
docker compose up -d
```

## File Uploads

Trade screenshots stored in `public/uploads/`. The `FileUploader` service handles image compression and storage.
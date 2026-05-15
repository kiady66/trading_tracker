# Architecture

## Stack

| Layer | Technology |
|---|---|
| Framework | Symfony 7.3 |
| Database | PostgreSQL 15+ via Doctrine ORM |
| Templates | Twig |
| Frontend interactivity | Stimulus.js + Hotwired Turbo |
| Asset pipeline | Symfony Asset Mapper (no build step) |
| File uploads | Custom `FileUploader` service (GD) |

## Domain Model

The core entity is `Trade`. A trade moves through three statuses automatically based on which dates are set:

```
watchlistDate set → status: "watching"
+ entryDate set   → status: "open"
+ exitDate set    → status: "closed"
```

### Entity relationships

```
User
 └── Trade (one-to-many)
      ├── TradeType       (many-to-one)   — e.g. "breakout", "reversal"
      ├── Trend           (many-to-one)   — market direction
      ├── TradeError      (many-to-one)   — mistake category
      ├── Timeframe       (many-to-many)  — H1, H4, D1 ...
      ├── Confluence      (many-to-many)  — technical confluences
      └── TradeScreenshot (one-to-many)   — categorized screenshots
           └── category: execution | management | closing
```

### Calculated fields

These fields are never set directly — they are derived and recalculated on save:

| Field | Formula |
|---|---|
| `status` | derived from `entryDate` / `exitDate` |
| `day` | day-of-week from `entryDate` |
| `gainRR` | `finalRR × (riskPercentage / 100)` |
| `gainEuro` | `gainRR × maxRiskEuro` |

## Directory Structure

```
src/
├── Command/           CLI commands (CreateAdminCommand)
├── Controller/
│   ├── Api/           REST API controllers
│   ├── HomeController.php
│   ├── SecurityController.php
│   ├── TradeController.php
│   ├── StatsController.php
│   ├── ConfluenceController.php
│   ├── TimeframeController.php
│   ├── TradeTypeController.php
│   ├── TradeErrorController.php
│   └── TrendController.php
├── DataFixtures/      Sample data for development
├── Entity/            Doctrine entities
├── Form/              Symfony form types
├── Repository/        Custom Doctrine queries
├── Security/          Authentication entry points
└── Service/
    └── FileUploader.php

templates/             Twig templates (mirroring controller structure)
assets/
├── controllers/       Stimulus JS controllers
├── styles/
└── vendor/            importmap-managed JS packages
config/
├── packages/          Bundle configuration
└── routes/
migrations/            Doctrine migration files
docs/                  Project documentation
```

## Authentication

Form-based login using Symfony Security. Email is the user identifier.

**Roles:**
- `ROLE_USER` — required for all protected routes
- `ROLE_ADMIN` — admin access, also grants premium features
- `ROLE_MODERATOR` — moderation access
- `ROLE_TRADER` — trader role

**Premium features** (currently granted to admins):
- Screenshots up to 500 KB, compression quality 90%
- Free users: 20 KB max, quality 10%

**Firewalls:**
- `dev` — profiler, assets (no security)
- `api` — `/api/*` routes, returns JSON 401 on unauthenticated access
- `main` — all other routes, redirects to `/login`

## Key Web Routes

| Method | Path | Description |
|---|---|---|
| GET | `/` | Landing page |
| GET/POST | `/login` | Login |
| GET/POST | `/register` | Registration |
| GET | `/trade` | Trade list |
| GET/POST | `/trade/new` | Create trade |
| GET | `/trade/{id}` | Trade detail |
| GET/POST | `/trade/{id}/edit` | Edit trade |
| POST | `/trade/{id}` | Delete trade |
| GET | `/stats` | Analytics dashboard |

Reference entities (TradeType, Trend, Confluence, Timeframe, TradeError) each have their own CRUD at `/trade-type`, `/trend`, `/confluence`, `/timeframe`, `/trade-error`.

## Statistics & Charting

`TradeRepository` provides four query methods used by `StatsController`:

- `getStatistics()` — aggregates (total, win rate, avg P&L)
- `getChartData()` — time-series data for cumulative P&L chart
- `getConfluenceStats()` — per-confluence win rate and avg gain
- `getDayStats()` — performance breakdown by day of week

All methods accept a `filters` array supporting: `user`, `start_date`, `end_date`, `confluences`.

## File Uploads

`FileUploader` service handles screenshot storage in `public/uploads/screenshots/`.

Upload flow:
1. Slugify + unique ID → safe filename
2. Move to target directory
3. Compress to `maxFileSizeKB` by progressively reducing quality (JPEG/WebP) or resizing (PNG)
4. Returns stored filename

Filenames are stored on `TradeScreenshot.filename`. The `remove()` method deletes the file on screenshot deletion.
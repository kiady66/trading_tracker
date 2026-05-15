# REST API — Trades

Base URL: `/api/trades`

## Authentication

The API uses **Bearer token** authentication. Each user has a personal token visible on their profile page (`/profile`).

Add the following header to every request:

```
Authorization: Bearer <your-token>
```

To get or regenerate your token: go to **Mon profil → Token API cTrader**.

Unauthenticated or invalid token requests return:

```json
HTTP 401
{ "error": "Authentication required" }
```

---

## Endpoints

### List trades

```
GET /api/trades
```

Returns all trades belonging to the authenticated user, sorted by `watchlistDate` descending.

**Query parameters**

| Parameter | Type | Description |
|---|---|---|
| `status` | string | Filter by status: `watching`, `open`, `closed` |
| `asset` | string | Filter by asset symbol (e.g. `EUR/USD`) |
| `ctraderPositionId` | int | Find the trade linked to a specific cTrader position |

**Response** `200 OK`

```json
[
  {
    "id": 42,
    "asset": "EUR/USD",
    "status": "closed",
    "entryDate": "2026-05-10T09:15:00+00:00",
    "exitDate": "2026-05-12T14:30:00+00:00",
    "watchlistDate": "2026-05-09T08:00:00+00:00",
    "day": "Sunday",
    "orderType": "buy limit",
    "riskPercentage": 1.5,
    "initialRR": 3.0,
    "finalRR": 2.8,
    "gainRR": 0.042,
    "gainEuro": 63.0,
    "maxRiskEuro": 1500.0,
    "tradeManagement": false,
    "goodTrade": true,
    "tradeQuality": 4,
    "executionReason": "Clean H4 breakout with confluence",
    "noteErrors": null,
    "tradeType": { "id": 1, "name": "Breakout" },
    "trend": { "id": 2, "name": "Bullish" },
    "error": null,
    "timeframes": [
      { "id": 1, "name": "H4" },
      { "id": 3, "name": "H1" }
    ],
    "confluences": [
      { "id": 2, "name": "Support level" }
    ]
  }
]
```

---

### Get a trade

```
GET /api/trades/{id}
```

**Response** `200 OK` — same object shape as above.

**Errors**

| Code | Description |
|---|---|
| `403` | Trade belongs to another user |
| `404` | Trade not found |

---

### Create a trade

```
POST /api/trades
Content-Type: application/json
```

**Required fields**

| Field | Type | Description |
|---|---|---|
| `asset` | string | Must be one of the [allowed assets](#allowed-assets) |
| `orderType` | string | Must be one of the [allowed order types](#allowed-order-types) |
| `riskPercentage` | number | Risk as a percentage of account (e.g. `1.5`) |
| `maxRiskEuro` | number | Maximum risk in euros |

**Optional fields**

| Field | Type | Description |
|---|---|---|
| `entryDate` | string\|null | ISO 8601 datetime |
| `exitDate` | string\|null | ISO 8601 datetime |
| `watchlistDate` | string | ISO 8601 datetime (defaults to now) |
| `initialRR` | number\|null | Initial risk/reward target |
| `finalRR` | number\|null | Actual risk/reward at close |
| `tradeTypeId` | int\|null | ID of a TradeType |
| `trendId` | int\|null | ID of a Trend |
| `errorId` | int\|null | ID of a TradeError |
| `timeframeIds` | int[] | Array of Timeframe IDs |
| `confluenceIds` | int[] | Array of Confluence IDs |
| `tradeManagement` | bool | Whether trade management was applied |
| `goodTrade` | bool\|null | Subjective quality flag |
| `tradeQuality` | int\|null | Quality score 1–5 |
| `executionReason` | string\|null | Free text — why this trade was taken |
| `noteErrors` | string\|null | Free text — mistakes noted |
| `ctraderPositionId` | int\|null | cTrader position ID — used to link and retrieve the trade from the cBot |

**Example request**

```json
{
  "asset": "EUR/USD",
  "orderType": "buy limit",
  "riskPercentage": 1.5,
  "maxRiskEuro": 1500.0,
  "entryDate": "2026-05-15T09:00:00",
  "initialRR": 3.0,
  "tradeTypeId": 1,
  "trendId": 2,
  "timeframeIds": [1, 3],
  "confluenceIds": [2, 5],
  "executionReason": "Clean breakout on H4 with support confluence"
}
```

**Response** `201 Created` — the created trade object.

**Errors**

| Code | Body | Description |
|---|---|---|
| `400` | `{ "error": "Invalid JSON" }` | Malformed request body |
| `422` | `{ "errors": { "field": "message" } }` | Validation failed |

---

### Update a trade (full)

```
PUT /api/trades/{id}
Content-Type: application/json
```

Same fields as `POST`. All required fields must be present.

**Response** `200 OK` — the updated trade object.

---

### Update a trade (partial)

```
PATCH /api/trades/{id}
Content-Type: application/json
```

Send only the fields to update. Fields not included are left unchanged.

**Example** — close a trade:

```json
{
  "exitDate": "2026-05-15T14:30:00",
  "finalRR": 2.5,
  "goodTrade": true,
  "tradeQuality": 4
}
```

**Response** `200 OK` — the updated trade object.

---

## Computed fields

These fields in the response are calculated server-side and cannot be set directly:

| Field | How it is calculated |
|---|---|
| `status` | `watching` if no entryDate, `open` if no exitDate, `closed` otherwise |
| `day` | Day of week extracted from `entryDate` |
| `gainRR` | `finalRR × (riskPercentage / 100)` |
| `gainEuro` | `gainRR × maxRiskEuro` |

---

## Allowed assets

```
EUR/USD, GBP/USD, USD/JPY, USD/CHF, AUD/USD, USD/CAD, NZD/USD,
EUR/GBP, EUR/JPY, GBP/JPY, GBP/CHF, NZD/JPY, AUD/GBP, AUD/NZD,
AUD/CAD, NZD/CAD, AUD/CHF, AUD/JPY, GBP/CAD, GBP/AUD, CAD/CHF,
CAD/JPY, CHF/JPY, BTC/USD, ETH/USD, XAU/USD, SP500
```

## Allowed order types

```
buy market, sell market, buy limit, sell limit, buy stop, sell stop
```
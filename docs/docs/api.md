# REST API

You can access PriceBuddy via its built-in REST API that can be used to create, read, update and delete
(CRUD) products, stores and tags programmatically. This is useful if any other external applications or 
extensions want to interact with PriceBuddy.

## Authentication / API Tokens

To use any of the API endpoints, you must be authenticated. This is done via an API token. You can generate
an API token by logging into PriceBuddy, clicking the user menu (top right) and clicking "API tokens". Here
you can create a token for a specific user and assign the permissions allowed for the token.

Once a token is generated, it will appear in a notification popup. Copy this and save in a safe place, this
is essentially the same as a password and will not be shown to you again.

## API Documentation

The API documentation is generated using the application code and can be viewed via `/docs/api` url
(eg `https://my-pricebuddy.local/docs/api`).

Via the docs you can test out the API with your API token, see request and response formats and even
export the openapi json spec.

## Meta Extraction

`POST /api/meta-extraction`

Use this endpoint to extract `title`, `price`, and `image` from a product URL without persisting anything.
It automatically uses a matching store configuration based on the URL domain and can also accept a
`store` override payload when you want to test a custom scrape strategy.

Example request:

```json
{
  "url": "https://example.com/product",
  "store": {
    "settings": {
      "scraper_service": "http",
      "cookies": "sessionid=abc123"
    },
    "scrape_strategy": {
      "title": { "type": "selector", "value": "meta[property=\"og:title\"]|content" },
      "price": { "type": "selector", "value": "meta[property=\"og:price:amount\"]|content" },
      "image": { "type": "selector", "value": "meta[property=\"og:image\"]|content" }
    }
  }
}
```

The response shape is:

```json
{
  "data": {
    "title": "Example product",
    "price": 35.0,
    "image": "https://example.com/image.jpg"
  }
}
```

The `price` field is normalized to a numeric value in both the store-backed and auto-create paths.

## Products

Products are managed via the standard CRUD endpoints (`GET/POST /api/products`,
`GET/PUT/DELETE /api/products/{id}`). The full request/response schema is in the
interactive docs at `/docs/api`. A few fields have behaviour worth calling out.

### Schedule & notification fields (writable via `PUT /api/products/{id}`)

| Field | Type | Notes |
| --- | --- | --- |
| `paused` | boolean | When `true`, the product is skipped by both the global schedule and any custom cadence. |
| `notify_in_stock` | boolean | Notify when a tracked URL becomes available again after being out of stock. |
| `refresh_interval` | integer (seconds) or `null` | Custom check cadence. `null` follows the global fetch schedule. Setting or changing it makes the product due on the next run. |

Allowed `refresh_interval` values (seconds): `300` (5m), `600` (10m), `900` (15m),
`1800` (30m), `3600` (1h), `7200` (2h), `14400` (4h), `21600` (6h), `43200` (12h),
`86400` (24h). Any other value is rejected with `422`.

Example — pause a product and set a 1-hour check interval:

```json
PUT /api/products/42
{
  "title": "Example product",
  "image": "https://example.com/image.jpg",
  "paused": false,
  "refresh_interval": 3600,
  "notify_in_stock": true
}
```

(`title` and `image` are required by the update endpoint.)

### Insights (`GET /api/products/{id}?include=insights`)

The product detail endpoint can embed the full insights data set — price statistics,
deal score, percentile, price distribution, drop events, store showdown, seasonality,
availability, and target tracker — under a top-level `insights` key. It is **opt-in**:
pass `?include=insights`. Without it, the `insights` key is omitted. The data is served
from a cache that refreshes whenever prices update, so it is cheap to request.

The insights block is only available on the **detail** endpoint; the list endpoint
(`GET /api/products`) ignores `include=insights`.

Example response (truncated):

```json
{
  "data": {
    "id": 42,
    "title": "Example product",
    "insights": {
      "hasEnoughData": true,
      "bestPrice": 95.0,
      "bestStore": "Acme",
      "dealScore": { "score": 8.5, "verdictKey": "great", "verdict": "Great time to buy", "isAllTimeLow": false, "lowConfidence": false },
      "stats": { "lowest": 80.0, "highest": 120.0, "average": 100.0, "current": 95.0, "percentVsAverage": -5.0 },
      "percentile": { "beatFraction": 0.75, "percentCheaperThan": 75 },
      "targetTracker": { "target": 90.0, "current": 95.0, "gap": 5.0, "progressPercent": 40 }
    }
  }
}
```

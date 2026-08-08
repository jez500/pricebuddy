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
    "currency": "AUD",
    "locale": "en-AU",
    "image": "https://example.com/image.jpg",
    "healing": { "attempted": false, "applied": false, "reason": "disabled" }
  }
}
```

The `price` field is normalized to a numeric value in both the store-backed and auto-create paths.

`currency` (ISO 4217) and `locale` (BCP-47) tell a client how to format `price`. They come from
the matched store's locale settings, falling back to the app-level defaults when no store could be
resolved (in which case `store` is `{}`). Both are always present. The embedded `store` object
carries the same two fields.

### AI healing and the request budget

When the deterministic scrape finds no price, this endpoint can ask the AI healing agent to work
out a strategy. That is slow, so it is **opt-in**: send `"heal": true`. The default is `false`,
which returns the deterministic result immediately.

Prefer the default when testing selectors a user just entered — healing may propose a *different*
strategy than the one under test. Use `heal: true` for an explicit "work it out for me" action,
and tell the user it will take longer.

The whole request is bounded by `price_buddy.meta_extraction.budget_seconds` (default 25,
`META_EXTRACTION_BUDGET_SECONDS`), covering the scrape, any browser re-scrape, and the AI call —
which is given the time remaining rather than the provider's own timeout. When less than
`heal_floor_seconds` (default 5) remains, healing is skipped rather than started and cut off.
Read the ceiling from `limits.meta_extraction_timeout_seconds` in `/api/client-config` instead of
hardcoding a client-side abort.

Healing never fails the request. Whatever happens, the response is a 200 carrying the
deterministic result, and `healing` says what became of it:

```json
"healing": { "attempted": true, "applied": false, "reason": "timeout" }
```

| `reason` | meaning |
| --- | --- |
| `null` | healing ran and its config was applied (`applied: true`) |
| `disabled` | not requested, the store opted out, or no healing provider is configured |
| `not_needed` | the deterministic scrape already produced a usable result |
| `timeout` | the budget ran out, or too little remained to start |
| `error` | healing ran but errored or produced no usable config |

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

### Finding a product by page URL

`GET /api/products?filter[url]=<raw page URL>`

Returns every product tracking that page. Matching is normalised, so all of these find
the same product:

```text
https://www.target.com.au/p/xbox-controller/
http://target.com.au/p/Xbox-Controller
https://target.com.au/p/xbox-controller?utm_source=news&gclid=abc
```

Scheme, port, userinfo, fragment, a leading `www.`, trailing slashes and letter case are
all ignored, as are tracking parameters (`utm_*`, `gclid`, `fbclid`, `ref`, `tag` and
others — see `config/url_matching.php`). Parameters that identify a product, such as
Shopify's `?variant=`, are significant and are NOT ignored, so two variants of the same
page remain distinct products.

An unmatched or unparseable URL returns `200` with an empty `data` array, never an error.

### Marking the listing you are on

`GET /api/products/{id}?include=insights&current_url=<raw page URL>`

When `current_url` is supplied, every entry in `price_cache` gains an `is_current`
boolean, true for the listing matching that page. The same URL normalisation applies.
Without the parameter the key is absent and the response is unchanged.

All matching entries are flagged, so a product tracking the same page twice gets `true`
on both. `current_url` is also accepted on `GET /api/products`.

## Client config

`GET /api/client-config`

Capability discovery, so a client can tell whether this instance supports the URL
matching endpoints without probing for a 4xx.

```json
{
  "data": {
    "capabilities": {
      "products_filter_url": true,
      "products_current_url": true,
      "products_sparse_fieldsets": true,
      "stores_filter_domain": true
    },
    "limits": {
      "meta_extraction_timeout_seconds": 25
    },
    "app_version": "1.4.2"
  }
}
```

`limits` publishes this instance's own ceilings so a client can configure itself from them
rather than hardcoding a guess that silently drifts.

Requires a token with the `client-config:read` ability, or an all-access token. Tokens
created before this endpoint existed will need re-minting to gain the ability.

The response carries an `ETag` and `Cache-Control: private, max-age=86400`. Send
`If-None-Match` to get a `304`.

## Stores

### Finding a store by domain

`GET /api/stores?filter[domain]=<bare host>`

Exact match on a host such as `www.Target.com.au` or `location.host` with a port. A
leading `www.`, letter case and any port are ignored. Unlike `filter[domains]`, which is
a partial match, this will not match a substring.

Advertised as the `stores_filter_domain` capability in `/api/client-config`.

### Scrape strategy values

Each `scrape_strategy` slot (`title`, `price`, `image`) has a `type` and, for most types, a
`value` holding the extraction expression. `schema_org` reads the page's embedded metadata
and takes no expression:

| `type` | `value` |
| --- | --- |
| `schema_org` | Must be omitted or `null`. Sending one is a 422. |
| `selector`, `xpath`, `regex`, `json` | Required, and must be a non-empty string. |

`POST /api/stores`, `PUT /api/stores/{id}` and `POST /api/meta-extraction` all enforce this
identically, so any strategy that meta-extraction accepts can be saved as-is.

A stored `schema_org` strategy round-trips without a `value` key at all — the API returns
`{"type": "schema_org"}`, which is valid input to all three endpoints.

### Currency & locale

Every store in `GET /api/stores` and `GET /api/stores/{id}` carries a `currency` (ISO 4217, e.g.
`"AUD"`) and a `locale` (BCP-47, e.g. `"en-AU"`). These are resolved values: a store's own
`settings.locale_settings` if set, otherwise the app-level defaults from Settings. Reading the raw
`settings` blob is not equivalent — most stores have no `locale_settings` key at all.

Both are computed, not columns, so they cannot be named in `fields[stores]`. They are still
returned correctly when other sparse fieldsets are used.

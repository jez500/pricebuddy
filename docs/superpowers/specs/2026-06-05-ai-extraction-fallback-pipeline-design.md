# AI extraction fallback in the scrape pipeline — design

> PriceGhost parity, Task 1 — pipeline wiring slice. Date: 2026-06-05.
> Branch: `feature/priceghost-parity-ai`.

## Goal

Activate the (currently dormant) AI extraction foundation: when a normal scrape finds
**no price**, optionally let `AiExtractionService` recover one from the page HTML. AI is
**purely additive** — with no provider enabled, or a product opted out, or a price already
found, the pipeline behaves exactly as it does today.

## Scope

In scope:

- A focused `App\Services\AiScrapeEnhancer` that backfills a missing `price` in a scrape
  result when (and only when) AI is enabled and allowed.
- One-line wiring into `Url::updatePrice()`.
- A per-product `ai_extraction_disabled` opt-out (toggle + migration + model).
- A minimum-confidence gate (constant 0.6) and scrape-log attribution.
- Tests.

Out of scope (later slices):

- AI verification, arbitration, and stock-status operations.
- Multi-candidate voting + the price-selection modal (Task 2).
- Backfilling fields other than price (title/image/availability).
- Persisting an AI source flag on the `Price` row, or a configurable confidence setting.

## Background / current state

- `App\Services\AiExtractionService::extract(string $html, ?Collection $schemaOrg = null): ?AiExtractionResultDto`
  exists and is tested. It guards `AiService::isEnabled()` internally (returns null when AI
  is off) and swallows SDK errors → null. The result DTO carries `price` (float), `confidence`
  (float 0–1), and other fields. Its `prepareHtml()` strips `<script>` (incl. JSON-LD).
- `Url::updatePrice(int|float|string|null $price = null, ?array $scrapeResult = null)`:
  when no manual `$price` is passed, it scrapes (`$this->scrape()` → `ScrapeUrl::scrape()`),
  reads `$price = data_get($scrapeResult, 'price')`, computes availability via
  `StockStatus::matchFromScrapedValue($scrapeResult['availability'], $store matchConfig)`,
  and either records a `Price` or returns null / the latest price (out-of-stock).
- `ScrapeUrl::scrape()` returns an array with `price`, `title`, `image`, `availability`,
  `body` (raw HTML), `store`, `errors`. Scrape logs go to `Log::channel('db')`
  (yoeriboven/laravel-log-db → `LogMessageResource`).
- `ProductResource` form has sections incl. `Notifications` (notify_price/percent/in_stock)
  and `Schedule` (paused). `Product` model uses `$fillable` + a `casts()` method.

## Architecture

A single new service `App\Services\AiScrapeEnhancer`, invoked from one line in
`Url::updatePrice()`. Considered and rejected:

- Inlining the logic into `updatePrice()` — bloats the method and couples it to AI.
- Hooking in `ScrapeUrl::scrape()` — too broad; would fire AI for search / auto-create-store
  flows and make the scraper depend on AI settings.

### Wiring point (`Url::updatePrice`)

Inside the existing "no manual price" branch:
```php
if (is_null($price) || $price === '') {
    $scrapeResult = $scrapeResult ?? $this->scrape();
    $scrapeResult = AiScrapeEnhancer::new()->enhance($this, $scrapeResult);
    $price = data_get($scrapeResult, 'price');
}
```
The enhancer only runs in this branch, so a caller passing an explicit `$price` (manual /
non-scrape paths) never triggers AI.

### `AiScrapeEnhancer`

```php
class AiScrapeEnhancer
{
    public const float MIN_CONFIDENCE = 0.6;

    public function __construct(protected AiExtractionService $extraction) {}

    public static function new(): self; // resolve(static::class)

    /**
     * Backfill a missing price via AI when enabled & allowed.
     * Returns the scrape result unchanged unless AI recovers a confident price.
     *
     * @param  array<string, mixed>  $scrapeResult
     * @return array<string, mixed>
     */
    public function enhance(Url $url, array $scrapeResult): array;
}
```

Guard ladder inside `enhance()` — any failure returns `$scrapeResult` untouched:

1. `filled(data_get($scrapeResult, 'price'))` → unchanged (gap-fill only).
2. `! IntegrationHelper::isAiEnabled()` → unchanged. *(the "optional" guarantee — no work when AI is off)*
3. `$url->product?->ai_extraction_disabled` is true → unchanged.
4. `$html = data_get($scrapeResult, 'body')`; `blank($html)` → unchanged.
5. Unavailable: `StockStatus::matchFromScrapedValue(data_get($scrapeResult, 'availability'),
   data_get($url->store, 'scrape_strategy.availability.match'))->isUnavailable()` → unchanged
   (no purchasable price expected; don't spend tokens).
6. `$result = $this->extraction->extract($html);` If `$result === null`, `$result->price === null`,
   or `$result->confidence < self::MIN_CONFIDENCE` → log a debug note, return unchanged.
7. Otherwise: `data_set($scrapeResult, 'price', $result->price)`; log to `Log::channel('db')`
   (info): `"Price recovered via AI (confidence {n})."` with `['url' => $url->url]` context;
   return the enhanced result.

The AI price is a clean float; the downstream `CurrencyHelper::toFloat($price, ...)` in
`updatePrice` handles a float input correctly. Schema.org is not passed (it already failed to
find a price, and the price lives in visible HTML).

### Per-product opt-out

- Migration `add_ai_extraction_disabled_to_products`: boolean `ai_extraction_disabled`,
  default `false`, after an existing column.
- `Product` model: add to `$fillable` and cast `'ai_extraction_disabled' => 'boolean'`.
- `ProductResource` form: a `Toggle::make('ai_extraction_disabled')` labelled
  "Disable AI extraction" in the `Notifications` section, with a helper hint that it only
  applies when AI is enabled globally.

## Error handling

| Condition | Behaviour |
|---|---|
| AI disabled / no active provider | `enhance()` returns unchanged → `updatePrice` proceeds as today |
| Product opted out | unchanged |
| Scrape already found a price | unchanged (no AI call) |
| Determined out-of-stock | unchanged (no AI call) |
| AI error / null / low confidence | unchanged; debug log |
| AI provider slow | bounded by the provider's configured timeout; never fails the scrape |

A broken or slow AI provider can never break or fail a scrape — worst case it adds latency.

## Testing

- `tests/Feature/Services/AiScrapeEnhancerTest.php` (mock `AiExtractionService` via the
  container; seed AI on/off via settings): price already present → untouched (extract never
  called); AI disabled → untouched; product `ai_extraction_disabled` → untouched; unavailable
  scrape result → untouched; low-confidence result → untouched; confident result → `price`
  backfilled and a `db`-channel log written (assert via `Log::shouldReceive`/a fake or by
  asserting the returned array).
- `Url::updatePrice` feature test: a URL whose scrape yields no price, with AI enabled and a
  confident mocked extraction → a `Price` row is created with the AI price; with AI disabled →
  still returns null (unchanged behaviour) and no `Price` row.
- Model/migration: `ai_extraction_disabled` persists, casts to bool, and is respected by the
  enhancer.

## File map

New:
- `app/Services/AiScrapeEnhancer.php`
- `database/migrations/<ts>_add_ai_extraction_disabled_to_products.php`
- `tests/Feature/Services/AiScrapeEnhancerTest.php`

Modified:
- `app/Models/Url.php` (one line in `updatePrice`)
- `app/Models/Product.php` (`$fillable` + `casts()`)
- `app/Filament/Resources/ProductResource.php` (toggle)
- `tests/Feature/.../UrlTest` or the existing updatePrice test (add the two cases)

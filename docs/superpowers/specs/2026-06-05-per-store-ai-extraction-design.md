# Per-store AI extraction — design

## Goal

Move control of AI price-extraction fallback out of two places (a global
master switch + a per-product opt-out) and into a single per-store setting,
surfaced in the store edit page's existing "Scraper service" section. A store
opts in to AI extraction and optionally picks which configured AI provider to
use; products inherit this from the store of each tracked URL at scrape time.

## Background — current state

- **Global gate:** `IntegrationHelper::isAiEnabled()` = the App Settings AI
  `enabled` flag AND a resolvable default provider. The `enabled` toggle is
  auto-injected by `FormHelperTrait::makeSettingsSection()` and also reveals the
  provider config UI.
- **Per-product opt-out:** `products.ai_extraction_disabled` column, cast +
  fillable on `Product`, and a "Disable AI extraction" toggle on
  `ProductResource`.
- **Scrape pipeline:** `AiScrapeEnhancer::enhance(Url $url, array $scrapeResult)`
  guards on price-gap → `isAiEnabled()` → product not opted out → html present →
  in stock, then calls `AiExtractionService::extract($html)` →
  `AiService::structured(...)` → `IntegrationHelper::getActiveAiProvider()`
  (global default provider).
- **"Scraper service"** section lives in
  `HasScraperTrait::getScraperSettings()` (used by `StoreResource`): a Radio of
  `ScraperService::Http` / `ScraperService::Api` plus a settings textarea.
- Store config persists in the store's `settings` JSON column (e.g.
  `settings.scraper_service`), read via accessors like `Store::scraperService`.

## Decisions

1. **AI extraction is an additive toggle**, not a third request method. The
   Http/Api radio is unchanged; AI remains a fallback that runs after the chosen
   request method fetches HTML.
2. **App Settings keeps its `enabled` toggle**, reframed as "AI is
   configured/active". It governs only whether the per-store controls are
   *offered*; it is **not** checked at scrape time.
3. **Per-product `ai_extraction_disabled` is removed fully** — column,
   cast/fillable, form toggle, and pipeline guard. (Feature-branch work,
   unreleased, so a clean drop is safe.)
4. **The per-store provider select stores the chosen provider's id** and
   pre-selects the global default. No "use default" sentinel value.

## Detailed design

### 1. App Settings (global) — no change

`AppSettingsPage::getAiSettings()`, the providers repeater, and
`default_provider_id` are unchanged. `IntegrationHelper::isAiEnabled()` keeps its
current definition and is used only to decide whether to render the per-store
controls.

### 2. Store edit page — new controls

In `HasScraperTrait::getScraperSettings()`, after the existing radio + textarea:

- `Toggle::make('settings.ai_extraction_enabled')`
  - label: "Enable AI price extraction"
  - help: "Use AI to recover a price when the normal scrape finds none."
  - `->reactive()`
  - `->hidden(fn (): bool => ! IntegrationHelper::isAiEnabled())`
- `Select::make('settings.ai_provider_id')`
  - label: "AI provider"
  - `->options(...)` = `IntegrationHelper::getAiProviders()` keyed `id => name`
  - `->default(fn () => IntegrationHelper::getActiveAiProvider()?->id)`
  - `->visible(fn (Get $get): bool => (bool) $get('settings.ai_extraction_enabled'))`

Persists into the store `settings` JSON column — no store migration.

Import `IntegrationHelper` (and `Get`, already imported) into the trait.

### 3. Store model accessors

Add to `App\Models\Store`, mirroring `scraperService()`:

```php
public function aiExtractionEnabled(): Attribute
{
    return Attribute::make(
        get: fn (): bool => (bool) data_get($this->settings, 'ai_extraction_enabled', false),
    );
}

public function aiProviderId(): Attribute
{
    return Attribute::make(
        get: fn (): ?string => data_get($this->settings, 'ai_provider_id'),
    );
}
```

Add `@property bool $ai_extraction_enabled` and
`@property string|null $ai_provider_id` to the class docblock.

### 4. Provider resolution helper

Add to `IntegrationHelper`:

```php
public static function getAiProvider(?string $id): ?AiProviderConfigDto
{
    if (blank($id)) {
        return self::getActiveAiProvider();
    }

    foreach (self::getAiProviders() as $provider) {
        if ($provider->id === $id) {
            return $provider;
        }
    }

    return self::getActiveAiProvider();
}
```

Returns the provider matching `$id`, else falls back to the global default.

### 5. Scrape pipeline

`AiScrapeEnhancer::enhance()`:

- Remove the `IntegrationHelper::isAiEnabled()` guard.
- Remove the `$url->product?->ai_extraction_disabled` guard.
- Add, near the top (after the price-gap check):
  ```php
  $store = $url->store;
  if (! $store?->ai_extraction_enabled) {
      return $scrapeResult;
  }
  $provider = IntegrationHelper::getAiProvider($store->ai_provider_id);
  if ($provider === null) {
      return $scrapeResult;
  }
  ```
- Keep the html-present and out-of-stock guards.
- Pass the provider down: `$this->extraction->extract($html, provider: $provider)`.

`AiExtractionService::extract()` gains an optional
`?AiProviderConfigDto $provider = null` parameter (after `$schemaOrg`). It
resolves `$provider ??= IntegrationHelper::getActiveAiProvider()`; if still null,
return null. Pass the provider to `AiService::structured(...)`.

`AiService::structured()` gains an optional `?AiProviderConfigDto $provider = null`
parameter; when null it falls back to `IntegrationHelper::getActiveAiProvider()`
exactly as today, so existing callers and tests are unaffected.

### 6. Remove per-product opt-out

- New migration: drop `ai_extraction_disabled` from `products`.
- `Product`: remove the cast and fillable entry.
- `ProductResource`: remove the "Disable AI extraction" toggle; remove the
  `IntegrationHelper` import if nothing else in the file uses it.

## Testing

- Update `tests/Feature/Services/AiScrapeEnhancerTest.php`: gate via store
  `ai_extraction_enabled` + provider rather than global-enabled / product
  opt-out; drop the opt-out cases.
- Update `tests/Feature/Models/UrlUpdatePriceTest.php` similarly.
- Add an `IntegrationHelper::getAiProvider()` unit test: matches by id, falls
  back to default when blank, falls back to default when id no longer matches.
- Add a Store form test (CreateStore/EditStore Livewire): the AI toggle renders
  when AI is configured, the provider select appears when the toggle is on, and
  the global default is pre-selected.
- Sweep tests for any other `ai_extraction_disabled` references and update.
- Finish with `lando phpcs-fix && lando phpcs` then `lando artisan test --parallel`.

## Out of scope

- Changing the App Settings AI section layout or the meaning of the global
  `enabled` flag beyond the reframing above.
- AI verification / arbitration / stock features (separate roadmap tasks).
- Per-URL (rather than per-store) provider overrides.

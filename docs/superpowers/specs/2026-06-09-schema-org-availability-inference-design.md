# Schema.org Availability Inference + Hide Match-Values UI — Design

**Date:** 2026-06-09
**Status:** Approved (pending implementation plan)

## 1. Problem

When a store's `scrape_strategy.availability.type` is `schema_org`, the scraped availability value is the canonical schema.org URL (e.g. `https://schema.org/InStock`). Today this is resolved via `StockStatus::matchFromScrapedValue($value, $matchConfig)` where `$matchConfig` is `scrape_strategy.availability.match`. With no match config that path returns **OutOfStock for any non-empty value** (a legacy "any availability text = out of stock" heuristic for selector strategies). Result: a schema.org `InStock` mis-resolves to **OutOfStock**, and the per-store "Match values" UI is redundant for schema_org because the value is self-describing.

Goal: make schema.org availability infer the correct `StockStatus` directly, and hide the now-unnecessary "Match values" / "Default status" fields when the availability type is `schema_org`.

## 2. Mapping — `StockStatus::fromSchemaOrgAvailability()`

Add `public static function fromSchemaOrgAvailability(?string $value): self` to `app/Enums/StockStatus.php`. It strips a URL to its label (`Str::afterLast(trim($value), '/')`) and maps the full schema.org [ItemAvailability](https://schema.org/ItemAvailability) enum onto the 6 `StockStatus` cases (InStock, PreOrder, BackOrder, SpecialOrder, OutOfStock, Discontinued):

| schema.org ItemAvailability label | StockStatus |
|---|---|
| `InStock`, `OnlineOnly`, `InStoreOnly`, `LimitedAvailability` | `InStock` |
| `OutOfStock`, `SoldOut`, `Reserved` | `OutOfStock` |
| `PreOrder`, `PreSale` | `PreOrder` |
| `BackOrder` | `BackOrder` |
| `MadeToOrder` | `SpecialOrder` |
| `Discontinued` | `Discontinued` |
| empty / null | `InStock` (matches the existing "no availability → in stock" convention) |
| anything else | `self::fromScrapedValue($label)` (handles enum-value strings; unknown → OutOfStock) |

Requires `use Illuminate\Support\Str;` in the enum. `SchemaOrgService::parseSchemaOrg` is left unchanged (keeps returning the canonical URL; normalization lives here).

## 3. Type-aware resolution — `StockStatus::resolveAvailability()`

Add `public static function resolveAvailability(?string $value, ?array $availabilityStrategy): self`:

```
type = data_get($availabilityStrategy, 'type')
if type === ScraperStrategyType::SchemaOrg->value:
    return fromSchemaOrgAvailability($value)        // ignores match config
return matchFromScrapedValue($value, data_get($availabilityStrategy, 'match'))
```

`matchFromScrapedValue` is unchanged and remains the non-schema_org path. Switch every store-availability resolution to `resolveAvailability($value, <…>.availability)` (passing the whole availability slot rather than just `.match`):

- `app/Services/ScrapeUrl.php:135` — `data_get($output, 'store.scrape_strategy.availability')`
- `app/Models/Url.php:187` and `:196` (createFromUrl) — `data_get($store, 'scrape_strategy.availability')`
- `app/Models/Url.php:251` (updatePrice) — `data_get($this->store, 'scrape_strategy.availability')`
- `app/Services/AiScrapeEnhancer.php:61` — `data_get($store, 'scrape_strategy.availability')`
- `app/Services/AiConfigHealer.php:131` — `data_get($store, 'scrape_strategy.availability')`
- `app/Rules/StoreUrl.php:45` — `data_get($scrape, 'store.scrape_strategy.availability')`
- `resources/views/filament/resources/store-resource/test-results.blade.php:24` — `data_get($record, 'scrape_strategy.availability')` (so the test modal shows the correct resolved status for schema_org)

`ScraperStrategyType` is imported where needed (the enum file gets it for `resolveAvailability`).

## 4. UI — hide match config for schema_org

In `app/Filament/Resources/StoreResource::form()`, the "Availability strategy" section contains a nested `Section::make('Match values')` and a `Select::make('availability.match.default')` ("Default status"). Add to **both**:

```php
->hidden(fn (Get $get): bool => $get('availability.type') === ScraperStrategyType::SchemaOrg->value)
```

The availability type select (from `HasScraperTrait::makeStrategyInput`) is already `->live()`, so the fields show/hide immediately on type change. Add `use App\Enums\ScraperStrategyType;` to `StoreResource` if absent.

## 5. Testing

- **`fromSchemaOrgAvailability` (unit):** every ItemAvailability value in both URL form (`https://schema.org/X`) and bare-label form (`X`) → expected StockStatus per the table; `null`/`''` → InStock; an unknown label → OutOfStock.
- **`resolveAvailability` (unit):** with `['type' => 'schema_org']` and **no match config**, `https://schema.org/InStock` → InStock (the bug fix) and `https://schema.org/OutOfStock` → OutOfStock; with a non-schema_org type it delegates to `matchFromScrapedValue` identically (a couple of parity cases).
- **Scrape flow (feature):** a store whose `scrape_strategy.availability.type = schema_org` with no match config, scraping a page whose JSON-LD offer is OutOfStock, reports OutOfStock (and InStock reports InStock). (Use the existing schema.org scrape test fixtures.)
- **Filament (feature):** the "Match values" section and "Default status" select are hidden when `availability.type` is `schema_org` and visible for other types.

## 6. Out of scope

- Changing `matchFromScrapedValue` or the selector/regex availability behaviour (unchanged).
- Adding new `StockStatus` cases (the 6 existing cases are the target set).
- Normalizing `SchemaOrgService::parseSchemaOrg` output (left returning the canonical URL).
- Migrating existing schema_org stores that have a stale `match` config — it is simply ignored now (a behaviour improvement); no data migration.

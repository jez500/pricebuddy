# Schema.org Availability Inference Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make schema.org availability resolve to the correct `StockStatus` on its own (mapping the full ItemAvailability enum), and hide the redundant "Match values" / "Default status" form fields when the availability strategy type is `schema_org`.

**Architecture:** Add `StockStatus::fromSchemaOrgAvailability()` (URL/label → StockStatus) and a type-aware `StockStatus::resolveAvailability($value, $availabilityStrategy)` that uses schema.org mapping for `schema_org` and falls back to the unchanged `matchFromScrapedValue` otherwise. Switch all store-availability resolution call sites to `resolveAvailability`. Hide the match-config UI for `schema_org`.

**Tech Stack:** Laravel 12, Filament 3, Pest 3. No DB migration. Spec: `docs/superpowers/specs/2026-06-09-schema-org-availability-inference-design.md`.

**Conventions:** Run via Lando: single test `lando artisan test --compact <path>`; style `lando phpcs-fix` then `lando phpcs` (reach Pint PASS + PHPStan `[OK] No errors`; NOT host `vendor/bin/pint`). Scrape tests fake HTTP with `Tests\Traits\ScraperTrait` / `Http::fake`.

---

## File Structure

**Modify:**
- `app/Enums/StockStatus.php` — add `fromSchemaOrgAvailability()` + `resolveAvailability()` (+ `use Illuminate\Support\Str;`).
- `app/Services/ScrapeUrl.php`, `app/Models/Url.php` (3 sites), `app/Services/AiScrapeEnhancer.php`, `app/Services/AiConfigHealer.php`, `app/Rules/StoreUrl.php`, `resources/views/filament/resources/store-resource/test-results.blade.php` — switch availability resolution to `resolveAvailability`.
- `app/Filament/Resources/StoreResource.php` — hide "Match values" + "Default status" for `schema_org`.

**Create (tests):**
- `tests/Unit/Enums/StockStatusSchemaOrgTest.php`
- `tests/Feature/Services/SchemaOrgAvailabilityTest.php`
- additions to `tests/Feature/Filament/StoreTest.php` (or a new Filament test).

`ScraperStrategyType` and `StockStatus` are both in `App\Enums`, so no cross-import is needed within the enum.

---

## Task 1: `StockStatus` schema.org mapping + type-aware resolver

**Files:**
- Modify: `app/Enums/StockStatus.php`
- Test: `tests/Unit/Enums/StockStatusSchemaOrgTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit\Enums;

use App\Enums\StockStatus;
use PHPUnit\Framework\TestCase;

class StockStatusSchemaOrgTest extends TestCase
{
    public function test_maps_item_availability_urls_to_stock_status(): void
    {
        $map = [
            'https://schema.org/InStock' => StockStatus::InStock,
            'https://schema.org/OnlineOnly' => StockStatus::InStock,
            'https://schema.org/InStoreOnly' => StockStatus::InStock,
            'https://schema.org/LimitedAvailability' => StockStatus::InStock,
            'https://schema.org/OutOfStock' => StockStatus::OutOfStock,
            'https://schema.org/SoldOut' => StockStatus::OutOfStock,
            'https://schema.org/Reserved' => StockStatus::OutOfStock,
            'https://schema.org/PreOrder' => StockStatus::PreOrder,
            'https://schema.org/PreSale' => StockStatus::PreOrder,
            'https://schema.org/BackOrder' => StockStatus::BackOrder,
            'https://schema.org/MadeToOrder' => StockStatus::SpecialOrder,
            'https://schema.org/Discontinued' => StockStatus::Discontinued,
        ];

        foreach ($map as $url => $expected) {
            $this->assertSame($expected, StockStatus::fromSchemaOrgAvailability($url), $url);
            // bare-label form maps the same
            $this->assertSame($expected, StockStatus::fromSchemaOrgAvailability(substr($url, strlen('https://schema.org/'))));
        }
    }

    public function test_empty_availability_is_in_stock_and_unknown_is_out_of_stock(): void
    {
        $this->assertSame(StockStatus::InStock, StockStatus::fromSchemaOrgAvailability(null));
        $this->assertSame(StockStatus::InStock, StockStatus::fromSchemaOrgAvailability(''));
        $this->assertSame(StockStatus::OutOfStock, StockStatus::fromSchemaOrgAvailability('https://schema.org/Nonsense'));
    }

    public function test_resolve_availability_uses_schema_org_mapping_and_ignores_match_config(): void
    {
        $strategy = ['type' => 'schema_org', 'value' => null];

        // The bug case: no match config, schema_org InStock must be InStock (was OutOfStock).
        $this->assertSame(StockStatus::InStock, StockStatus::resolveAvailability('https://schema.org/InStock', $strategy));
        $this->assertSame(StockStatus::OutOfStock, StockStatus::resolveAvailability('https://schema.org/OutOfStock', $strategy));

        // A stale match config is ignored for schema_org.
        $strategyWithMatch = ['type' => 'schema_org', 'match' => ['out_of_stock' => ['type' => 'match', 'value' => 'InStock']]];
        $this->assertSame(StockStatus::InStock, StockStatus::resolveAvailability('https://schema.org/InStock', $strategyWithMatch));
    }

    public function test_resolve_availability_delegates_to_match_for_non_schema_org(): void
    {
        $strategy = ['type' => 'selector', 'match' => ['out_of_stock' => ['type' => 'match', 'value' => 'Sold out']]];

        $this->assertSame(StockStatus::OutOfStock, StockStatus::resolveAvailability('Sold out', $strategy));
        $this->assertSame(StockStatus::InStock, StockStatus::resolveAvailability(null, $strategy));
        // Parity with matchFromScrapedValue for the same inputs.
        $this->assertSame(
            StockStatus::matchFromScrapedValue('Sold out', $strategy['match']),
            StockStatus::resolveAvailability('Sold out', $strategy),
        );
    }
}
```

- [ ] **Step 2: Run, verify FAIL**

Run: `lando artisan test --compact tests/Unit/Enums/StockStatusSchemaOrgTest.php`
Expected: FAIL — `Call to undefined method App\Enums\StockStatus::fromSchemaOrgAvailability()`.

- [ ] **Step 3: Implement the two methods**

Add `use Illuminate\Support\Str;` to the `use` block at the top of `app/Enums/StockStatus.php`.

Add these two methods (e.g. directly after the existing `matchFromScrapedValue()` method):

```php
    /**
     * Map a schema.org ItemAvailability value (URL or bare label) to a StockStatus.
     * See https://schema.org/ItemAvailability.
     */
    public static function fromSchemaOrgAvailability(?string $value): self
    {
        if ($value === null || $value === '') {
            return self::InStock;
        }

        $label = Str::afterLast(trim($value), '/');

        return match ($label) {
            'InStock', 'OnlineOnly', 'InStoreOnly', 'LimitedAvailability' => self::InStock,
            'OutOfStock', 'SoldOut', 'Reserved' => self::OutOfStock,
            'PreOrder', 'PreSale' => self::PreOrder,
            'BackOrder' => self::BackOrder,
            'MadeToOrder' => self::SpecialOrder,
            'Discontinued' => self::Discontinued,
            default => self::fromScrapedValue($label),
        };
    }

    /**
     * Resolve a scraped availability value against a store's availability strategy.
     * Schema.org strategies infer the status directly from the ItemAvailability value
     * (ignoring any match config); other strategies use the per-status match config.
     *
     * @param  array<string, mixed>|null  $availabilityStrategy  The scrape_strategy.availability slot.
     */
    public static function resolveAvailability(?string $value, ?array $availabilityStrategy): self
    {
        if (data_get($availabilityStrategy, 'type') === ScraperStrategyType::SchemaOrg->value) {
            return self::fromSchemaOrgAvailability($value);
        }

        return self::matchFromScrapedValue($value, data_get($availabilityStrategy, 'match'));
    }
```

(`ScraperStrategyType` is in the same `App\Enums` namespace, so it is referenced unqualified with no import.)

- [ ] **Step 4: Run, verify PASS**

Run: `lando artisan test --compact tests/Unit/Enums/StockStatusSchemaOrgTest.php`
Expected: PASS (4 tests).

- [ ] **Step 5: Run the existing StockStatus suite (no regression)**

Run: `lando artisan test --compact tests/Unit/Enums/StockStatusTest.php`
Expected: PASS.

- [ ] **Step 6: Style + commit**

```bash
lando phpcs-fix && lando phpcs
git add app/Enums/StockStatus.php tests/Unit/Enums/StockStatusSchemaOrgTest.php
git commit -m "feat: StockStatus schema.org availability mapping + type-aware resolveAvailability"
```

---

## Task 2: Switch availability resolution to `resolveAvailability`

**Files:**
- Modify: `app/Services/ScrapeUrl.php`, `app/Models/Url.php`, `app/Services/AiScrapeEnhancer.php`, `app/Services/AiConfigHealer.php`, `app/Rules/StoreUrl.php`, `resources/views/filament/resources/store-resource/test-results.blade.php`
- Test: `tests/Feature/Services/SchemaOrgAvailabilityTest.php`

- [ ] **Step 1: Write the failing integration test**

```php
<?php

namespace Tests\Feature\Services;

use App\Enums\StockStatus;
use App\Models\Store;
use App\Services\ScrapeUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SchemaOrgAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    private function jsonLdPage(string $availabilityLabel): string
    {
        $json = json_encode([
            '@context' => 'https://schema.org/',
            '@type' => 'Product',
            'name' => 'Widget',
            'offers' => [
                '@type' => 'Offer',
                'priceCurrency' => 'USD',
                'price' => '19.99',
                'availability' => 'https://schema.org/'.$availabilityLabel,
            ],
        ]);

        return "<html><head><script type=\"application/ld+json\">{$json}</script></head><body></body></html>";
    }

    private function schemaOrgStore(): Store
    {
        return Store::factory()->create([
            'domains' => [['domain' => 'example.com']],
            'scrape_strategy' => [
                'title' => ['type' => 'schema_org', 'value' => null],
                'price' => ['type' => 'schema_org', 'value' => null],
                'availability' => ['type' => 'schema_org', 'value' => null],
            ],
            'settings' => ['scraper_service' => 'http'],
        ]);
    }

    public function test_schema_org_out_of_stock_resolves_without_match_config(): void
    {
        $store = $this->schemaOrgStore();
        Http::fake(['*' => Http::response($this->jsonLdPage('OutOfStock'))]);

        $scrape = ScrapeUrl::new('https://example.com/p')->scrape();

        $this->assertSame(StockStatus::OutOfStock, StockStatus::resolveAvailability(
            data_get($scrape, 'availability'),
            data_get($store, 'scrape_strategy.availability'),
        ));
    }

    public function test_schema_org_in_stock_resolves_in_stock_without_match_config(): void
    {
        $store = $this->schemaOrgStore();
        Http::fake(['*' => Http::response($this->jsonLdPage('InStock'))]);

        $scrape = ScrapeUrl::new('https://example.com/p')->scrape();

        // Before the fix this resolved to OutOfStock via the null-match-config heuristic.
        $this->assertSame(StockStatus::InStock, StockStatus::resolveAvailability(
            data_get($scrape, 'availability'),
            data_get($store, 'scrape_strategy.availability'),
        ));
    }
}
```

- [ ] **Step 2: Run, verify PASS (it should already pass — Task 1 added resolveAvailability)**

Run: `lando artisan test --compact tests/Feature/Services/SchemaOrgAvailabilityTest.php`
Expected: PASS (2 tests). This test characterises the end-to-end behaviour and guards it. (It exercises `resolveAvailability` directly; the call-site edits below make the *internal* scrape/price pipeline use it too.)

- [ ] **Step 3: Update `app/Services/ScrapeUrl.php`**

Replace:
```php
        $matchConfig = data_get($output, 'store.scrape_strategy.availability.match');
        $isUnavailable = StockStatus::matchFromScrapedValue($output['availability'] ?? null, $matchConfig)->isUnavailable();
```
with:
```php
        $availabilityStrategy = data_get($output, 'store.scrape_strategy.availability');
        $isUnavailable = StockStatus::resolveAvailability($output['availability'] ?? null, $availabilityStrategy)->isUnavailable();
```

- [ ] **Step 4: Update `app/Models/Url.php` (three sites)**

Site A — `createFromUrl`, the first block. Replace:
```php
        $matchConfig = data_get($store, 'scrape_strategy.availability.match');
        $isUnavailable = StockStatus::matchFromScrapedValue(data_get($scrape, 'availability'), $matchConfig)->isUnavailable();
```
with:
```php
        $availabilityStrategy = data_get($store, 'scrape_strategy.availability');
        $isUnavailable = StockStatus::resolveAvailability(data_get($scrape, 'availability'), $availabilityStrategy)->isUnavailable();
```

Site B — `createFromUrl`, inside the AI-fallback `if` block (indented 12 spaces). Replace:
```php
            $matchConfig = data_get($store, 'scrape_strategy.availability.match');
            $isUnavailable = StockStatus::matchFromScrapedValue(data_get($scrape, 'availability'), $matchConfig)->isUnavailable();
```
with:
```php
            $availabilityStrategy = data_get($store, 'scrape_strategy.availability');
            $isUnavailable = StockStatus::resolveAvailability(data_get($scrape, 'availability'), $availabilityStrategy)->isUnavailable();
```

Site C — `updatePrice`. Replace:
```php
            $matchConfig = data_get($this->store, 'scrape_strategy.availability.match');
            $stockStatus = StockStatus::matchFromScrapedValue($scrapedValue, $matchConfig);
```
with:
```php
            $availabilityStrategy = data_get($this->store, 'scrape_strategy.availability');
            $stockStatus = StockStatus::resolveAvailability($scrapedValue, $availabilityStrategy);
```

- [ ] **Step 5: Update `app/Services/AiScrapeEnhancer.php`**

Replace:
```php
        $matchConfig = data_get($store, 'scrape_strategy.availability.match');
        $isUnavailable = StockStatus::matchFromScrapedValue(data_get($scrapeResult, 'availability'), $matchConfig)
            ->isUnavailable();
```
with:
```php
        $availabilityStrategy = data_get($store, 'scrape_strategy.availability');
        $isUnavailable = StockStatus::resolveAvailability(data_get($scrapeResult, 'availability'), $availabilityStrategy)
            ->isUnavailable();
```

- [ ] **Step 6: Update `app/Services/AiConfigHealer.php`**

Replace:
```php
        $matchConfig = data_get($store, 'scrape_strategy.availability.match');
        $isUnavailable = StockStatus::matchFromScrapedValue(data_get($scrapeResult, 'availability'), $matchConfig)
            ->isUnavailable();
```
with:
```php
        $availabilityStrategy = data_get($store, 'scrape_strategy.availability');
        $isUnavailable = StockStatus::resolveAvailability(data_get($scrapeResult, 'availability'), $availabilityStrategy)
            ->isUnavailable();
```

- [ ] **Step 7: Update `app/Rules/StoreUrl.php`**

Replace:
```php
            $matchConfig = data_get($scrape, 'store.scrape_strategy.availability.match');
            $isUnavailable = StockStatus::matchFromScrapedValue($scrape['availability'] ?? null, $matchConfig)->isUnavailable();
```
with:
```php
            $availabilityStrategy = data_get($scrape, 'store.scrape_strategy.availability');
            $isUnavailable = StockStatus::resolveAvailability($scrape['availability'] ?? null, $availabilityStrategy)->isUnavailable();
```

- [ ] **Step 8: Update `resources/views/filament/resources/store-resource/test-results.blade.php`**

Replace:
```php
            $availabilityVal = data_get($scrape, 'availability');
            $matchConfig = data_get($record, 'scrape_strategy.availability.match');
            $resolvedStatus = \App\Enums\StockStatus::matchFromScrapedValue($availabilityVal, $matchConfig);
```
with:
```php
            $availabilityVal = data_get($scrape, 'availability');
            $availabilityStrategy = data_get($record, 'scrape_strategy.availability');
            $resolvedStatus = \App\Enums\StockStatus::resolveAvailability($availabilityVal, $availabilityStrategy);
```

(The `$matchConfig` variable lower in the blade — used to render the "matched rule" hint — still reads `data_get($record, 'scrape_strategy.availability.match')` on its own line; leave that line unchanged. Only the `$resolvedStatus` computation changes.)

- [ ] **Step 9: Confirm no stray `matchFromScrapedValue` store-availability call sites remain**

Run: `lando ssh -c "grep -rn 'matchFromScrapedValue' app resources"`
Expected: the only remaining reference is the method definition in `app/Enums/StockStatus.php` (and `resolveAvailability` calling it). No call site should still derive `availability.match` for resolution except the blade's separate "matched rule" hint line.

- [ ] **Step 10: Run the integration test + availability/scrape regression**

Run: `lando artisan test --compact tests/Feature/Services/SchemaOrgAvailabilityTest.php tests/Feature/Models/UrlTest.php tests/Unit/Services/ScrapeUrlTest.php tests/Feature/Services/AiScrapeEnhancerTest.php tests/Feature/Services/AiConfigHealerTest.php`
Expected: PASS (existing availability behaviour for selector/regex stores is unchanged; schema_org now resolves correctly).

- [ ] **Step 11: Style + commit**

```bash
lando phpcs-fix && lando phpcs
git add app/Services/ScrapeUrl.php app/Models/Url.php app/Services/AiScrapeEnhancer.php app/Services/AiConfigHealer.php app/Rules/StoreUrl.php resources/views/filament/resources/store-resource/test-results.blade.php tests/Feature/Services/SchemaOrgAvailabilityTest.php
git commit -m "feat: resolve store availability via type-aware resolveAvailability"
```

---

## Task 3: Hide match-config UI for schema_org

**Files:**
- Modify: `app/Filament/Resources/StoreResource.php`
- Test: `tests/Feature/Filament/StoreAvailabilitySchemaOrgTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\StoreResource\Pages\EditStore;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class StoreAvailabilitySchemaOrgTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['email' => 'test@test.com']));
    }

    public function test_match_values_hidden_for_schema_org_and_shown_for_selector(): void
    {
        $store = Store::factory()->create([
            'scrape_strategy' => ['availability' => ['type' => 'selector', 'value' => '.stock']],
        ]);

        Livewire::test(EditStore::class, ['record' => $store->getKey()])
            ->assertSee('Match values')
            ->assertSee('Default status')
            ->set('data.scrape_strategy.availability.type', 'schema_org')
            ->assertDontSee('Match values')
            ->assertDontSee('Default status');
    }
}
```

- [ ] **Step 2: Run, verify FAIL**

Run: `lando artisan test --compact tests/Feature/Filament/StoreAvailabilitySchemaOrgTest.php`
Expected: FAIL — "Match values"/"Default status" still visible after switching to `schema_org`.

- [ ] **Step 3: Add the import + the `hidden()` conditions**

In `app/Filament/Resources/StoreResource.php`, add `use App\Enums\ScraperStrategyType;` with the other `use App\Enums\...` imports.

In `form()`, the "Availability strategy" section has a nested `Section::make('Match values')` and a `Forms\Components\Select::make('availability.match.default')`. Add this modifier to the `Section::make('Match values')` (it currently ends with `->collapsed(fn (Get $get): bool => ...)` — append after that):

```php
                            ->hidden(fn (Get $get): bool => $get('availability.type') === ScraperStrategyType::SchemaOrg->value)
```

And add the same modifier to the `Select::make('availability.match.default')` (after its existing `->hintIcon(...)`):

```php
                            ->hidden(fn (Get $get): bool => $get('availability.type') === ScraperStrategyType::SchemaOrg->value)
```

- [ ] **Step 4: Run, verify PASS**

Run: `lando artisan test --compact tests/Feature/Filament/StoreAvailabilitySchemaOrgTest.php`
Expected: PASS.

- [ ] **Step 5: Regression — store form/test modal unaffected**

Run: `lando artisan test --compact tests/Feature/Filament/StoreTest.php tests/Feature/Filament/StoreTestModalTest.php tests/Feature/Filament/StoreSelfHealUiTest.php`
Expected: PASS.

- [ ] **Step 6: Style + commit**

```bash
lando phpcs-fix && lando phpcs
git add app/Filament/Resources/StoreResource.php tests/Feature/Filament/StoreAvailabilitySchemaOrgTest.php
git commit -m "feat: hide availability match config when type is schema.org"
```

---

## Task 4: Final verification

**Files:** none (verification only)

- [ ] **Step 1: Style + static analysis**

Run: `lando phpcs-fix && lando phpcs`
Expected: Pint PASS, then PHPStan `[OK] No errors`.

- [ ] **Step 2: Full suite in parallel**

Run: `lando artisan test --parallel`
Expected: all green.

- [ ] **Step 3: Commit any style fixes**

```bash
git add -A && git commit -m "style: phpcs fixes for schema.org availability inference" || echo "nothing to commit"
```

---

## Self-Review

**Spec coverage:**
- §2 `fromSchemaOrgAvailability` mapping (full ItemAvailability table, URL+label, empty→InStock, unknown→OutOfStock) → Task 1. ✓
- §3 `resolveAvailability` type-aware + all 8 call sites switched → Tasks 1 (method) + 2 (sites incl. blade). ✓
- §4 hide Match values + Default status for schema_org → Task 3. ✓
- §5 tests (fromSchemaOrgAvailability unit, resolveAvailability unit incl. bug-fix + parity, scrape-flow feature, Filament visibility) → Tasks 1–3. ✓
- §6 out of scope respected (matchFromScrapedValue/selector behaviour, parseSchemaOrg, no new StockStatus cases, no data migration). ✓

**Placeholder scan:** none — every step has complete code and exact commands.

**Type consistency:** `fromSchemaOrgAvailability(?string): self`, `resolveAvailability(?string, ?array): self`, and the `data_get(<source>, '<path>.availability')` argument shape are consistent across Tasks 1–2; the Filament `hidden()` predicate `$get('availability.type') === ScraperStrategyType::SchemaOrg->value` matches the live type field. The blade's separate `$matchConfig` "matched rule" line is explicitly left unchanged.

# Per-store AI extraction Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move AI price-extraction control from a global master switch + per-product opt-out to a single per-store setting (with an optional per-store provider choice) surfaced in the store edit page's "Scraper service" section.

**Architecture:** App Settings keeps holding provider configs + the default provider (its `enabled` toggle now just means "AI is configured"). Each store opts in via `settings.ai_extraction_enabled` and optionally picks `settings.ai_provider_id`. At scrape time `AiScrapeEnhancer` gates on the URL's store and resolves the provider (store choice → global default), threading it through `AiExtractionService::extract()` → `AiService::structured()`. The per-product `ai_extraction_disabled` field is removed entirely.

**Tech Stack:** Laravel 12, Filament 3, Pest/PHPUnit, Lando (run all artisan/composer/test via `lando`).

**Spec:** `docs/superpowers/specs/2026-06-05-per-store-ai-extraction-design.md`

---

## File structure

- `app/Services/Helpers/IntegrationHelper.php` — add `getAiProvider(?string $id)` resolver.
- `app/Services/AiService.php` — `structured()` accepts an optional explicit provider.
- `app/Services/AiExtractionService.php` — `extract()` accepts an optional explicit provider; resolves provider instead of gating on `isEnabled()`.
- `app/Models/Store.php` — `aiExtractionEnabled` + `aiProviderId` accessors.
- `app/Services/AiScrapeEnhancer.php` — per-store gating + provider threading.
- `app/Filament/Concerns/HasScraperTrait.php` — AI toggle + conditional provider select in the Scraper service section.
- `app/Models/Product.php` + new migration + `app/Filament/Resources/ProductResource.php` — remove the per-product opt-out.
- Tests across `tests/Feature/Services`, `tests/Feature/Models`, `tests/Unit/Services`, `tests/Feature/Filament`.

---

## Task 1: Provider resolution helper

**Files:**
- Modify: `app/Services/Helpers/IntegrationHelper.php` (after `getActiveAiProvider()`, ~line 71)
- Test: `tests/Unit/Services/IntegrationHelperTest.php`

- [ ] **Step 1: Write the failing test**

Append these methods to `tests/Unit/Services/IntegrationHelperTest.php` (inside the test class):

```php
    public function test_get_ai_provider_matches_by_id(): void
    {
        SettingsHelper::setSetting('integrated_services', ['ai' => [
            'enabled' => true, 'default_provider_id' => 'p1',
            'providers' => [
                ['id' => 'p1', 'name' => 'A', 'type' => 'ollama', 'model' => 'm'],
                ['id' => 'p2', 'name' => 'B', 'type' => 'ollama', 'model' => 'm'],
            ],
        ]]);

        $this->assertSame('p2', IntegrationHelper::getAiProvider('p2')?->id);
    }

    public function test_get_ai_provider_falls_back_to_default_when_blank(): void
    {
        SettingsHelper::setSetting('integrated_services', ['ai' => [
            'enabled' => true, 'default_provider_id' => 'p1',
            'providers' => [['id' => 'p1', 'name' => 'A', 'type' => 'ollama', 'model' => 'm']],
        ]]);

        $this->assertSame('p1', IntegrationHelper::getAiProvider(null)?->id);
    }

    public function test_get_ai_provider_falls_back_to_default_when_id_unknown(): void
    {
        SettingsHelper::setSetting('integrated_services', ['ai' => [
            'enabled' => true, 'default_provider_id' => 'p1',
            'providers' => [['id' => 'p1', 'name' => 'A', 'type' => 'ollama', 'model' => 'm']],
        ]]);

        $this->assertSame('p1', IntegrationHelper::getAiProvider('gone')?->id);
    }
```

Confirm `IntegrationHelperTest` already imports `App\Services\Helpers\SettingsHelper` and `App\Services\Helpers\IntegrationHelper`; if not, add the `use` statements. Check how existing tests in this file reset settings between cases (e.g. a `setUp()` clearing `SettingsHelper::$settings`) and follow the same pattern — do not invent a new reset mechanism.

- [ ] **Step 2: Run test to verify it fails**

Run: `lando artisan test --compact --filter=get_ai_provider`
Expected: FAIL with "Call to undefined method App\Services\Helpers\IntegrationHelper::getAiProvider()".

- [ ] **Step 3: Add the resolver**

In `app/Services/Helpers/IntegrationHelper.php`, add after `getActiveAiProvider()`:

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

`AiProviderConfigDto` is already imported at the top of this file.

- [ ] **Step 4: Run test to verify it passes**

Run: `lando artisan test --compact --filter=get_ai_provider`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Helpers/IntegrationHelper.php tests/Unit/Services/IntegrationHelperTest.php
git commit -m "feat: add IntegrationHelper::getAiProvider resolver with default fallback"
```

---

## Task 2: Thread an explicit provider through AiService + AiExtractionService

**Files:**
- Modify: `app/Services/AiService.php:34-47` (`structured()`)
- Modify: `app/Services/AiExtractionService.php:43-75` (`extract()`)
- Test: `tests/Feature/Services/AiExtractionServiceTest.php`

- [ ] **Step 1: Update the extraction-service tests to pass an explicit provider**

The existing `AiExtractionServiceTest` mocks `AiService::isEnabled()`. After this task `extract()` no longer calls `isEnabled()`; it resolves a provider instead. Update the file so the "happy path" tests pass an explicit provider DTO and drop the `isEnabled` stubs.

Add this import near the top of `tests/Feature/Services/AiExtractionServiceTest.php`:

```php
use App\Dto\AiProviderConfigDto;
use App\Enums\AiProvider;
```

Add this private helper inside the test class:

```php
    private function provider(): AiProviderConfigDto
    {
        return new AiProviderConfigDto(id: 'p1', name: 'Test', type: AiProvider::Ollama, model: 'm');
    }
```

Rewrite the test bodies as follows.

`test_returns_null_when_ai_is_disabled` — no provider configured and none passed → null:

```php
    public function test_returns_null_when_ai_is_disabled(): void
    {
        $this->mock(AiService::class, function ($mock) {
            $mock->shouldReceive('structured')->never();
        });

        $result = AiExtractionService::new()->extract('<html><body>Widget $12</body></html>');

        $this->assertNull($result);
    }
```

`test_maps_a_structured_ai_result_to_a_dto`:

```php
    public function test_maps_a_structured_ai_result_to_a_dto(): void
    {
        $this->mock(AiService::class, function ($mock) {
            $mock->shouldReceive('structured')->once()->andReturn([
                'name' => 'Widget',
                'price' => '12.99',
                'currency' => 'USD',
                'imageUrl' => 'https://example.com/w.jpg',
                'stockStatus' => 'https://schema.org/InStock',
                'confidence' => 0.82,
            ]);
        });

        $result = AiExtractionService::new()->extract('<html><body>Widget $12.99</body></html>', provider: $this->provider());

        $this->assertInstanceOf(AiExtractionResultDto::class, $result);
        $this->assertSame('Widget', $result->title);
        $this->assertSame(12.99, $result->price);
        $this->assertSame('USD', $result->currency);
        $this->assertSame('https://example.com/w.jpg', $result->image);
        $this->assertSame(StockStatus::InStock, $result->stockStatus);
        $this->assertSame(0.82, $result->confidence);
    }
```

`test_returns_null_when_the_ai_result_is_empty`:

```php
    public function test_returns_null_when_the_ai_result_is_empty(): void
    {
        $this->mock(AiService::class, function ($mock) {
            $mock->shouldReceive('structured')->once()->andReturnNull();
        });

        $result = AiExtractionService::new()->extract('<html></html>', provider: $this->provider());

        $this->assertNull($result);
    }
```

`test_maps_schema_org_out_of_stock_url_to_out_of_stock_status` — drop the `isEnabled` stub and add `provider:`:

```php
        $result = AiExtractionService::new()->extract('<html><body>Widget</body></html>', provider: $this->provider());
```
(and remove the `$mock->shouldReceive('isEnabled')->andReturnTrue();` line)

`test_maps_schema_org_pre_order_url_to_pre_order_status` — same two edits as above.

Leave `test_preprocesses_html_...` and `test_prepare_html_prepends_schema_org_...` unchanged (they call `prepareHtml()` directly).

- [ ] **Step 2: Run tests to verify they fail**

Run: `lando artisan test --compact --filter=AiExtractionServiceTest`
Expected: FAIL — `extract()` does not yet accept a `provider` argument / still gates on `isEnabled()`.

- [ ] **Step 3: Update `AiService::structured()`**

Replace the body of `structured()` in `app/Services/AiService.php` (currently lines 34-47):

```php
    public function structured(string $instructions, Closure $schema, string $prompt, ?AiProviderConfigDto $provider = null): ?array
    {
        $provider ??= IntegrationHelper::getActiveAiProvider();

        if ($provider === null) {
            return null;
        }

        return $this->runStructuredFor($provider, $instructions, $schema, $prompt);
    }
```

Also update its PHPDoc to document the new param:

```php
    /**
     * Run a structured prompt through the given provider, or the active default.
     *
     * @param  Closure(JsonSchema): array<string, mixed>  $schema
     * @return array<string, mixed>|null
     */
```

`AiProviderConfigDto` and `IntegrationHelper` are already imported in this file. Leave the `isEnabled()` method itself in place (still used by `testConnection()`).

- [ ] **Step 4: Update `AiExtractionService::extract()`**

In `app/Services/AiExtractionService.php`:

Add imports near the existing `use` block:

```php
use App\Dto\AiProviderConfigDto;
use App\Services\Helpers\IntegrationHelper;
```

Replace the method signature, the `isEnabled()` guard, and the `structured()` call (currently lines 43-61):

```php
    /**
     * @param  Collection<int, mixed>|null  $schemaOrg
     */
    public function extract(string $html, ?Collection $schemaOrg = null, ?AiProviderConfigDto $provider = null): ?AiExtractionResultDto
    {
        $provider ??= IntegrationHelper::getActiveAiProvider();

        if ($provider === null) {
            return null;
        }

        $result = $this->ai->structured(
            self::EXTRACTION_PROMPT,
            fn (JsonSchema $schema) => [
                'name' => $schema->string(),
                'price' => $schema->number(),
                'currency' => $schema->string(),
                'imageUrl' => $schema->string(),
                'stockStatus' => $schema->string(),
                'confidence' => $schema->number()->min(0)->max(1)->required(),
            ],
            $this->prepareHtml($html, $schemaOrg),
            $provider,
        );
```

Leave everything from `if (blank($result)) {` onward unchanged.

- [ ] **Step 5: Run tests to verify they pass**

Run: `lando artisan test --compact --filter=AiExtractionServiceTest`
Expected: PASS.

Run: `lando artisan test --compact --filter=AiServiceTest`
Expected: PASS (the disabled/gone-provider cases still return null via `getActiveAiProvider()`).

- [ ] **Step 6: Commit**

```bash
git add app/Services/AiService.php app/Services/AiExtractionService.php tests/Feature/Services/AiExtractionServiceTest.php
git commit -m "feat: thread explicit AI provider through extraction services"
```

---

## Task 3: Store model accessors

**Files:**
- Modify: `app/Models/Store.php` (add accessors after `scraperService()` ~line 174; add `@property` lines to class docblock)
- Test: `tests/Feature/Models/StoreTest.php`

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/Models/StoreTest.php` (ensure `use App\Models\Store;` is present):

```php
    public function test_ai_extraction_accessors_read_from_settings(): void
    {
        $store = Store::factory()->create([
            'settings' => [
                'scraper_service' => 'http',
                'ai_extraction_enabled' => true,
                'ai_provider_id' => 'p2',
            ],
        ]);

        $this->assertTrue($store->ai_extraction_enabled);
        $this->assertSame('p2', $store->ai_provider_id);
    }

    public function test_ai_extraction_enabled_defaults_to_false(): void
    {
        $store = Store::factory()->create([
            'settings' => ['scraper_service' => 'http'],
        ]);

        $this->assertFalse($store->ai_extraction_enabled);
        $this->assertNull($store->ai_provider_id);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `lando artisan test --compact --filter="ai_extraction_accessors_read_from_settings|ai_extraction_enabled_defaults_to_false"`
Expected: FAIL (accessors return null / property undefined).

- [ ] **Step 3: Add the accessors**

In `app/Models/Store.php`, add after the `scraperService()` accessor:

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

Add to the class-level PHPDoc `@property` block (near the existing `@property string $scraper_service`):

```php
 * @property bool $ai_extraction_enabled
 * @property string|null $ai_provider_id
```

- [ ] **Step 4: Run test to verify it passes**

Run: `lando artisan test --compact --filter="ai_extraction_accessors_read_from_settings|ai_extraction_enabled_defaults_to_false"`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Models/Store.php tests/Feature/Models/StoreTest.php
git commit -m "feat: add per-store AI extraction accessors"
```

---

## Task 4: Per-store gating in the scrape pipeline

**Files:**
- Modify: `app/Services/AiScrapeEnhancer.php` (`enhance()`)
- Test: `tests/Feature/Services/AiScrapeEnhancerTest.php`
- Test: `tests/Feature/Models/UrlUpdatePriceTest.php`

- [ ] **Step 1: Rewrite `AiScrapeEnhancerTest` for per-store gating**

Replace the whole of `tests/Feature/Services/AiScrapeEnhancerTest.php` with:

```php
<?php

namespace Tests\Feature\Services;

use App\Dto\AiExtractionResultDto;
use App\Models\Store;
use App\Models\Url;
use App\Services\AiExtractionService;
use App\Services\AiScrapeEnhancer;
use App\Services\Helpers\SettingsHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Once;
use Tests\TestCase;

class AiScrapeEnhancerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SettingsHelper::$settings = null;
        Cache::flush();
        Once::flush();
    }

    /**
     * Register one usable provider in app settings so the store's
     * ai_provider_id (or the global default) resolves to something.
     */
    private function configureProviders(): void
    {
        SettingsHelper::setSetting('integrated_services', ['ai' => [
            'enabled' => true,
            'default_provider_id' => 'p1',
            'providers' => [[
                'id' => 'p1', 'name' => 'Local', 'type' => 'ollama',
                'base_url' => 'http://ai.example:11434', 'model' => 'm',
            ]],
        ]]);
        SettingsHelper::$settings = null;
        Cache::flush();
        Once::flush();
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function url(array $settings = ['ai_extraction_enabled' => true]): Url
    {
        $store = Store::factory()->create([
            'settings' => array_merge(['scraper_service' => 'http'], $settings),
        ]);

        return Url::factory()->for($store)->create();
    }

    public function test_leaves_result_untouched_when_a_price_is_present(): void
    {
        $this->configureProviders();
        $this->mock(AiExtractionService::class, fn ($m) => $m->shouldReceive('extract')->never());

        $result = AiScrapeEnhancer::new()->enhance($this->url(), ['price' => '12.99', 'body' => '<html>']);

        $this->assertSame('12.99', $result['price']);
    }

    public function test_does_nothing_when_store_ai_extraction_disabled(): void
    {
        $this->configureProviders();
        $this->mock(AiExtractionService::class, fn ($m) => $m->shouldReceive('extract')->never());

        $result = AiScrapeEnhancer::new()->enhance(
            $this->url(['ai_extraction_enabled' => false]),
            ['price' => null, 'body' => '<html>9.99</html>'],
        );

        $this->assertNull($result['price']);
    }

    public function test_does_nothing_when_no_provider_is_configured(): void
    {
        // Store opts in, but no providers exist in app settings.
        $this->mock(AiExtractionService::class, fn ($m) => $m->shouldReceive('extract')->never());

        $result = AiScrapeEnhancer::new()->enhance($this->url(), ['price' => null, 'body' => '<html>9.99</html>']);

        $this->assertNull($result['price']);
    }

    public function test_does_nothing_when_item_is_unavailable(): void
    {
        $this->configureProviders();
        $this->mock(AiExtractionService::class, fn ($m) => $m->shouldReceive('extract')->never());

        $result = AiScrapeEnhancer::new()->enhance(
            $this->url(),
            ['price' => null, 'body' => '<html>', 'availability' => 'Sold out'],
        );

        $this->assertNull($result['price']);
    }

    public function test_does_nothing_when_confidence_below_threshold(): void
    {
        $this->configureProviders();
        $this->mock(AiExtractionService::class, fn ($m) => $m->shouldReceive('extract')
            ->once()->andReturn(new AiExtractionResultDto(price: 9.99, confidence: 0.4)));
        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('withContext')->andReturnSelf();
        Log::shouldReceive('debug');

        $result = AiScrapeEnhancer::new()->enhance($this->url(), ['price' => null, 'body' => '<html>9.99</html>']);

        $this->assertNull($result['price']);
    }

    public function test_backfills_price_for_a_confident_result(): void
    {
        $this->configureProviders();
        $this->mock(AiExtractionService::class, fn ($m) => $m->shouldReceive('extract')
            ->once()->andReturn(new AiExtractionResultDto(price: 9.99, confidence: 0.82)));
        Log::shouldReceive('channel')->with('db')->andReturnSelf();
        Log::shouldReceive('withContext')->andReturnSelf();
        Log::shouldReceive('info')->once();

        $result = AiScrapeEnhancer::new()->enhance($this->url(), ['price' => null, 'body' => '<html>9.99</html>']);

        $this->assertSame(9.99, $result['price']);
    }

    public function test_does_nothing_when_extract_returns_null(): void
    {
        $this->configureProviders();
        $this->mock(AiExtractionService::class, fn ($m) => $m->shouldReceive('extract')
            ->once()->andReturnNull());
        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('withContext')->andReturnSelf();
        Log::shouldReceive('debug');

        $result = AiScrapeEnhancer::new()->enhance($this->url(), ['price' => null, 'body' => '<html>9.99</html>']);

        $this->assertNull($result['price']);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `lando artisan test --compact --filter=AiScrapeEnhancerTest`
Expected: FAIL — `enhance()` still checks `isAiEnabled()` / product opt-out, so e.g. `test_does_nothing_when_no_provider_is_configured` and the per-store toggle cases behave wrongly.

- [ ] **Step 3: Rewrite the guards in `enhance()`**

In `app/Services/AiScrapeEnhancer.php`, replace the two guards (the `isAiEnabled()` check and the `$url->product?->ai_extraction_disabled` check) and the availability/extract section so the method reads:

```php
    public function enhance(Url $url, array $scrapeResult): array
    {
        // Only fill a genuine gap. The scraped price is a raw string here, so filled()
        // treats null/'' as a gap and any non-empty value as already-present.
        if (filled(data_get($scrapeResult, 'price'))) {
            return $scrapeResult;
        }

        $store = $url->store;

        // Enablement is per-store; a product inherits it from the store of each URL.
        if (! $store?->ai_extraction_enabled) {
            return $scrapeResult;
        }

        // Resolve the store's chosen provider, falling back to the global default.
        $provider = IntegrationHelper::getAiProvider($store->ai_provider_id);

        if ($provider === null) {
            return $scrapeResult;
        }

        $html = data_get($scrapeResult, 'body');

        if (blank($html)) {
            return $scrapeResult;
        }

        // An out-of-stock item has no purchasable price; don't spend tokens.
        $matchConfig = data_get($store, 'scrape_strategy.availability.match');
        $isUnavailable = StockStatus::matchFromScrapedValue(data_get($scrapeResult, 'availability'), $matchConfig)
            ->isUnavailable();

        if ($isUnavailable) {
            return $scrapeResult;
        }

        $result = $this->extraction->extract($html, provider: $provider);
```

Leave everything from `if ($result === null ...` onward unchanged. `IntegrationHelper` is already imported in this file.

- [ ] **Step 4: Run tests to verify they pass**

Run: `lando artisan test --compact --filter=AiScrapeEnhancerTest`
Expected: PASS (7 tests).

- [ ] **Step 5: Update `UrlUpdatePriceTest`**

In `tests/Feature/Models/UrlUpdatePriceTest.php`:

Add the import:

```php
use App\Models\Store;
```

Rename `enableAi()` to `configureProviders()` (same body — it already only sets app-settings providers). Then make the two tests create a store and opt in / out.

`test_ai_backfills_price_when_scrape_finds_none`:

```php
    public function test_ai_backfills_price_when_scrape_finds_none(): void
    {
        $this->configureProviders();
        $this->mock(AiExtractionService::class, fn ($m) => $m->shouldReceive('extract')
            ->once()->andReturn(new AiExtractionResultDto(price: 9.99, confidence: 0.9)));
        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('withContext')->andReturnSelf();
        Log::shouldReceive('info');
        Log::shouldReceive('debug');

        $store = Store::factory()->create([
            'settings' => ['scraper_service' => 'http', 'ai_extraction_enabled' => true],
        ]);
        $url = Url::factory()->for(Product::factory())->for($store)->create();

        $price = $url->updatePrice(null, ['price' => null, 'body' => '<html>9.99</html>', 'availability' => null]);

        $this->assertInstanceOf(Price::class, $price);
        $this->assertSame(9.99, (float) $price->price);
    }
```

`test_no_price_recorded_when_ai_disabled_and_scrape_finds_none` — store does not opt in (default factory settings have no `ai_extraction_enabled`), so AI never runs:

```php
    public function test_no_price_recorded_when_ai_disabled_and_scrape_finds_none(): void
    {
        $this->mock(AiExtractionService::class, fn ($m) => $m->shouldReceive('extract')->never());

        $url = Url::factory()->for(Product::factory())->create();

        $price = $url->updatePrice(null, ['price' => null, 'body' => '<html>9.99</html>', 'availability' => null]);

        $this->assertNull($price);
        $this->assertDatabaseCount('prices', 0);
    }
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `lando artisan test --compact --filter=UrlUpdatePriceTest`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Services/AiScrapeEnhancer.php tests/Feature/Services/AiScrapeEnhancerTest.php tests/Feature/Models/UrlUpdatePriceTest.php
git commit -m "feat: gate AI extraction per-store and resolve provider from store"
```

---

## Task 5: Store edit page — AI toggle + conditional provider select

**Files:**
- Modify: `app/Filament/Concerns/HasScraperTrait.php` (`getScraperSettings()`)
- Test: `tests/Feature/Filament/StoreTest.php`

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/Filament/StoreTest.php`. Add imports at the top:

```php
use App\Filament\Resources\StoreResource\Pages\CreateStore;
use App\Services\Helpers\SettingsHelper;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Once;
```

Add a helper inside the class:

```php
    private function configureAi(): void
    {
        SettingsHelper::setSetting('integrated_services', ['ai' => [
            'enabled' => true,
            'default_provider_id' => 'p1',
            'providers' => [['id' => 'p1', 'name' => 'Local', 'type' => 'ollama', 'model' => 'm']],
        ]]);
        SettingsHelper::$settings = null;
        Cache::flush();
        Once::flush();
    }
```

Add the tests:

```php
    public function test_store_form_shows_ai_extraction_toggle_when_ai_configured(): void
    {
        $this->configureAi();
        $this->actingAs($this->user);

        Livewire::test(CreateStore::class)
            ->assertFormFieldExists('settings.ai_extraction_enabled');
    }

    public function test_store_form_defaults_ai_provider_to_global_default(): void
    {
        $this->configureAi();
        $this->actingAs($this->user);

        Livewire::test(CreateStore::class)
            ->assertFormSet(['settings' => ['ai_provider_id' => 'p1']]);
    }
```

Note: if `assertFormSet` with a nested array proves brittle in this Filament version, assert with a closure instead: `->assertFormSet(fn (array $state): bool => data_get($state, 'settings.ai_provider_id') === 'p1')`. Check a sibling Filament test for the form-state assertion style already used in this codebase and match it.

- [ ] **Step 2: Run test to verify it fails**

Run: `lando artisan test --compact --filter="ai_extraction_toggle_when_ai_configured|ai_provider_to_global_default"`
Expected: FAIL — field `settings.ai_extraction_enabled` does not exist.

- [ ] **Step 3: Add the fields to the Scraper service section**

In `app/Filament/Concerns/HasScraperTrait.php`:

Add imports:

```php
use App\Services\Helpers\IntegrationHelper;
use Filament\Forms\Components\Toggle;
```

In `getScraperSettings()`, add these two components to the section schema after the existing `Textarea::make('settings.scraper_service_settings')` block (still inside the `->schema([ ... ])` array):

```php
            Toggle::make('settings.ai_extraction_enabled')
                ->label('Enable AI price extraction')
                ->helperText('Use AI to recover a price when the normal scrape finds none.')
                ->reactive()
                ->hidden(fn (): bool => ! IntegrationHelper::isAiEnabled())
                ->columnSpanFull(),

            Select::make('settings.ai_provider_id')
                ->label('AI provider')
                ->options(fn (): array => collect(IntegrationHelper::getAiProviders())
                    ->mapWithKeys(fn ($provider): array => [$provider->id => $provider->name])
                    ->all())
                ->default(fn (): ?string => IntegrationHelper::getActiveAiProvider()?->id)
                ->visible(fn (Get $get): bool => (bool) $get('settings.ai_extraction_enabled')),
```

`Select` and `Get` are already imported in this trait.

- [ ] **Step 4: Run test to verify it passes**

Run: `lando artisan test --compact --filter="ai_extraction_toggle_when_ai_configured|ai_provider_to_global_default"`
Expected: PASS.

- [ ] **Step 5: Run the full Filament store suite**

Run: `lando artisan test --compact --filter=Filament\\StoreTest`
Expected: PASS (existing edit/index tests still green).

- [ ] **Step 6: Commit**

```bash
git add app/Filament/Concerns/HasScraperTrait.php tests/Feature/Filament/StoreTest.php
git commit -m "feat: add per-store AI extraction toggle and provider select to store form"
```

---

## Task 6: Remove the per-product opt-out

**Files:**
- Create: `database/migrations/<timestamp>_drop_ai_extraction_disabled_from_products_table.php`
- Modify: `app/Models/Product.php` (docblock ~line 43, `$attributes` ~lines 69-71, `$casts` ~line 80)
- Modify: `app/Filament/Resources/ProductResource.php` (remove toggle ~lines 217-221; remove now-unused `IntegrationHelper` import if unused)
- Test: sweep `tests/` for `ai_extraction_disabled`

- [ ] **Step 1: Confirm no remaining references will break**

Run: `grep -rn "ai_extraction_disabled" app/ tests/ database/`
Expected before changes: matches in `app/Models/Product.php`, `app/Filament/Resources/ProductResource.php`, and the original migration `database/migrations/2026_06_05_185606_add_ai_extraction_disabled_to_products_table.php`. (Task 4 already removed the test/service references.) If any test still references it, update that test to drop the reference before proceeding.

- [ ] **Step 2: Create the drop migration**

Run: `lando artisan make:migration drop_ai_extraction_disabled_from_products_table --no-interaction`

Set its contents to:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('ai_extraction_disabled');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('ai_extraction_disabled')->default(false);
        });
    }
};
```

- [ ] **Step 3: Remove the field from the `Product` model**

In `app/Models/Product.php`:
- Delete the `@property bool $ai_extraction_disabled` docblock line (~line 43).
- Delete the entire `protected $attributes = [ 'ai_extraction_disabled' => false, ];` block (~lines 69-71) — it holds only this key.
- Delete the `'ai_extraction_disabled' => 'boolean',` entry from `$casts` (~line 80).

- [ ] **Step 4: Remove the toggle from `ProductResource`**

In `app/Filament/Resources/ProductResource.php`, delete the toggle block (~lines 217-221):

```php
                Forms\Components\Toggle::make('ai_extraction_disabled')
                    ->label('Disable AI extraction')
                    ->hintIcon(Icons::Help->value, 'Skip AI price recovery for this product.')
                    ->hidden(fn (): bool => ! IntegrationHelper::isAiEnabled())
                    ->columnSpanFull(),
```

Then check whether `IntegrationHelper` is still referenced anywhere else in the file:

Run: `grep -n "IntegrationHelper" app/Filament/Resources/ProductResource.php`
If the only remaining match is the `use App\Services\Helpers\IntegrationHelper;` import line, delete that import line too.

- [ ] **Step 5: Run the migration and the affected suites**

Run: `lando artisan migrate --no-interaction`
Expected: the drop migration runs cleanly.

Run: `lando artisan test --compact --filter="ProductResource|ProductTest"`
Expected: PASS (no references to the removed field).

- [ ] **Step 6: Commit**

```bash
git add app/Models/Product.php app/Filament/Resources/ProductResource.php database/migrations
git commit -m "feat: remove per-product ai_extraction_disabled opt-out"
```

---

## Task 7: Standards + full test run

**Files:** none (verification only)

- [ ] **Step 1: Fix + check coding standards**

Run: `lando phpcs-fix && lando phpcs`
Expected: ends with `[OK] No errors` (Pint passes, then PHPStan reports no errors). Note: a Pint failure short-circuits before PHPStan runs — make sure you reach the PHPStan `[OK]`.

- [ ] **Step 2: Run the full suite in parallel**

Run: `lando artisan test --parallel`
Expected: all tests green.

- [ ] **Step 3: Commit any standards fixes**

```bash
git add -A
git commit -m "style: apply phpcs fixes for per-store AI extraction"
```
(Skip if there is nothing to commit.)

---

## Self-review notes

- **Spec §1 (App Settings unchanged):** no task modifies the AI settings section — correct; `isAiEnabled()` keeps its definition (Task 5 only *reads* it).
- **Spec §2 (store controls):** Task 5.
- **Spec §3 (store accessors):** Task 3.
- **Spec §4 (provider resolver):** Task 1.
- **Spec §5 (pipeline + provider threading):** Tasks 2 and 4.
- **Spec §6 (remove per-product opt-out):** Task 6.
- **Spec testing section:** covered across Tasks 1–6, with phpcs + parallel run in Task 7.
- **Type consistency:** `getAiProvider(?string): ?AiProviderConfigDto`, `extract(string, ?Collection, ?AiProviderConfigDto)`, `structured(string, Closure, string, ?AiProviderConfigDto)`, `Store::$ai_extraction_enabled` (bool), `Store::$ai_provider_id` (?string), store settings keys `ai_extraction_enabled` / `ai_provider_id` — used identically in form, accessors, and pipeline.

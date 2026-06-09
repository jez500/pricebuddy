# AI Extraction Fallback Pipeline Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** When a scrape finds no price, optionally recover one via `AiExtractionService` — purely additive, gated on AI being enabled, the product not opted out, the item in stock, and a 0.6 confidence threshold.

**Architecture:** A focused `AiScrapeEnhancer` service, called from one line in `Url::updatePrice()`, backfills a missing `price` in the scrape-result array. Every guard failure returns the result untouched, so with AI off (or a broken provider) the pipeline behaves exactly as today. A per-product `ai_extraction_disabled` toggle provides opt-out.

**Tech Stack:** Laravel 12, PHP 8.4, Filament 3, laravel/ai 0.7, Pest/PHPUnit.

**Spec:** `docs/superpowers/specs/2026-06-05-ai-extraction-fallback-pipeline-design.md`

**Conventions:**
- All tooling via Lando: `lando artisan ...`, `lando ssh -c "..."`. Never run php on the host.
- Tests: `lando artisan test --parallel --filter=...`. If a test fails with `getaddrinfo for tests_db failed`, run `lando start`.
- After PHP edits run `lando ssh -c "vendor/bin/pint --dirty"` then the FULL `lando phpcs` and confirm it ends with `[OK] No errors`. GOTCHA: `lando phpcs` runs Pint then PHPStan; a Pint failure short-circuits before PHPStan, so a passing Pint line is not enough — confirm `[OK] No errors`.
- Branch: `feature/priceghost-parity-ai` (already checked out). End commit messages with a blank line then `Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>`.

**What already exists (use, do not recreate):**
- `App\Services\AiExtractionService::extract(string $html, ?Collection $schemaOrg = null): ?App\Dto\AiExtractionResultDto`. The DTO has named, defaulted params `(?string $title, ?float $price, ?string $currency, ?string $image, ?StockStatus $stockStatus, float $confidence)`.
- `App\Services\Helpers\IntegrationHelper::isAiEnabled(): bool` (true only when AI enabled AND a valid default provider exists).
- `App\Enums\StockStatus::matchFromScrapedValue(?string $scrapedValue, array|string|null $matchConfig): self` and `->isUnavailable(): bool`. With a null/empty match config, any non-empty scraped value → OutOfStock; an empty value → InStock.
- `Url::updatePrice(int|float|string|null $price = null, ?array $scrapeResult = null)`; `$url->store` (Store with array-cast `scrape_strategy`), `$url->product` (Product, may be null).
- `Product` uses `protected $guarded` (so new columns are mass-assignable automatically) + a `protected $casts = [...]` array.

---

## File Structure

New:
- `app/Services/AiScrapeEnhancer.php` — the gap-fill orchestrator (one responsibility).
- `database/migrations/<ts>_add_ai_extraction_disabled_to_products_table.php`
- `tests/Feature/Services/AiScrapeEnhancerTest.php`

Modified:
- `app/Models/Product.php` — cast + PHPDoc for `ai_extraction_disabled`.
- `app/Models/Url.php` — one line in `updatePrice()`.
- `app/Filament/Resources/ProductResource.php` — opt-out toggle.
- `tests/Feature/Models/UrlUpdatePriceTest.php` (new, or add to an existing Url test) — pipeline behaviour.

---

## Task 1: Per-product opt-out column

**Files:**
- Create: `database/migrations/<ts>_add_ai_extraction_disabled_to_products_table.php`
- Modify: `app/Models/Product.php`
- Test: `tests/Feature/Models/ProductAiToggleTest.php`

- [ ] **Step 1: Create the migration**

Run:
```bash
lando artisan make:migration add_ai_extraction_disabled_to_products_table --no-interaction
```
Then replace the generated file's body with:
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
            $table->boolean('ai_extraction_disabled')->default(false)->after('notify_in_stock');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('ai_extraction_disabled');
        });
    }
};
```

- [ ] **Step 2: Write the failing test**

Create `tests/Feature/Models/ProductAiToggleTest.php`:
```php
<?php

namespace Tests\Feature\Models;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductAiToggleTest extends TestCase
{
    use RefreshDatabase;

    public function test_ai_extraction_disabled_defaults_to_false_and_casts_to_bool(): void
    {
        $product = Product::factory()->create();

        $this->assertIsBool($product->ai_extraction_disabled);
        $this->assertFalse($product->ai_extraction_disabled);
    }

    public function test_ai_extraction_disabled_persists_when_set(): void
    {
        $product = Product::factory()->create(['ai_extraction_disabled' => true]);

        $this->assertTrue($product->fresh()->ai_extraction_disabled);
    }
}
```

- [ ] **Step 3: Run, confirm FAIL**

Run: `lando artisan test --parallel --filter=ProductAiToggleTest`
Expected: FAIL — the value comes back as `0`/`1` (not bool) or the column is missing until the cast + migration are in place. (If `Product::factory()` needs required attributes, check `database/factories/ProductFactory.php` and pass any the factory doesn't default.)

- [ ] **Step 4: Add the cast + PHPDoc to Product**

In `app/Models/Product.php`, add to the `$casts` array (alongside `'notify_in_stock' => 'boolean'`):
```php
        'ai_extraction_disabled' => 'boolean',
```
And add a PHPDoc `@property` line near the other property docs (e.g. after `@property bool $notify_in_stock`):
```php
 * @property bool $ai_extraction_disabled
```

- [ ] **Step 5: Run, confirm PASS**

Run: `lando artisan test --parallel --filter=ProductAiToggleTest`
Expected: PASS (2 tests).

- [ ] **Step 6: Format + static analysis + commit**

```bash
lando ssh -c "vendor/bin/pint --dirty"
lando phpcs   # MUST end with [OK] No errors
git add database/migrations app/Models/Product.php tests/Feature/Models/ProductAiToggleTest.php
git commit -m "feat: add per-product ai_extraction_disabled opt-out"
```

---

## Task 2: AiScrapeEnhancer service

**Files:**
- Create: `app/Services/AiScrapeEnhancer.php`
- Test: `tests/Feature/Services/AiScrapeEnhancerTest.php`

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Services/AiScrapeEnhancerTest.php`:
```php
<?php

namespace Tests\Feature\Services;

use App\Dto\AiExtractionResultDto;
use App\Models\Product;
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

    private function enableAi(): void
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

    private function url(array $productAttrs = []): Url
    {
        $product = Product::factory()->create($productAttrs);

        return Url::factory()->for($product)->create();
    }

    public function test_leaves_result_untouched_when_a_price_is_present(): void
    {
        $this->enableAi();
        $this->mock(AiExtractionService::class, fn ($m) => $m->shouldReceive('extract')->never());

        $result = AiScrapeEnhancer::new()->enhance($this->url(), ['price' => '12.99', 'body' => '<html>']);

        $this->assertSame('12.99', $result['price']);
    }

    public function test_does_nothing_when_ai_is_disabled(): void
    {
        // AI not enabled (no settings seeded).
        $this->mock(AiExtractionService::class, fn ($m) => $m->shouldReceive('extract')->never());

        $result = AiScrapeEnhancer::new()->enhance($this->url(), ['price' => null, 'body' => '<html>9.99</html>']);

        $this->assertNull($result['price']);
    }

    public function test_does_nothing_when_product_opted_out(): void
    {
        $this->enableAi();
        $this->mock(AiExtractionService::class, fn ($m) => $m->shouldReceive('extract')->never());

        $result = AiScrapeEnhancer::new()->enhance(
            $this->url(['ai_extraction_disabled' => true]),
            ['price' => null, 'body' => '<html>9.99</html>'],
        );

        $this->assertNull($result['price']);
    }

    public function test_does_nothing_when_item_is_unavailable(): void
    {
        $this->enableAi();
        $this->mock(AiExtractionService::class, fn ($m) => $m->shouldReceive('extract')->never());

        // No store match config => any non-empty availability string => OutOfStock.
        $result = AiScrapeEnhancer::new()->enhance(
            $this->url(),
            ['price' => null, 'body' => '<html>', 'availability' => 'Sold out'],
        );

        $this->assertNull($result['price']);
    }

    public function test_does_nothing_when_confidence_below_threshold(): void
    {
        $this->enableAi();
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
        $this->enableAi();
        $this->mock(AiExtractionService::class, fn ($m) => $m->shouldReceive('extract')
            ->once()->andReturn(new AiExtractionResultDto(price: 9.99, confidence: 0.82)));
        Log::shouldReceive('channel')->with('db')->andReturnSelf();
        Log::shouldReceive('withContext')->andReturnSelf();
        Log::shouldReceive('info')->once();

        $result = AiScrapeEnhancer::new()->enhance($this->url(), ['price' => null, 'body' => '<html>9.99</html>']);

        $this->assertSame(9.99, $result['price']);
    }
}
```
> If `Url::factory()->for($product)` needs a store, check `database/factories/UrlFactory.php` — it likely creates/attaches a `Store` by default. If `enhance()` reads `$url->store->scrape_strategy` and the factory store has none, `data_get` returns null and the unavailable check uses a null match config (the intended path). Adjust factory usage only if a test errors on a missing relation.

- [ ] **Step 2: Run, confirm FAIL**

Run: `lando artisan test --parallel --filter=AiScrapeEnhancerTest`
Expected: FAIL (class missing).

- [ ] **Step 3: Implement the service**

Create `app/Services/AiScrapeEnhancer.php`:
```php
<?php

namespace App\Services;

use App\Enums\StockStatus;
use App\Models\Url;
use App\Services\Helpers\IntegrationHelper;
use Illuminate\Support\Facades\Log;

class AiScrapeEnhancer
{
    public const float MIN_CONFIDENCE = 0.6;

    public function __construct(protected AiExtractionService $extraction) {}

    public static function new(): self
    {
        return resolve(static::class);
    }

    /**
     * Backfill a missing price via AI when enabled and allowed. AI is purely additive:
     * any guard failure returns the scrape result exactly as it was scraped.
     *
     * @param  array<string, mixed>  $scrapeResult
     * @return array<string, mixed>
     */
    public function enhance(Url $url, array $scrapeResult): array
    {
        // Only fill a genuine gap.
        if (filled(data_get($scrapeResult, 'price'))) {
            return $scrapeResult;
        }

        // AI is optional — do no work when it is not enabled.
        if (! IntegrationHelper::isAiEnabled()) {
            return $scrapeResult;
        }

        // Per-product opt-out.
        if ($url->product?->ai_extraction_disabled) {
            return $scrapeResult;
        }

        $html = data_get($scrapeResult, 'body');

        if (blank($html)) {
            return $scrapeResult;
        }

        // An out-of-stock item has no purchasable price; don't spend tokens.
        $matchConfig = data_get($url->store, 'scrape_strategy.availability.match');
        $isUnavailable = StockStatus::matchFromScrapedValue(data_get($scrapeResult, 'availability'), $matchConfig)
            ->isUnavailable();

        if ($isUnavailable) {
            return $scrapeResult;
        }

        $result = $this->extraction->extract($html);

        if ($result === null || $result->price === null || $result->confidence < self::MIN_CONFIDENCE) {
            Log::channel('db')->withContext(['url' => $url->url])
                ->debug('AI could not recover a confident price.');

            return $scrapeResult;
        }

        data_set($scrapeResult, 'price', $result->price);

        Log::channel('db')->withContext(['url' => $url->url])
            ->info('Price recovered via AI (confidence '.number_format($result->confidence, 2).').');

        return $scrapeResult;
    }
}
```

- [ ] **Step 4: Run, confirm PASS**

Run: `lando artisan test --parallel --filter=AiScrapeEnhancerTest`
Expected: PASS (6 tests). If the "disabled" or "opted out" test still calls `extract`, recheck the guard order — `isAiEnabled()` and the opt-out must short-circuit before `extract()`.

- [ ] **Step 5: Format + full static analysis + commit**

```bash
lando ssh -c "vendor/bin/pint --dirty"
lando phpcs   # MUST end with [OK] No errors
git add app/Services/AiScrapeEnhancer.php tests/Feature/Services/AiScrapeEnhancerTest.php
git commit -m "feat: add AiScrapeEnhancer to backfill missing prices via AI"
```

---

## Task 3: Wire into the pipeline + product toggle

**Files:**
- Modify: `app/Models/Url.php`
- Modify: `app/Filament/Resources/ProductResource.php`
- Test: `tests/Feature/Models/UrlUpdatePriceTest.php`

- [ ] **Step 1: Write the failing feature test**

Create `tests/Feature/Models/UrlUpdatePriceTest.php`:
```php
<?php

namespace Tests\Feature\Models;

use App\Dto\AiExtractionResultDto;
use App\Models\Price;
use App\Models\Product;
use App\Models\Url;
use App\Services\AiExtractionService;
use App\Services\Helpers\SettingsHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Once;
use Tests\TestCase;

class UrlUpdatePriceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SettingsHelper::$settings = null;
        Cache::flush();
        Once::flush();
    }

    private function enableAi(): void
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

    public function test_ai_backfills_price_when_scrape_finds_none(): void
    {
        $this->enableAi();
        $this->mock(AiExtractionService::class, fn ($m) => $m->shouldReceive('extract')
            ->once()->andReturn(new AiExtractionResultDto(price: 9.99, confidence: 0.9)));
        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('withContext')->andReturnSelf();
        Log::shouldReceive('info');

        $url = Url::factory()->for(Product::factory())->create();

        $price = $url->updatePrice(null, ['price' => null, 'body' => '<html>9.99</html>', 'availability' => null]);

        $this->assertInstanceOf(Price::class, $price);
        $this->assertSame(9.99, (float) $price->price);
    }

    public function test_no_price_recorded_when_ai_disabled_and_scrape_finds_none(): void
    {
        // AI disabled (no settings). extract must never be called.
        $this->mock(AiExtractionService::class, fn ($m) => $m->shouldReceive('extract')->never());

        $url = Url::factory()->for(Product::factory())->create();

        $price = $url->updatePrice(null, ['price' => null, 'body' => '<html>9.99</html>', 'availability' => null]);

        $this->assertNull($price);
        $this->assertDatabaseCount('prices', 0);
    }
}
```
> Verify `Url::factory()->for(Product::factory())` produces a URL with a `store_id` (needed — `updatePrice` returns null early without one). Check `database/factories/UrlFactory.php`; if it doesn't attach a store by default, add `->for(Store::factory())` or set `store_id`. Also confirm `Price` model + `prices` table names match (`assertDatabaseCount('prices', ...)`).

- [ ] **Step 2: Run, confirm FAIL**

Run: `lando artisan test --parallel --filter=UrlUpdatePriceTest`
Expected: the AI-backfill test FAILS (price not backfilled yet — `updatePrice` doesn't call the enhancer). The disabled test may already pass.

- [ ] **Step 3: Wire the enhancer into `updatePrice`**

In `app/Models/Url.php`, add the import near the other `use` lines:
```php
use App\Services\AiScrapeEnhancer;
```
Then in `updatePrice()`, change the no-manual-price branch from:
```php
        if (is_null($price) || $price === '') {
            $scrapeResult = $scrapeResult ?? $this->scrape();
            $price = data_get($scrapeResult, 'price');
        }
```
to:
```php
        if (is_null($price) || $price === '') {
            $scrapeResult = $scrapeResult ?? $this->scrape();
            $scrapeResult = AiScrapeEnhancer::new()->enhance($this, $scrapeResult);
            $price = data_get($scrapeResult, 'price');
        }
```

- [ ] **Step 4: Run, confirm PASS**

Run: `lando artisan test --parallel --filter=UrlUpdatePriceTest`
Expected: PASS (2 tests).

- [ ] **Step 5: Add the product opt-out toggle**

In `app/Filament/Resources/ProductResource.php`, inside the `Section::make('Notifications')` schema array (after the `notify_in_stock` toggle), add:
```php
                Forms\Components\Toggle::make('ai_extraction_disabled')
                    ->label('Disable AI extraction')
                    ->hintIcon(Icons::Help->value, 'When AI is enabled globally, skip AI price recovery for this product.')
                    ->columnSpanFull(),
```
(Confirm `Icons` is already imported in this file — it is used by the sibling toggles' `hintIcon`.)

- [ ] **Step 6: Confirm the resource still loads + tests stay green**

Run:
```bash
lando artisan test --parallel --filter='UrlUpdatePriceTest|ProductResource'
```
Expected: PASS. (If there is a `ProductResource` form test, it should still pass; the new toggle binds to a mass-assignable, cast column.)

- [ ] **Step 7: Format + full static analysis + commit**

```bash
lando ssh -c "vendor/bin/pint --dirty"
lando phpcs   # MUST end with [OK] No errors
git add app/Models/Url.php app/Filament/Resources/ProductResource.php tests/Feature/Models/UrlUpdatePriceTest.php
git commit -m "feat: wire AI price fallback into Url::updatePrice with per-product toggle"
```

---

## Task 4: Full verification sweep

**Files:** none (verification only)

- [ ] **Step 1: Run the entire suite**

Run: `lando artisan test --parallel`
Expected: all green (1 pre-existing skip allowed). Investigate any regression — especially existing `Url`/scrape tests, since `updatePrice` changed.

- [ ] **Step 2: Coding standards + static analysis**

Run: `lando phpcs-fix && lando phpcs`
Expected: `[OK] No errors`.

- [ ] **Step 3: Confirm AI is genuinely optional (no provider → unchanged behaviour)**

Run:
```bash
lando artisan tinker --execute 'echo \App\Services\Helpers\IntegrationHelper::isAiEnabled() ? "ai-on" : "ai-off";'
```
Expected: prints the current state. Then reason: with `ai-off`, `AiScrapeEnhancer::enhance()` returns at guard 2 before any AI call — confirm by re-reading the guard order in `app/Services/AiScrapeEnhancer.php`.

- [ ] **Step 4: Review the branch**

```bash
git log --oneline main..HEAD | head
```
Expected: the three feature commits from Tasks 1–3 on top of the prior AI work.

---

## Self-Review Notes (for the implementer)

- **AI is purely additive:** guards 1–5 in `enhance()` (price present / AI off / opted out / no HTML / unavailable) all return the input untouched before any `extract()` call. The disabled and opted-out tests assert `extract` is `never()` called — keep those.
- **No network in tests:** pass an explicit `$scrapeResult` to `updatePrice()` so it never hits `ScrapeUrl::scrape()`; mock `AiExtractionService::extract`.
- **Float price:** the AI returns a clean float; `CurrencyHelper::toFloat()` downstream handles it. The feature test asserts `(float) $price->price === 9.99`.
- **Out of scope:** verification/arbitration/stock AI ops, voting + selection modal, backfilling non-price fields, persisting an AI source on `Price`.
```

# AI Bootstrap/Repair Store Config on Product Create — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** When `Url::createFromUrl()` would fail (no store could be inferred, or a store exists but the scrape found no price/title) and AI healing is enabled, use the existing healing agent to build or repair the store's `scrape_strategy`, re-scrape once, and continue — so admin/API product creation succeeds where it previously errored.

**Architecture:** Extract a pure agent core (`attemptAgentRepair`) from `AiConfigHealer` and add a URL-centric `healStoreForUrl()` that either repairs an existing store or bootstraps a brand-new one (persisted via the existing `CreateStoreAction`, identical shape to heuristic stores). `Url::createFromUrl()` calls it on the failure path and re-scrapes once. AI runs only on failure; happy-path creates are untouched.

**Tech Stack:** Laravel 12, Filament 3, `laravel/ai` SDK, `jez500/web-scraper-for-laravel`, Pest 3. No DB migration (config lives in existing JSON columns). Spec: `docs/superpowers/specs/2026-06-07-ai-bootstrap-store-on-create-design.md`.

**Conventions:** Run everything through Lando: single test `lando artisan test --compact <path>`; style `lando phpcs-fix` then `lando phpcs` (must reach Pint PASS + PHPStan `[OK] No errors`; do NOT use host `vendor/bin/pint` — host PHP lacks mbstring). Tests fake scraping with `Tests\Traits\ScraperTrait` (`Http::fake` via `mockScrape($price,$title,$image,$availability)`), and settings via `SettingsHelper::setSetting(...)` + `SettingsHelper::$settings = null; Cache::flush(); Once::flush();`.

---

## File Structure

**Modify:**
- `app/Services/AutoCreateStore.php` — extract `buildAttributes(string $url, array $scrapeStrategy): array` (shared store-attribute assembly); `getStoreAttributes()` delegates to it.
- `app/Services/AiConfigHealer.php` — extract pure `attemptAgentRepair(HealingContext, provider): ?array` and `applyValidatedSlots(Store, array): void`; change `log()` to accept a string URL; refactor `runHeal()` onto them (behaviour unchanged); add public `healStoreForUrl(string $url, ?Store $store, ?string $html): ?Store`.
- `app/Models/Url.php` — in `createFromUrl()`, invoke the AI fallback on the would-fail path and re-scrape once.

**No change needed:** `app/Filament/Resources/ProductResource/Pages/CreateProduct.php` (it calls `createFromUrl`, which now heals internally).

**Create (tests):**
- `tests/Unit/Services/AutoCreateStoreBuildAttributesTest.php`
- `tests/Feature/Services/AiConfigHealerBootstrapTest.php`
- `tests/Feature/Models/UrlCreateAiFallbackTest.php`
- `tests/Feature/Filament/CreateProductAiFallbackTest.php`

---

## Task 1: Extract `AutoCreateStore::buildAttributes()`

**Files:**
- Modify: `app/Services/AutoCreateStore.php`
- Test: `tests/Unit/Services/AutoCreateStoreBuildAttributesTest.php`

The store-attribute assembly currently lives inside `getStoreAttributes()` (after the detection gate). Extract it so the AI path can build an identical store shape with its own strategy.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit\Services;

use App\Services\AutoCreateStore;
use PHPUnit\Framework\TestCase;

class AutoCreateStoreBuildAttributesTest extends TestCase
{
    public function test_builds_store_attributes_from_url_and_strategy(): void
    {
        $strategy = ['title' => ['type' => 'selector', 'value' => '.t']];

        $attrs = AutoCreateStore::buildAttributes('https://www.shop.test/product/123', $strategy);

        $this->assertSame([['domain' => 'shop.test'], ['domain' => 'www.shop.test']], $attrs['domains']);
        $this->assertSame('Shop.test', $attrs['name']);
        $this->assertSame($strategy, $attrs['scrape_strategy']);
        $this->assertSame('https://www.shop.test/product/123', $attrs['settings']['test_url']);
        $this->assertSame('http', $attrs['settings']['scraper_service']);
        $this->assertArrayHasKey('locale', $attrs['settings']['locale_settings']);
        $this->assertArrayHasKey('currency', $attrs['settings']['locale_settings']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `lando artisan test --compact tests/Unit/Services/AutoCreateStoreBuildAttributesTest.php`
Expected: FAIL — `Call to undefined method App\Services\AutoCreateStore::buildAttributes()`.

- [ ] **Step 3: Extract the method**

In `app/Services/AutoCreateStore.php`, add this `public static` method (place it just after `getStoreAttributes()`):

```php
    /**
     * Assemble store attributes for a URL with a given scrape strategy. Shared by
     * heuristic auto-create and AI bootstrap so both produce identical store shapes.
     *
     * @param  array<string, mixed>  $scrapeStrategy
     * @return array<string, mixed>
     */
    public static function buildAttributes(string $url, array $scrapeStrategy): array
    {
        $host = strtolower(Uri::of($url)->host());

        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }

        return [
            'user_id' => auth()->id(),
            'domains' => [
                ['domain' => $host],
                ['domain' => 'www.'.$host],
            ],
            'name' => ucfirst($host),
            'scrape_strategy' => $scrapeStrategy,
            'settings' => [
                'scraper_service' => ScraperService::Http->value,
                'scraper_service_settings' => '',
                'test_url' => $url,
                'locale_settings' => [
                    'locale' => CurrencyHelper::getLocale(),
                    'currency' => CurrencyHelper::getCurrency(),
                ],
            ],
        ];
    }
```

Then refactor `getStoreAttributes()` so everything from `$attributes = [...]` to `return $attributes;` (the assembly block after the detection gate) is replaced by:

```php
        return self::buildAttributes(
            $this->url,
            collect($this->strategyParse())
                ->mapWithKeys(fn ($value, $key): array => [$key => collect($value)->only('type', 'value')->all()])
                ->toArray(),
        );
```

Leave the detection gate (the `if (...) { errorLog; return null; }`) and `$strategy = $this->strategyParse();` lines above it unchanged. `ScraperService`, `CurrencyHelper`, and `Uri` are already imported in this file (the old code used them).

- [ ] **Step 4: Run test to verify it passes**

Run: `lando artisan test --compact tests/Unit/Services/AutoCreateStoreBuildAttributesTest.php`
Expected: PASS.

- [ ] **Step 5: Run the existing AutoCreateStore suite to confirm parity**

Run: `lando artisan test --compact tests/Unit/Services/AutoCreateStoreTest.php`
Expected: PASS (unchanged output shape).

- [ ] **Step 6: Style + commit**

```bash
lando phpcs-fix && lando phpcs
git add app/Services/AutoCreateStore.php tests/Unit/Services/AutoCreateStoreBuildAttributesTest.php
git commit -m "refactor: extract AutoCreateStore::buildAttributes for reuse"
```

---

## Task 2: Refactor `AiConfigHealer` to a pure agent core

**Files:**
- Modify: `app/Services/AiConfigHealer.php`
- Test: `tests/Feature/Services/AiConfigHealerTest.php` (existing — must stay green, no edits expected)

Behaviour-preserving refactor: extract the agent run+validation into a pure `attemptAgentRepair` (no persistence/cooldown), a small `applyValidatedSlots` helper, and make `log()` take a string. `heal()`'s public behaviour is unchanged; the existing 12 tests are the safety net.

- [ ] **Step 1: Change `log()` to accept a string URL**

In `app/Services/AiConfigHealer.php`, replace:

```php
    protected function log(Url $url): LoggerInterface
    {
        // @phpstan-ignore-next-line - withContext is valid.
        return Log::channel('db')->withContext(['url' => $url->url]);
    }
```

with:

```php
    protected function log(string $url): LoggerInterface
    {
        // @phpstan-ignore-next-line - withContext is valid.
        return Log::channel('db')->withContext(['url' => $url]);
    }
```

- [ ] **Step 2: Replace `runHeal()` with a thin wrapper + pure core + apply helper**

Replace the entire current `runHeal()` method (from its signature through its closing brace) with the following three methods:

```php
    /**
     * @param  array<string, mixed>  $scrapeResult
     * @return array<string, mixed>
     */
    protected function runHeal(Url $url, Store $store, array $scrapeResult, string $html, AiProviderConfigDto $provider): array
    {
        $result = $this->attemptAgentRepair(new HealingContext($url->url, $store, $html), $provider);

        if ($result === null) {
            $store->markAiHealFailed();

            return $scrapeResult;
        }

        $this->applyValidatedSlots($store, $result['validated']);
        $store->clearAiHealFailed();

        foreach ($result['extracted'] as $field => $value) {
            data_set($scrapeResult, $field, $value);
        }

        $this->log($url->url)->info('Store scraper config healed via AI.', ['fields' => array_keys($result['validated'])]);

        return $scrapeResult;
    }

    /**
     * Run the agent against a page and return validated selectors + extracted values,
     * or null. Pure: performs no persistence and sets no cooldown — callers decide.
     *
     * @return array{validated: array<string, array<string, mixed>>, extracted: array<string, string>}|null
     */
    protected function attemptAgentRepair(HealingContext $context, AiProviderConfigDto $provider): ?array
    {
        $url = $context->url;

        $tools = [
            new FetchPageHtmlTool($context),
            new TestCssSelectorTool($context),
            new TestRegexTool($context),
        ];

        $this->log($url)->info('AI self-healing started; attempting to repair store scraper config.', [
            'provider' => $provider->name,
        ]);

        try {
            $proposal = $this->ai->runAgent(
                self::PROMPT,
                $this->schema(),
                'Develop an extraction plan for this product page: '.$url,
                $tools,
                $provider,
                self::MAX_STEPS,
            );
        } catch (AiProviderException $e) {
            $this->log($url)->warning('AI healing provider error; config unchanged.', ['error' => $e->getMessage()]);

            return null;
        }

        if (data_get($proposal, 'is_product') === false) {
            $this->log($url)->info('AI determined the page is not a product; config unchanged.');

            return null;
        }

        $fields = data_get($proposal, 'fields');

        if (! is_array($fields)) {
            $this->log($url)->warning('AI healing returned no usable proposal; config unchanged.');

            return null;
        }

        $validated = [];
        $extracted = [];

        foreach (self::PROPOSABLE_FIELDS as $field) {
            $slot = $this->normaliseSlot(data_get($fields, $field));

            if ($slot === null) {
                continue;
            }

            $value = $context->validate($slot['type'], $slot['value'])['value'] ?? null;

            if (filled($value)) {
                $validated[$field] = $slot;
                $extracted[$field] = $value;
            }
        }

        foreach (self::REQUIRED_FIELDS as $required) {
            if (! filled($extracted[$required] ?? null)) {
                $this->log($url)->info('AI healing could not validate required field: '.$required);

                return null;
            }
        }

        return ['validated' => $validated, 'extracted' => $extracted];
    }

    /**
     * Merge validated strategy slots into the store's scrape_strategy (in memory).
     *
     * @param  array<string, array<string, mixed>>  $validated
     */
    protected function applyValidatedSlots(Store $store, array $validated): void
    {
        $strategy = $store->scrape_strategy ?? [];

        foreach ($validated as $field => $slot) {
            $strategy[$field] = $slot;
        }

        $store->scrape_strategy = $strategy;
    }
```

(`HealingContext::$url` is a public readonly property, so `$context->url` is valid. `HealingContext` is already imported.)

- [ ] **Step 3: Run the existing healer suite — behaviour must be unchanged**

Run: `lando artisan test --compact tests/Feature/Services/AiConfigHealerTest.php`
Expected: PASS (all 12, including `test_logs_when_a_heal_is_attempted` which asserts the unchanged "AI self-healing started…" message).

- [ ] **Step 4: Style check (PHPStan matters for the new array-shape PHPDoc)**

Run: `lando phpcs-fix && lando phpcs`
Expected: Pint PASS + PHPStan `[OK] No errors`.

- [ ] **Step 5: Commit**

```bash
git add app/Services/AiConfigHealer.php
git commit -m "refactor: extract pure attemptAgentRepair core in AiConfigHealer"
```

---

## Task 3: Add `AiConfigHealer::healStoreForUrl()`

**Files:**
- Modify: `app/Services/AiConfigHealer.php`
- Test: `tests/Feature/Services/AiConfigHealerBootstrapTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Services;

use App\Models\Store;
use App\Services\AiConfigHealer;
use App\Services\AiService;
use App\Services\Helpers\SettingsHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Once;
use Tests\TestCase;

class AiConfigHealerBootstrapTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        SettingsHelper::$settings = null;
        Cache::flush();
        Once::flush();
    }

    private function configureProviders(array $aiOverrides = []): void
    {
        SettingsHelper::setSetting('integrated_services', ['ai' => array_merge([
            'enabled' => true,
            'default_provider_id' => 'p1',
            'providers' => [[
                'id' => 'p1', 'name' => 'Local', 'type' => 'ollama',
                'base_url' => 'http://ai.example:11434', 'model' => 'm',
            ]],
        ], $aiOverrides)]);
        SettingsHelper::$settings = null;
        Cache::flush();
        Once::flush();
    }

    private function mockAgent(?array $proposal, string $expectation = 'once'): void
    {
        $this->mock(AiService::class, fn ($m) => $m->shouldReceive('runAgent')->{$expectation}()->andReturn($proposal));
    }

    private function html(): string
    {
        return '<html><body><h1 class="t">Widget</h1><span id="pr">$12.99</span></body></html>';
    }

    private function validProposal(): array
    {
        return [
            'is_product' => true,
            'fields' => [
                'title' => ['type' => 'selector', 'value' => '.t'],
                'price' => ['type' => 'selector', 'value' => '#pr'],
            ],
        ];
    }

    public function test_creates_a_new_store_from_ai_selectors_when_none_exists(): void
    {
        $this->configureProviders();
        $this->mockAgent($this->validProposal());

        $store = AiConfigHealer::new()->healStoreForUrl('https://shop.test/widget', null, $this->html());

        $this->assertInstanceOf(Store::class, $store);
        $this->assertTrue($store->exists);
        $this->assertSame('#pr', data_get($store->scrape_strategy, 'price.value'));
        $this->assertSame('.t', data_get($store->scrape_strategy, 'title.value'));
        $this->assertSame('Shop.test', $store->name);
        $this->assertContains('shop.test', collect($store->domains)->pluck('domain')->all());
    }

    public function test_repairs_an_existing_store(): void
    {
        $this->configureProviders();
        $this->mockAgent($this->validProposal());
        $store = Store::factory()->create([
            'domains' => [['domain' => 'shop.test']],
            'scrape_strategy' => ['image' => ['type' => 'selector', 'value' => 'img|src']],
            'settings' => ['scraper_service' => 'http'],
        ]);

        $returned = AiConfigHealer::new()->healStoreForUrl('https://shop.test/widget', $store, $this->html());

        $this->assertSame($store->getKey(), $returned?->getKey());
        $this->assertSame('#pr', data_get($store->fresh()->scrape_strategy, 'price.value'));
        $this->assertSame('img|src', data_get($store->fresh()->scrape_strategy, 'image.value'));
    }

    public function test_returns_null_and_creates_nothing_when_healing_disabled_globally(): void
    {
        $this->configureProviders(['feature_providers' => ['healing' => '__disabled__']]);
        $this->mockAgent(null, 'never');

        $store = AiConfigHealer::new()->healStoreForUrl('https://shop.test/widget', null, $this->html());

        $this->assertNull($store);
        $this->assertDatabaseCount('stores', 0);
    }

    public function test_returns_null_when_existing_store_opted_out(): void
    {
        $this->configureProviders();
        $this->mockAgent(null, 'never');
        $store = Store::factory()->create([
            'domains' => [['domain' => 'shop.test']],
            'settings' => ['scraper_service' => 'http', 'ai_self_healing_disabled' => true],
        ]);

        $this->assertNull(AiConfigHealer::new()->healStoreForUrl('https://shop.test/widget', $store, $this->html()));
    }

    public function test_persists_no_store_when_ai_fails_for_new_domain(): void
    {
        $this->configureProviders();
        $this->mockAgent(null);

        $store = AiConfigHealer::new()->healStoreForUrl('https://shop.test/widget', null, $this->html());

        $this->assertNull($store);
        $this->assertDatabaseCount('stores', 0);
    }

    public function test_sets_cooldown_when_ai_fails_for_existing_store(): void
    {
        $this->configureProviders();
        $this->mockAgent(null);
        $store = Store::factory()->create([
            'domains' => [['domain' => 'shop.test']],
            'settings' => ['scraper_service' => 'http'],
        ]);

        $this->assertNull(AiConfigHealer::new()->healStoreForUrl('https://shop.test/widget', $store, $this->html()));
        $this->assertNotNull($store->fresh()->getAiHealFailedAt());
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `lando artisan test --compact tests/Feature/Services/AiConfigHealerBootstrapTest.php`
Expected: FAIL — `Call to undefined method App\Services\AiConfigHealer::healStoreForUrl()`.

- [ ] **Step 3: Add imports**

In `app/Services/AiConfigHealer.php`, add these imports (with the existing `use` block):

```php
use App\Actions\CreateStoreAction;
use Illuminate\Support\Uri;
use Throwable;
```

- [ ] **Step 4: Add the `healStoreForUrl()` method**

Add this public method (e.g. directly after `heal()`):

```php
    /**
     * Ensure a working store config exists for the URL by building (when no store
     * exists) or repairing (when one does) its scrape_strategy via the AI agent.
     * Returns the usable Store, or null when AI is unavailable/opted-out/fails.
     */
    public function healStoreForUrl(string $url, ?Store $store, ?string $html): ?Store
    {
        if ($store !== null && $store->ai_self_healing_disabled) {
            return null;
        }

        $provider = IntegrationHelper::resolveFeatureProvider(AiFeature::Healing, $store);

        if ($provider === null) {
            return null;
        }

        if ($store !== null) {
            $failedAt = $store->getAiHealFailedAt();

            if ($failedAt !== null && $failedAt->addHours(self::COOLDOWN_HOURS)->isFuture()) {
                return null;
            }
        }

        $host = strtolower(Uri::of($url)->host());
        $lock = Cache::lock('ai-heal:store:'.($store?->getKey() ?? $host), self::LOCK_SECONDS);

        if (! $lock->get()) {
            return null;
        }

        try {
            $context = new HealingContext($url, $store ?? new Store(['settings' => []]), $html);

            if (blank($context->getHtml())) {
                try {
                    $context->fetch(false);
                } catch (Throwable $e) {
                    $this->log($url)->warning('AI healing could not fetch page HTML.', ['error' => $e->getMessage()]);
                    $store?->markAiHealFailed();

                    return null;
                }
            }

            $result = $this->attemptAgentRepair($context, $provider);

            if ($result === null) {
                $store?->markAiHealFailed();

                return null;
            }

            if ($store !== null) {
                $this->applyValidatedSlots($store, $result['validated']);
                $store->clearAiHealFailed();
                $this->log($url)->info('Store scraper config healed via AI.', [
                    'store_id' => $store->getKey(),
                    'fields' => array_keys($result['validated']),
                ]);

                return $store;
            }

            $created = (new CreateStoreAction)(AutoCreateStore::buildAttributes($url, $result['validated']));

            if ($created !== null) {
                $this->log($url)->info('Store created via AI self-healing.', [
                    'store_id' => $created->getKey(),
                    'fields' => array_keys($result['validated']),
                ]);
            }

            return $created;
        } finally {
            $lock->release();
        }
    }
```

Note: `AutoCreateStore` is in the same `App\Services` namespace as `AiConfigHealer`, so no import is needed for it.

- [ ] **Step 5: Run to verify it passes**

Run: `lando artisan test --compact tests/Feature/Services/AiConfigHealerBootstrapTest.php`
Expected: PASS (6 tests).

- [ ] **Step 6: Style + commit**

```bash
lando phpcs-fix && lando phpcs
git add app/Services/AiConfigHealer.php tests/Feature/Services/AiConfigHealerBootstrapTest.php
git commit -m "feat: add AiConfigHealer::healStoreForUrl to bootstrap/repair store config"
```

---

## Task 4: Wire the AI fallback into `Url::createFromUrl`

**Files:**
- Modify: `app/Models/Url.php`
- Test: `tests/Feature/Models/UrlCreateAiFallbackTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Models;

use App\Models\Product;
use App\Models\Store;
use App\Models\Url;
use App\Models\User;
use App\Services\AiConfigHealer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\ScraperTrait;

class UrlCreateAiFallbackTest extends TestCase
{
    use RefreshDatabase;
    use ScraperTrait;

    const URL = 'https://newdomain.test/product/1';

    public function test_ai_builds_a_store_when_create_would_otherwise_fail(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $product = Product::factory()->create();

        // No store exists for newdomain.test, so the initial scrape finds nothing.
        // The (mocked) healer creates a working store; the re-scrape then succeeds.
        $this->mock(AiConfigHealer::class, fn ($m) => $m->shouldReceive('healStoreForUrl')
            ->once()
            ->andReturnUsing(fn ($url, $store, $html) => Store::factory()->create([
                'domains' => [['domain' => 'newdomain.test']],
            ])));

        $this->mockScrape(50, 'Healed Widget');

        $urlModel = Url::createFromUrl(self::URL, productId: $product->id, createStore: false);

        $this->assertInstanceOf(Url::class, $urlModel);
        $this->assertSame($product->id, $urlModel->product_id);
        $this->assertSame('newdomain.test', parse_url($urlModel->url, PHP_URL_HOST));
    }

    public function test_returns_false_and_creates_nothing_when_ai_cannot_heal(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->mock(AiConfigHealer::class, fn ($m) => $m->shouldReceive('healStoreForUrl')
            ->once()
            ->andReturnNull());

        $this->mockScrape('', '');

        $result = Url::createFromUrl(self::URL, createStore: false);

        $this->assertFalse($result);
        $this->assertDatabaseCount('stores', 0);
        $this->assertDatabaseCount('urls', 0);
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `lando artisan test --compact tests/Feature/Models/UrlCreateAiFallbackTest.php`
Expected: FAIL — `test_ai_builds_a_store…` fails because `healStoreForUrl` is never called (Mockery `->once()` unsatisfied) and `createFromUrl` returns false.

- [ ] **Step 3: Wire the fallback**

In `app/Models/Url.php`, `createFromUrl()` currently reads:

```php
        $scrape = ScrapeUrl::new($url)->scrape();

        /** @var ?Store $store */
        $store = data_get($scrape, 'store');

        $matchConfig = data_get($store, 'scrape_strategy.availability.match');
        $isUnavailable = StockStatus::matchFromScrapedValue(data_get($scrape, 'availability'), $matchConfig)->isUnavailable();

        if (! $store || (! data_get($scrape, 'price') && ! $isUnavailable)) {
            return false;
        }
```

Replace it with (insert the AI fallback + re-scrape between the first failure computation and the final guard):

```php
        $scrape = ScrapeUrl::new($url)->scrape();

        /** @var ?Store $store */
        $store = data_get($scrape, 'store');

        $matchConfig = data_get($store, 'scrape_strategy.availability.match');
        $isUnavailable = StockStatus::matchFromScrapedValue(data_get($scrape, 'availability'), $matchConfig)->isUnavailable();

        // AI fallback: when a normal create would fail, let the healing agent build or
        // repair the store config, then re-scrape once with the new config.
        if ((! $store || (! data_get($scrape, 'price') && ! $isUnavailable))
            && AiConfigHealer::new()->healStoreForUrl($url, $store, data_get($scrape, 'body')) !== null) {
            $scrape = ScrapeUrl::new($url)->scrape();
            $store = data_get($scrape, 'store');
            $matchConfig = data_get($store, 'scrape_strategy.availability.match');
            $isUnavailable = StockStatus::matchFromScrapedValue(data_get($scrape, 'availability'), $matchConfig)->isUnavailable();
        }

        if (! $store || (! data_get($scrape, 'price') && ! $isUnavailable)) {
            return false;
        }
```

`AiConfigHealer` is already imported in `app/Models/Url.php` (from the earlier healing-on-update work). Confirm the `use App\Services\AiConfigHealer;` line is present; if not, add it.

- [ ] **Step 4: Run to verify it passes**

Run: `lando artisan test --compact tests/Feature/Models/UrlCreateAiFallbackTest.php`
Expected: PASS (2 tests).

- [ ] **Step 5: Regression — existing create paths unaffected**

Run: `lando artisan test --compact tests/Feature/Models/UrlTest.php tests/Feature/Models/UrlUpdatePriceTest.php tests/Feature/Models/UrlHealingOrderTest.php`
Expected: PASS. (The happy-path `createFromUrl` tests never hit the fallback because the initial scrape succeeds. The existing-store `UrlTest` tests have a store with a working strategy, so no fallback runs.)

- [ ] **Step 6: Style + commit**

```bash
lando phpcs-fix && lando phpcs
git add app/Models/Url.php tests/Feature/Models/UrlCreateAiFallbackTest.php
git commit -m "feat: AI-bootstrap a store config when product create would fail"
```

---

## Task 5: Admin create-product feature test (no production change)

**Files:**
- Test: `tests/Feature/Filament/CreateProductAiFallbackTest.php`

`CreateProduct::handleRecordCreation()` already calls `createFromUrl` and translates `false` → `ValidationException`. AI now runs inside `createFromUrl`, so the admin benefits with no page change. This task locks that behaviour in.

- [ ] **Step 1: Write the test**

```php
<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\ProductResource\Pages\CreateProduct;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Services\AiConfigHealer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;
use Tests\Traits\ScraperTrait;

class CreateProductAiFallbackTest extends TestCase
{
    use RefreshDatabase;
    use ScraperTrait;

    const URL = 'https://newshop.test/p/1';

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_product_is_created_when_ai_heals_the_store(): void
    {
        // create_store unticked + no store exists → normal create fails; AI builds one.
        $this->mock(AiConfigHealer::class, fn ($m) => $m->shouldReceive('healStoreForUrl')
            ->once()
            ->andReturnUsing(fn ($url, $store, $html) => Store::factory()->create([
                'domains' => [['domain' => 'newshop.test']],
            ])));

        $this->mockScrape('19.99', 'AI Widget');

        Livewire::test(CreateProduct::class)
            ->fillForm([
                'url' => self::URL,
                'create_store' => false,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('products', ['title' => 'AI Widget']);
        $this->assertDatabaseHas('urls', ['url' => self::URL]);
    }

    public function test_validation_error_when_ai_unavailable(): void
    {
        $this->mock(AiConfigHealer::class, fn ($m) => $m->shouldReceive('healStoreForUrl')
            ->andReturnNull());

        $this->mockScrape('', '');

        Livewire::test(CreateProduct::class)
            ->fillForm([
                'url' => self::URL,
                'create_store' => false,
            ])
            ->call('create')
            ->assertHasFormErrors(['url']);

        $this->assertDatabaseCount('products', 0);
    }
}
```

- [ ] **Step 2: Run the test**

Run: `lando artisan test --compact tests/Feature/Filament/CreateProductAiFallbackTest.php`
Expected: PASS (2 tests). If the second test's error key differs, the create page attaches the message to `url` (see `CreateProduct::handleRecordCreation`), so `assertHasFormErrors(['url'])` is correct; if Filament reports it differently, inspect `tests/Feature/Filament/ProductTest.php` patterns and adjust the assertion minimally.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/Filament/CreateProductAiFallbackTest.php
git commit -m "test: admin product create heals store via AI on failure"
```

---

## Task 6: Final verification

**Files:** none (verification only)

- [ ] **Step 1: Style + static analysis**

Run: `lando phpcs-fix && lando phpcs`
Expected: Pint PASS, then PHPStan `[OK] No errors`.

- [ ] **Step 2: Full suite in parallel**

Run: `lando artisan test --parallel`
Expected: all green (the new tests add to the existing 660 passing).

- [ ] **Step 3: Commit any style fixes**

```bash
git add -A
git commit -m "style: phpcs fixes for AI bootstrap-on-create" || echo "nothing to commit"
```

---

## Self-Review

**Spec coverage:**
- §2 trigger on any would-fail in `createFromUrl`, synchronous, re-scrape once → Task 4. ✓
- §2 runs regardless of `create_store` / adding to existing product → Task 4 test (`createStore: false`, productId set) + Task 5. ✓
- §3 extract pure `attemptAgentRepair`; refactor `heal()` behaviour-preserving → Task 2. ✓
- §3 `healStoreForUrl` (provider gate, opt-out, cooldown, lock, transient store, fetch when no html, new-vs-existing apply, no junk store on failure) → Task 3. ✓
- §4 reuse store-attribute building via `CreateStoreAction` → Tasks 1 + 3. ✓
- §5 gating/safety/logging (global Healing feature, per-store opt-out, MAX_STEPS, lock, db logs) → Tasks 2 + 3. ✓
- §6 testing (healStoreForUrl, createFromUrl, Filament) → Tasks 3, 4, 5. ✓
- §7 out of scope (no async, no toggle/heuristic changes, no new UI) → respected. ✓

**Placeholder scan:** none — every step has full code and exact commands.

**Type consistency:** `healStoreForUrl(string, ?Store, ?string): ?Store`, `attemptAgentRepair(HealingContext, AiProviderConfigDto): ?array{validated,extracted}`, `applyValidatedSlots(Store, array): void`, `AutoCreateStore::buildAttributes(string, array): array`, `log(string)`, lock key `ai-heal:store:{id|host}` — consistent across tasks. `HealingContext::$url` (public readonly) and `getHtml()`/`fetch()`/`validate()` match the existing class.

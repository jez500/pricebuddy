# Deterministic-First Self-Healing Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the self-healing flow try `AutoCreateStore`'s deterministic schema.org/og heuristics first (static HTML, then browser-rendered), and only fall back to the AI agent when they can't build a config — so schema.org sites heal with zero tokens and clean `schema_org` strategies (incl. availability).

**Architecture:** Add `AutoCreateStore::parseAvailability()` + `detect()` (uniform `{fields, extracted}`). Replace the healer's `runAgentForUrl` with `resolveConfigForUrl` that escalates heuristics(static)→heuristics(browser)→agent, returning `{fields, extracted, usedBrowser}`. `heal()`/`healStoreForUrl()`/`previewForUrl()` consume that uniform shape.

**Tech Stack:** Laravel 12, Filament 3, Pest 3, `laravel/ai`. No DB migration. Spec: `docs/superpowers/specs/2026-06-09-deterministic-heal-schema-org-design.md`.

**Conventions:** Run via Lando: single test `lando artisan test --compact <path>`; style `lando phpcs-fix` then `lando phpcs` (Pint PASS + PHPStan `[OK] No errors`; NOT host vendor/bin/pint). Scrape tests fake HTTP with `Http::fake` / the `WebScraper` facade fake.

---

## File Structure

**Modify:**
- `app/Services/AutoCreateStore.php` — add `parseAvailability()` + `detect()`; refactor `getStoreAttributes()` onto `detect()`.
- `app/Services/AiConfigHealer.php` — add `resolveConfigForUrl()`, `detectConfig()`, `applyConfigToStore()`; rewrite `runHeal()`/`healStoreForUrl()` apply-blocks and `previewForUrl()`; remove `runAgentForUrl()`.
- `tests/Feature/Services/AiConfigHealerTest.php` and `tests/Feature/Services/AiConfigHealerBootstrapTest.php` — change the `html()` helper so the agent-path tests use HTML the heuristics can't detect (force the agent fallback).

**Create (tests):**
- additions to `tests/Unit/Services/AutoCreateStoreTest.php`
- `tests/Feature/Services/DeterministicHealTest.php`

---

## Task 1: `AutoCreateStore::detect()` + availability

**Files:**
- Modify: `app/Services/AutoCreateStore.php`
- Test: `tests/Unit/Services/AutoCreateStoreTest.php`

- [ ] **Step 1: Write the failing tests** — append to `tests/Unit/Services/AutoCreateStoreTest.php`:

```php
    public function test_detect_returns_schema_org_strategy_including_availability(): void
    {
        $json = json_encode([
            '@context' => 'https://schema.org/',
            '@type' => 'Product',
            'name' => 'Widget',
            'image' => 'https://x.test/w.png',
            'offers' => [
                '@type' => 'Offer',
                'priceCurrency' => 'USD',
                'price' => 48.95,
                'availability' => 'https://schema.org/InStock',
            ],
        ]);
        $html = "<html><head><script type=\"application/ld+json\">{$json}</script></head><body></body></html>";

        $detected = (new AutoCreateStore('https://shop.test/p', $html))->detect();

        $this->assertSame('schema_org', data_get($detected, 'fields.title.type'));
        $this->assertSame('schema_org', data_get($detected, 'fields.price.type'));
        $this->assertSame('schema_org', data_get($detected, 'fields.image.type'));
        $this->assertSame('schema_org', data_get($detected, 'fields.availability.type'));
        $this->assertSame('Widget', data_get($detected, 'extracted.title'));
        $this->assertNotEmpty(data_get($detected, 'extracted.price'));
        $this->assertSame('https://schema.org/InStock', data_get($detected, 'extracted.availability'));
    }

    public function test_detect_returns_null_when_title_or_price_missing(): void
    {
        $detected = (new AutoCreateStore('https://shop.test/p', '<html><body><div>nothing</div></body></html>'))->detect();

        $this->assertNull($detected);
    }
```

- [ ] **Step 2: Run, verify FAIL** (`Call to undefined method AutoCreateStore::detect()`):

`lando artisan test --compact --filter test_detect tests/Unit/Services/AutoCreateStoreTest.php`

- [ ] **Step 3: Implement.**

Add `parseAvailability()` (e.g. after `parseImage()`):

```php
    protected function parseAvailability(): array
    {
        return $this->attemptSchemaOrg('availability') ?? [];
    }
```

Add `detect()` (e.g. after `getStoreAttributes()`):

```php
    /**
     * Detect a scrape strategy from the (already-fetched) HTML using the deterministic
     * heuristics (schema.org → selector → regex). Adds a schema.org availability field
     * when present. Returns the field strategies plus each extracted value, or null when
     * the required title+price could not be found.
     *
     * @return array{fields: array<string, array<string, string|null>>, extracted: array<string, mixed>}|null
     */
    public function detect(): ?array
    {
        $strategy = $this->strategyParse();
        $schemaOrg = ScraperStrategyType::SchemaOrg->value;

        if (
            (data_get($strategy, 'title.type') !== $schemaOrg && empty(data_get($strategy, 'title.value')))
            || (data_get($strategy, 'price.type') !== $schemaOrg && empty(data_get($strategy, 'price.value')))
        ) {
            return null;
        }

        $strategy['availability'] = $this->parseAvailability();

        $fields = [];
        $extracted = [];

        foreach ($strategy as $field => $parsed) {
            if (empty($parsed) || empty(data_get($parsed, 'type'))) {
                continue;
            }

            $fields[$field] = collect($parsed)->only('type', 'value')->all();
            $extracted[$field] = data_get($parsed, 'data');
        }

        return ['fields' => $fields, 'extracted' => $extracted];
    }
```

Refactor `getStoreAttributes()` to use it. Replace its current body with:

```php
    public function getStoreAttributes(): ?array
    {
        $detected = $this->detect();

        if ($detected === null) {
            $this->errorLog('Unable to auto create store', [
                'url' => $this->url,
                'html' => $this->html,
            ]);

            return null;
        }

        return self::buildAttributes($this->url, $detected['fields']);
    }
```

Do NOT change `strategyParse()` (it stays title/price/image — its exact-output tests depend on it). `availability` is added only inside `detect()`.

- [ ] **Step 4: Run, verify PASS** (2 new tests):

`lando artisan test --compact --filter test_detect tests/Unit/Services/AutoCreateStoreTest.php`

- [ ] **Step 5: Run the full AutoCreateStore suites (no regression)**

`lando artisan test --compact tests/Unit/Services/AutoCreateStoreTest.php tests/Unit/Services/AutoCreateStoreBuildAttributesTest.php`
Expected: PASS. (The `getStoreAttributes` test uses the og-only `basic-meta` fixture, so `parseAvailability` returns `[]` and the strategy stays 3 fields — `assertCount(3)` holds. The 8 `strategyParse` exact-output assertions are untouched.)

- [ ] **Step 6: Style + commit**

```bash
lando phpcs-fix && lando phpcs
git add app/Services/AutoCreateStore.php tests/Unit/Services/AutoCreateStoreTest.php
git commit -m "feat: AutoCreateStore::detect with schema.org availability"
```

---

## Task 2: Healer deterministic-first `resolveConfigForUrl`

**Files:**
- Modify: `app/Services/AiConfigHealer.php`
- Modify: `tests/Feature/Services/AiConfigHealerTest.php`, `tests/Feature/Services/AiConfigHealerBootstrapTest.php` (the `html()` helper)

### Background: why the existing test HTML must change

`AutoCreateStore`'s candidate selectors include `h1`/`title` for title and a bare `~\$(\d+(\.\d{2})?)~` regex for price. The healer tests' `html()` returns `<h1 class="t">Widget</h1><span id="pr">$12.99</span>`, which the heuristics WOULD now detect (title via `h1`, price via the `$` regex), so the deterministic path would intercept them and their mocked agent would never run. Changing the title element from `<h1>` to `<div>` defeats the title heuristic (no `h1`/`title`/`og:title`), so `detect()` returns null and the agent fallback runs — preserving those tests' intent. The agent's `.t` selector still matches `<div class="t">`.

### Step 1: Update the `html()` helper in BOTH test files

In `tests/Feature/Services/AiConfigHealerTest.php` and `tests/Feature/Services/AiConfigHealerBootstrapTest.php`, change the private `html()` method body from:

```php
        return '<html><body><h1 class="t">Widget</h1><span id="pr">$12.99</span></body></html>';
```

to:

```php
        return '<html><body><div class="t">Widget</div><span id="pr">$12.99</span></body></html>';
```

(Also scan each file for any other inline HTML containing `<h1`, `<title`, `og:title`, or `application/ld+json` that would let the heuristics detect a title+price; there should be none beyond `html()`.)

### Step 2: Add `resolveConfigForUrl()`, `detectConfig()`, `applyConfigToStore()`; remove `runAgentForUrl()`

In `app/Services/AiConfigHealer.php`, DELETE the `runAgentForUrl()` method, and add these three methods (e.g. where `runAgentForUrl` was):

```php
    /**
     * Resolve a scrape config for the URL. Tries the deterministic AutoCreateStore
     * heuristics first on the static HTML, then on browser-rendered HTML, and only
     * falls back to the AI agent when the heuristics cannot build a config.
     *
     * @return array{fields: array<string, array<string, mixed>>, extracted: array<string, mixed>, usedBrowser: bool}|null
     */
    protected function resolveConfigForUrl(string $url, ?Store $store, ?string $html, AiProviderConfigDto $provider): ?array
    {
        $context = new HealingContext($url, $store ?? new Store(['settings' => []]), $html);

        if (blank($context->getHtml())) {
            try {
                $context->fetch(false);
            } catch (Throwable $e) {
                $this->log($url)->warning('AI healing could not fetch page HTML.', ['error' => $e->getMessage()]);

                return null;
            }
        }

        // 1. Deterministic heuristics on the static HTML.
        if ($detected = $this->detectConfig($url, (string) $context->getHtml())) {
            $this->log($url)->info('Store config detected deterministically.', ['fields' => array_keys($detected['fields']), 'rendered' => false]);

            return ['fields' => $detected['fields'], 'extracted' => $detected['extracted'], 'usedBrowser' => $context->usedBrowser()];
        }

        // 2. Escalate to browser-rendered HTML and retry the heuristics.
        if (! $context->usedBrowser()) {
            try {
                $context->fetch(true);
            } catch (Throwable $e) {
                $this->log($url)->warning('AI healing could not fetch browser-rendered HTML.', ['error' => $e->getMessage()]);
            }

            if ($context->usedBrowser() && ($detected = $this->detectConfig($url, (string) $context->getHtml()))) {
                $this->log($url)->info('Store config detected deterministically.', ['fields' => array_keys($detected['fields']), 'rendered' => true]);

                return ['fields' => $detected['fields'], 'extracted' => $detected['extracted'], 'usedBrowser' => true];
            }
        }

        // 3. Fall back to the AI agent (it does its own static→browser switching).
        $result = $this->attemptAgentRepair($context, $provider);

        if ($result === null) {
            return null;
        }

        return ['fields' => $result['validated'], 'extracted' => $result['extracted'], 'usedBrowser' => $context->usedBrowser()];
    }

    /**
     * Run the deterministic AutoCreateStore heuristics on already-fetched HTML.
     *
     * @return array{fields: array<string, array<string, mixed>>, extracted: array<string, mixed>}|null
     */
    protected function detectConfig(string $url, string $html): ?array
    {
        return AutoCreateStore::new($url, $html)->setLogErrors(false)->detect();
    }

    /**
     * Apply a resolved config's fields to a store (in memory) and switch it to the
     * browser scraper when the resolution required browser rendering. Caller persists.
     *
     * @param  array{fields: array<string, array<string, mixed>>, usedBrowser: bool}  $config
     */
    protected function applyConfigToStore(Store $store, array $config): void
    {
        $this->applyValidatedSlots($store, $config['fields']);

        if ($config['usedBrowser']) {
            $this->useBrowserScraper($store);
        }
    }
```

### Step 3: Rewrite the `healStoreForUrl` try-block

In `healStoreForUrl()`, replace the entire `try { ... } finally { $lock->release(); }` block with:

```php
        try {
            $config = $this->resolveConfigForUrl($url, $store, $html, $provider);

            if ($config === null) {
                $store?->markAiHealFailed();

                return null;
            }

            if ($store !== null) {
                $this->applyConfigToStore($store, $config);
                $store->clearAiHealFailed();
                $this->log($url)->info('Store scraper config healed.', [
                    'store_id' => $store->getKey(),
                    'fields' => array_keys($config['fields']),
                    'scraper_service' => data_get($store->settings, 'scraper_service'),
                ]);

                return $store;
            }

            $attributes = AutoCreateStore::buildAttributes($url, $config['fields']);

            if ($config['usedBrowser']) {
                data_set($attributes, 'settings.scraper_service', ScraperService::Api->value);
            }

            $created = (new CreateStoreAction)($attributes);

            if ($created !== null) {
                $this->log($url)->info('Store created via self-healing.', [
                    'store_id' => $created->getKey(),
                    'fields' => array_keys($config['fields']),
                ]);
            } else {
                $this->log($url)->warning('Self-healing resolved a config but store creation failed.');
            }

            return $created;
        } finally {
            $lock->release();
        }
```

### Step 4: Rewrite `runHeal()`

Replace the entire `runHeal()` method body with:

```php
    protected function runHeal(Url $url, Store $store, array $scrapeResult, string $html, AiProviderConfigDto $provider): array
    {
        $config = $this->resolveConfigForUrl($url->url, $store, $html, $provider);

        if ($config === null) {
            $store->markAiHealFailed();

            return $scrapeResult;
        }

        $this->applyConfigToStore($store, $config);
        $store->clearAiHealFailed();

        foreach ($config['extracted'] as $field => $value) {
            data_set($scrapeResult, $field, $value);
        }

        $this->log($url->url)->info('Store scraper config healed.', [
            'fields' => array_keys($config['fields']),
            'scraper_service' => data_get($store->settings, 'scraper_service'),
        ]);

        return $scrapeResult;
    }
```

(This also fixes a latent gap: `runHeal` now sets `scraper_service=api` when browser rendering was required, via `applyConfigToStore`.)

### Step 5: Rewrite `previewForUrl()`

Replace the body after the provider null-check with a direct return:

```php
    public function previewForUrl(string $url, ?Store $store, ?string $html = null): ?array
    {
        $provider = IntegrationHelper::resolveFeatureProvider(AiFeature::Healing, $store);

        if ($provider === null) {
            return null;
        }

        return $this->resolveConfigForUrl($url, $store, $html, $provider);
    }
```

### Step 6: Run the existing healer suites — they must stay green via the agent fallback

`lando artisan test --compact tests/Feature/Services/AiConfigHealerTest.php tests/Feature/Services/AiConfigHealerBootstrapTest.php tests/Feature/Models/UrlHealingOrderTest.php tests/Feature/Filament/StoreSelfHealUiTest.php`
Expected: PASS. (With the `div` HTML, `detect()` returns null → the mocked agent runs as before. `StoreSelfHealUiTest` mocks `AiConfigHealer` wholesale, so it is unaffected.)

If a specific test still resolves deterministically (e.g. it has its own inline structured HTML), make that test's HTML non-structured the same way (`div` title, no JSON-LD/og) — do NOT weaken assertions.

### Step 7: Style + commit

```bash
lando phpcs-fix && lando phpcs
git add app/Services/AiConfigHealer.php tests/Feature/Services/AiConfigHealerTest.php tests/Feature/Services/AiConfigHealerBootstrapTest.php
git commit -m "feat: deterministic-first self-healing (heuristics before AI agent)"
```

---

## Task 3: Deterministic-path feature tests

**Files:**
- Test: `tests/Feature/Services/DeterministicHealTest.php`

- [ ] **Step 1: Write the tests**

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
use Jez500\WebScraperForLaravel\Facades\WebScraper;
use Jez500\WebScraperForLaravel\WebScraperFake;
use Tests\TestCase;

class DeterministicHealTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        SettingsHelper::$settings = null;
        Cache::flush();
        Once::flush();

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

    private function schemaOrgHtml(string $availabilityLabel = 'InStock'): string
    {
        $json = json_encode([
            '@context' => 'https://schema.org/',
            '@type' => 'Product',
            'name' => 'Widget',
            'image' => 'https://x.test/w.png',
            'offers' => [
                '@type' => 'Offer',
                'priceCurrency' => 'USD',
                'price' => 48.95,
                'availability' => 'https://schema.org/'.$availabilityLabel,
            ],
        ]);

        return "<html><head><script type=\"application/ld+json\">{$json}</script></head><body></body></html>";
    }

    public function test_heals_deterministically_from_schema_org_without_invoking_the_agent(): void
    {
        $this->mock(AiService::class, fn ($m) => $m->shouldReceive('runAgent')->never());

        $store = AiConfigHealer::new()->healStoreForUrl('https://shop.test/widget', null, $this->schemaOrgHtml());

        $this->assertInstanceOf(Store::class, $store);
        $this->assertSame('schema_org', data_get($store->scrape_strategy, 'price.type'));
        $this->assertSame('schema_org', data_get($store->scrape_strategy, 'availability.type'));
        $this->assertSame('schema_org', data_get($store->scrape_strategy, 'title.type'));
    }

    public function test_preview_returns_schema_org_fields_without_invoking_the_agent(): void
    {
        $this->mock(AiService::class, fn ($m) => $m->shouldReceive('runAgent')->never());

        $preview = AiConfigHealer::new()->previewForUrl('https://shop.test/widget', null, $this->schemaOrgHtml());

        $this->assertSame('schema_org', data_get($preview, 'fields.price.type'));
        $this->assertSame('schema_org', data_get($preview, 'fields.availability.type'));
        $this->assertFalse(data_get($preview, 'usedBrowser'));
    }

    public function test_escalates_to_browser_then_heals_deterministically_when_static_is_blocked(): void
    {
        $this->mock(AiService::class, fn ($m) => $m->shouldReceive('runAgent')->never());

        $blocked = '<html><head><title>Access Denied</title></head><body>blocked</body></html>';
        $schema = $this->schemaOrgHtml();

        // Static (http) returns the blocked page; browser (api) returns the schema.org page.
        WebScraper::shouldReceive('make')->andReturnUsing(
            fn (string $service) => (new WebScraperFake)->setBody($service === 'api' ? $schema : $blocked),
        );

        // html=null forces a static fetch (blocked) → heuristics fail → browser fetch → heuristics succeed.
        $store = AiConfigHealer::new()->healStoreForUrl('https://shop.test/widget', null, null);

        $this->assertInstanceOf(Store::class, $store);
        $this->assertSame('api', data_get($store->settings, 'scraper_service'));
        $this->assertSame('schema_org', data_get($store->scrape_strategy, 'price.type'));
    }
}
```

- [ ] **Step 2: Run, verify PASS (3 tests)**

`lando artisan test --compact tests/Feature/Services/DeterministicHealTest.php`
Expected: PASS. If `test_escalates_to_browser…` fails because the blocked page is heuristically detectable, ensure the blocked HTML has no `og:`/`h1`/`title`-as-product/JSON-LD that yields a title+price (the `<title>Access Denied</title>` provides a title but no price, so `detect()` returns null — verify and adjust only the fixture if needed).

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/Services/DeterministicHealTest.php
git commit -m "test: deterministic schema.org self-healing (no agent) + browser escalation"
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
git add -A && git commit -m "style: phpcs fixes for deterministic self-healing" || echo "nothing to commit"
```

---

## Self-Review

**Spec coverage:**
- §2 `parseAvailability()` (schema.org) + `detect()` (uniform {fields,extracted}, title+price gate) + `getStoreAttributes` refactor → Task 1. ✓
- §3 `resolveConfigForUrl` escalation (static heuristics → browser heuristics → agent), uniform `{fields,extracted,usedBrowser}` → Task 2. ✓
- §4 consumers (`heal`/`healStoreForUrl`/`previewForUrl`) switched, behaviour preserved, api-on-browser (incl. the runHeal gap fix) → Task 2. ✓
- §5 testing (detect schema.org incl availability; deterministic heal/preview no-agent; browser escalation; existing suites green via agent fallback) → Tasks 1–3. ✓
- §6 out of scope (no agent tool, no prompt change, schema.org-only availability) → respected. ✓

**Placeholder scan:** none — every step has full code and exact commands.

**Type consistency:** `detect(): ?array{fields,extracted}`, `resolveConfigForUrl(...): ?array{fields,extracted,usedBrowser}`, `detectConfig(string,string): ?array{fields,extracted}`, `applyConfigToStore(Store, array{fields,usedBrowser})` — consistent. `previewForUrl` returns exactly `resolveConfigForUrl`'s shape (matches its existing `{fields,extracted,usedBrowser}` PHPDoc). The `html()` change (h1→div) is applied in both test files referenced by Task 2.

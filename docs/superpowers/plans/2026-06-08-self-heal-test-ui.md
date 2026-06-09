# Self-Healing Test UI (Preview & Apply) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a "Heal with AI" action to the EditStore Test modal that previews an AI-proposed `scrape_strategy` (selectors + extracted values) without persisting, then applies it into the store form fields for the user to review and Save.

**Architecture:** A new non-persisting `AiConfigHealer::previewForUrl()` (sharing a `runAgentForUrl` core with `healStoreForUrl`) returns the proposal. EditStore gains `previewSelfHeal`/`applySelfHeal`/`discardSelfHeal` Livewire methods writing only to in-memory form state. The Test modal renders the proposal via a new Blade partial with Apply/Discard actions.

**Tech Stack:** Laravel 12, Filament 3 (Livewire), Pest 3, `laravel/ai`. No DB migration. Spec: `docs/superpowers/specs/2026-06-08-self-heal-test-ui-design.md`.

**Conventions:** Run via Lando: single test `lando artisan test --compact <path>`; style `lando phpcs-fix` then `lando phpcs` (reach Pint PASS + PHPStan `[OK] No errors`; NOT host `vendor/bin/pint`). Settings in tests: `SettingsHelper::setSetting('integrated_services', ['ai'=>[...]])` then `SettingsHelper::$settings = null; Cache::flush(); Once::flush();`. Mock the agent with `$this->mock(AiService::class, fn($m)=>$m->shouldReceive('runAgent')->andReturn($proposal))`; mock the healer in UI tests with `$this->mock(AiConfigHealer::class, ...)`.

---

## File Structure

**Modify:**
- `app/Services/AiConfigHealer.php` — extract `runAgentForUrl()`; refactor `healStoreForUrl()` onto it (behaviour unchanged); add public `previewForUrl()`.
- `app/Filament/Resources/StoreResource/Pages/EditStore.php` — `$healPreview` property; `previewSelfHeal()`, `applySelfHeal()`, `discardSelfHeal()`; reset `$healPreview` in the Test action's `mountUsing`.
- `app/Filament/Resources/StoreResource.php` — in `testForm()`, add the "Heal with AI" header action and the "AI healing proposal" section (Apply/Discard) rendering the new partial.

**Create:**
- `resources/views/filament/resources/store-resource/heal-preview.blade.php` — the proposal table.
- Test files per task.

---

## Task 1: `previewForUrl()` + shared `runAgentForUrl()` core

**Files:**
- Modify: `app/Services/AiConfigHealer.php`
- Test: `tests/Feature/Services/AiConfigHealerBootstrapTest.php` (add cases)

### Step 1: Add failing tests

Append these methods to `tests/Feature/Services/AiConfigHealerBootstrapTest.php` (it already has `configureProviders()`, `mockAgent()`, `mockAgentUsingBrowser()`, `html()`, `validProposal()` helpers):

```php
    public function test_preview_returns_proposal_without_persisting(): void
    {
        $this->configureProviders();
        $this->mockAgent($this->validProposal());

        $preview = AiConfigHealer::new()->previewForUrl('https://shop.test/widget', null, $this->html());

        $this->assertSame('#pr', data_get($preview, 'fields.price.value'));
        $this->assertSame('.t', data_get($preview, 'fields.title.value'));
        $this->assertSame('$12.99', data_get($preview, 'extracted.price'));
        $this->assertFalse(data_get($preview, 'usedBrowser'));
        $this->assertDatabaseCount('stores', 0);
    }

    public function test_preview_reports_used_browser_and_persists_nothing(): void
    {
        $this->configureProviders();
        $this->mockAgentUsingBrowser($this->validProposal(), $this->html());

        $preview = AiConfigHealer::new()->previewForUrl('https://shop.test/widget', null, null);

        $this->assertTrue(data_get($preview, 'usedBrowser'));
        $this->assertDatabaseCount('stores', 0);
    }

    public function test_preview_returns_null_when_healing_disabled(): void
    {
        $this->configureProviders(['feature_providers' => ['healing' => '__disabled__']]);
        $this->mockAgent(null, 'never');

        $this->assertNull(AiConfigHealer::new()->previewForUrl('https://shop.test/widget', null, $this->html()));
    }
```

### Step 2: Run, verify FAIL

Run: `lando artisan test --compact --filter test_preview tests/Feature/Services/AiConfigHealerBootstrapTest.php`
Expected: FAIL — `Call to undefined method App\Services\AiConfigHealer::previewForUrl()`.

### Step 3: Extract `runAgentForUrl()` and refactor `healStoreForUrl()`

In `app/Services/AiConfigHealer.php`, inside `healStoreForUrl()`, the current `try { ... } finally { $lock->release(); }` block contains the context build, the fetch-if-blank, and the `attemptAgentRepair` call. Replace the part from `$context = new HealingContext(...)` down to (and including) the `$result = $this->attemptAgentRepair($context, $provider);` + its null-check, so the `try` body becomes:

```php
        try {
            [$context, $result] = $this->runAgentForUrl($url, $store, $html, $provider);

            if ($result === null) {
                $store?->markAiHealFailed();

                return null;
            }

            if ($store !== null) {
                $this->applyValidatedSlots($store, $result['validated']);

                if ($context->usedBrowser()) {
                    $this->useBrowserScraper($store);
                }

                $store->clearAiHealFailed();
                $this->log($url)->info('Store scraper config healed via AI.', [
                    'store_id' => $store->getKey(),
                    'fields' => array_keys($result['validated']),
                    'scraper_service' => data_get($store->settings, 'scraper_service'),
                ]);

                return $store;
            }

            $attributes = AutoCreateStore::buildAttributes($url, $result['validated']);

            if ($context->usedBrowser()) {
                // Static/HTTP scraping was insufficient (e.g. bot-blocked), so the live
                // store must scrape via the browser like the agent did.
                data_set($attributes, 'settings.scraper_service', ScraperService::Api->value);
            }

            $created = (new CreateStoreAction)($attributes);

            if ($created !== null) {
                $this->log($url)->info('Store created via AI self-healing.', [
                    'store_id' => $created->getKey(),
                    'fields' => array_keys($result['validated']),
                ]);
            } else {
                $this->log($url)->warning('AI self-healing validated selectors but store creation failed.');
            }

            return $created;
        } finally {
            $lock->release();
        }
```

Then add these two methods (e.g. directly after `healStoreForUrl()`):

```php
    /**
     * Run the healing agent for a URL and return the proposed config WITHOUT
     * persisting anything — for interactive preview-then-apply UIs. Returns null
     * when the Healing feature is unavailable or the agent produced no usable plan.
     *
     * @return array{fields: array<string, array<string, mixed>>, extracted: array<string, string>, usedBrowser: bool}|null
     */
    public function previewForUrl(string $url, ?Store $store, ?string $html = null): ?array
    {
        $provider = IntegrationHelper::resolveFeatureProvider(AiFeature::Healing, $store);

        if ($provider === null) {
            return null;
        }

        [$context, $result] = $this->runAgentForUrl($url, $store, $html, $provider);

        if ($result === null) {
            return null;
        }

        return [
            'fields' => $result['validated'],
            'extracted' => $result['extracted'],
            'usedBrowser' => $context->usedBrowser(),
        ];
    }

    /**
     * Build a HealingContext for the URL, fetch static HTML when none was supplied,
     * and run the agent. Returns [context, result] where result is the validated
     * proposal from attemptAgentRepair, or null on fetch failure / agent failure.
     *
     * @return array{0: HealingContext, 1: array{validated: array<string, array<string, mixed>>, extracted: array<string, string>}|null}
     */
    protected function runAgentForUrl(string $url, ?Store $store, ?string $html, AiProviderConfigDto $provider): array
    {
        $context = new HealingContext($url, $store ?? new Store(['settings' => []]), $html);

        if (blank($context->getHtml())) {
            try {
                $context->fetch(false);
            } catch (Throwable $e) {
                $this->log($url)->warning('AI healing could not fetch page HTML.', ['error' => $e->getMessage()]);

                return [$context, null];
            }
        }

        return [$context, $this->attemptAgentRepair($context, $provider)];
    }
```

### Step 4: Run, verify PASS + no regression

Run: `lando artisan test --compact tests/Feature/Services/AiConfigHealerBootstrapTest.php tests/Feature/Services/AiConfigHealerTest.php`
Expected: PASS (existing healer/bootstrap tests stay green; the 3 new preview tests pass).

### Step 5: Style + commit

```bash
lando phpcs-fix && lando phpcs
git add app/Services/AiConfigHealer.php tests/Feature/Services/AiConfigHealerBootstrapTest.php
git commit -m "feat: add AiConfigHealer::previewForUrl (non-persisting heal preview)"
```

---

## Task 2: EditStore Livewire methods

**Files:**
- Modify: `app/Filament/Resources/StoreResource/Pages/EditStore.php`
- Test: `tests/Feature/Filament/StoreSelfHealUiTest.php`

### Step 1: Write the failing test

```php
<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\StoreResource\Pages\EditStore;
use App\Models\Store;
use App\Models\User;
use App\Services\AiConfigHealer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class StoreSelfHealUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['email' => 'test@test.com']));
    }

    private function preview(): array
    {
        return [
            'fields' => ['price' => ['type' => 'regex', 'value' => '"price":([0-9.]+)', 'prepend' => '', 'append' => '']],
            'extracted' => ['price' => '48.95'],
            'usedBrowser' => true,
        ];
    }

    public function test_preview_self_heal_populates_heal_preview(): void
    {
        $store = Store::factory()->create(['settings' => ['scraper_service' => 'http']]);

        $this->mock(AiConfigHealer::class, fn ($m) => $m->shouldReceive('previewForUrl')
            ->once()
            ->andReturn($this->preview()));

        Livewire::test(EditStore::class, ['record' => $store->getKey()])
            ->call('previewSelfHeal', 'https://shop.test/p')
            ->assertSet('healPreview.extracted.price', '48.95');
    }

    public function test_preview_self_heal_notifies_when_ai_returns_nothing(): void
    {
        $store = Store::factory()->create(['settings' => ['scraper_service' => 'http']]);

        $this->mock(AiConfigHealer::class, fn ($m) => $m->shouldReceive('previewForUrl')
            ->once()
            ->andReturnNull());

        Livewire::test(EditStore::class, ['record' => $store->getKey()])
            ->call('previewSelfHeal', 'https://shop.test/p')
            ->assertNotified()
            ->assertSet('healPreview', null);
    }

    public function test_apply_self_heal_writes_form_state_without_persisting(): void
    {
        $store = Store::factory()->create([
            'scrape_strategy' => [],
            'settings' => ['scraper_service' => 'http'],
        ]);

        Livewire::test(EditStore::class, ['record' => $store->getKey()])
            ->set('healPreview', $this->preview())
            ->call('applySelfHeal')
            ->assertSet('data.scrape_strategy.price.value', '"price":([0-9.]+)')
            ->assertSet('data.settings.scraper_service', 'api')
            ->assertSet('healPreview', null);

        // Nothing persisted until the user clicks Save.
        $this->assertSame([], $store->fresh()->scrape_strategy);
        $this->assertSame('http', data_get($store->fresh()->settings, 'scraper_service'));
    }

    public function test_discard_self_heal_clears_preview(): void
    {
        $store = Store::factory()->create();

        Livewire::test(EditStore::class, ['record' => $store->getKey()])
            ->set('healPreview', $this->preview())
            ->call('discardSelfHeal')
            ->assertSet('healPreview', null);
    }
}
```

### Step 2: Run, verify FAIL

Run: `lando artisan test --compact tests/Feature/Filament/StoreSelfHealUiTest.php`
Expected: FAIL — method/property not defined (`previewSelfHeal`, `healPreview`, etc.).

### Step 3: Implement on `EditStore`

Add imports near the top of `app/Filament/Resources/StoreResource/Pages/EditStore.php` (with the existing `use` block):

```php
use App\Enums\ScraperService;
use App\Services\AiConfigHealer;
```

Add the property next to the other `test*` properties (after `public ?string $testScraper = null;`):

```php
    /** @var array<string, mixed>|null */
    public ?array $healPreview = null;
```

Add the `healPreview` reset inside the Test action's existing `mountUsing` closure (alongside the `testScrapeResult`/`testAiResult`/`testUrl`/`testScraper` resets):

```php
                    $livewire->healPreview = null;
```

Add these three public methods (e.g. after `compareWithAi()`):

```php
    public function previewSelfHeal(string $url): void
    {
        $this->authorizeAccess();

        if (blank($url)) {
            Notification::make()->title('Enter a product URL first')->warning()->send();

            return;
        }

        $preview = AiConfigHealer::new()->previewForUrl(
            $url,
            $this->buildUnsavedStore(),
            data_get($this->testScrapeResult, 'body'),
        );

        if ($preview === null) {
            Notification::make()->title('AI could not build a working config for this URL')->warning()->send();

            return;
        }

        $this->healPreview = $preview;
    }

    public function applySelfHeal(): void
    {
        $this->authorizeAccess();

        if (blank($this->healPreview)) {
            return;
        }

        foreach (data_get($this->healPreview, 'fields', []) as $field => $slot) {
            data_set($this->data, 'scrape_strategy.'.$field, $slot);
        }

        if (data_get($this->healPreview, 'usedBrowser')) {
            data_set($this->data, 'settings.scraper_service', ScraperService::Api->value);
        }

        $this->healPreview = null;

        Notification::make()->title('Applied to the form — review the fields and Save')->success()->send();
    }

    public function discardSelfHeal(): void
    {
        $this->healPreview = null;
    }
```

### Step 4: Run, verify PASS

Run: `lando artisan test --compact tests/Feature/Filament/StoreSelfHealUiTest.php`
Expected: PASS (4 tests).

### Step 5: Style + commit

```bash
lando phpcs-fix && lando phpcs
git add app/Filament/Resources/StoreResource/Pages/EditStore.php tests/Feature/Filament/StoreSelfHealUiTest.php
git commit -m "feat: EditStore self-heal preview/apply/discard livewire methods"
```

---

## Task 3: Test-modal wiring + heal-preview partial

**Files:**
- Modify: `app/Filament/Resources/StoreResource.php`
- Create: `resources/views/filament/resources/store-resource/heal-preview.blade.php`
- Test: `tests/Feature/Filament/StoreSelfHealUiTest.php` (add cases)

### Step 1: Add failing tests

Append to `tests/Feature/Filament/StoreSelfHealUiTest.php` (add the imports `use App\Services\Helpers\SettingsHelper; use Illuminate\Support\Facades\Cache; use Illuminate\Support\Once;` to the file's `use` block):

```php
    private function enableHealing(): void
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

    public function test_heal_action_hidden_when_healing_disabled(): void
    {
        $store = Store::factory()->create();

        Livewire::test(EditStore::class, ['record' => $store->getKey()])
            ->mountAction('test')
            ->set('testScrapeResult', ['title' => 'X', 'price' => '1', 'body' => '<html></html>'])
            ->assertDontSee('Heal with AI');
    }

    public function test_heal_action_and_proposal_render_when_enabled(): void
    {
        $this->enableHealing();
        $store = Store::factory()->create();

        Livewire::test(EditStore::class, ['record' => $store->getKey()])
            ->mountAction('test')
            ->set('testScrapeResult', ['title' => 'X', 'price' => '1', 'body' => '<html></html>'])
            ->assertSee('Heal with AI')
            ->set('healPreview', $this->preview())
            ->assertSee('48.95')
            ->assertSee('Apply to form')
            ->assertSee('Browser scraping required');
    }
```

### Step 2: Run, verify FAIL

Run: `lando artisan test --compact --filter test_heal_action tests/Feature/Filament/StoreSelfHealUiTest.php`
Expected: FAIL — "Heal with AI" / proposal content not rendered (action and section not wired yet). (`test_heal_action_hidden_when_healing_disabled` may already pass since nothing renders it; the enabled test fails.)

### Step 3: Create the partial `resources/views/filament/resources/store-resource/heal-preview.blade.php`

```blade
@php
    $fields = $preview['fields'] ?? [];
    $extracted = $preview['extracted'] ?? [];
    $usedBrowser = $preview['usedBrowser'] ?? false;
    $labels = ['title' => 'Title', 'price' => 'Price', 'image' => 'Image', 'availability' => 'Availability'];
@endphp

<div>
    @if ($usedBrowser)
        <p class="mb-3 text-sm text-amber-600 dark:text-amber-400">
            {{ __('Browser scraping required — applying will set the scraper service to Api.') }}
        </p>
    @endif

    <table class="w-full text-sm border-collapse table-fixed">
        <thead>
            <tr class="border-b border-gray-200 dark:border-white/10 text-left">
                <th class="py-2 pr-4 font-semibold w-1/4">Field</th>
                <th class="py-2 pr-4 font-semibold w-1/2">Proposed selector</th>
                <th class="py-2 pr-4 font-semibold w-1/4">Extracted</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($labels as $key => $label)
                <tr class="border-b border-gray-100 dark:border-white/5 align-top">
                    <td class="py-2 pr-4 font-medium text-gray-500 dark:text-gray-400">{{ $label }}</td>
                    <td class="py-2 pr-4">
                        @php $slot = $fields[$key] ?? null; @endphp
                        @if ($slot)
                            <span class="text-gray-400">{{ $slot['type'] }}</span>
                            <code class="break-all">{{ $slot['value'] }}</code>
                        @else
                            <span class="text-gray-400">—</span>
                        @endif
                    </td>
                    <td class="py-2 pr-4">
                        @php $val = $extracted[$key] ?? null; @endphp
                        @if (filled($val))<span class="break-words">{{ $val }}</span>@else<span class="text-gray-400">—</span>@endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
```

### Step 4: Wire the action + section into `StoreResource::testForm()`

In `app/Filament/Resources/StoreResource.php`, ensure `use App\Enums\AiFeature;` is imported (add it with the other `use App\Enums\...` imports if absent).

In `testForm()`, the "Results" `Section` has a `->headerActions([...])` array containing the `compareWithAi` action. Add a second action to that array (after `compareWithAi`):

```php
                        FormAction::make('healWithAi')
                            ->label('Heal with AI')
                            ->icon('heroicon-m-wrench-screwdriver')
                            ->visible(fn (): bool => IntegrationHelper::isFeatureEnabled(AiFeature::Healing))
                            ->action(fn (Get $get, EditStore $livewire) => $livewire->previewSelfHeal((string) $get('test_url'))),
```

Then add a new section to the `testForm()` schema array, immediately after the "Results" `Section` (i.e. as the next element of the `array_values(array_filter([...]))` list):

```php
                Section::make('AI healing proposal')
                    ->description('Proposed selectors — review, then apply to the form')
                    ->extraAttributes(['class' => 'mt-4'])
                    ->visible(fn (EditStore $livewire): bool => filled($livewire->healPreview))
                    ->headerActions([
                        FormAction::make('applySelfHeal')
                            ->label('Apply to form')
                            ->icon('heroicon-m-check')
                            ->action(fn (EditStore $livewire) => $livewire->applySelfHeal()),
                        FormAction::make('discardSelfHeal')
                            ->label('Discard')
                            ->color('gray')
                            ->action(fn (EditStore $livewire) => $livewire->discardSelfHeal()),
                    ])
                    ->schema([
                        View::make('filament.resources.store-resource.heal-preview')
                            ->viewData(fn (EditStore $livewire): array => ['preview' => $livewire->healPreview]),
                    ]),
```

### Step 5: Run, verify PASS

Run: `lando artisan test --compact tests/Feature/Filament/StoreSelfHealUiTest.php`
Expected: PASS (6 tests).

### Step 6: Regression — store form/test modal unaffected

Run: `lando artisan test --compact tests/Feature/Filament/StoreTest.php tests/Feature/Filament/StoreTestModalTest.php`
Expected: PASS.

### Step 7: Style + commit

```bash
lando phpcs-fix && lando phpcs
git add app/Filament/Resources/StoreResource.php resources/views/filament/resources/store-resource/heal-preview.blade.php tests/Feature/Filament/StoreSelfHealUiTest.php
git commit -m "feat: Heal with AI action + proposal preview in store test modal"
```

---

## Task 4: Final verification

**Files:** none (verification only)

- [ ] **Step 1: Style + static analysis**

Run: `lando phpcs-fix && lando phpcs`
Expected: Pint PASS, then PHPStan `[OK] No errors`.

- [ ] **Step 2: Full suite in parallel**

Run: `lando artisan test --parallel`
Expected: all green (new tests added to the existing pass count).

- [ ] **Step 3: Commit any style fixes**

```bash
git add -A && git commit -m "style: phpcs fixes for self-heal test UI" || echo "nothing to commit"
```

---

## Self-Review

**Spec coverage:**
- §2 non-persisting `previewForUrl` + shared `runAgentForUrl` core → Task 1. ✓
- §3 EditStore `$healPreview` + `previewSelfHeal`/`applySelfHeal`/`discardSelfHeal` + mountUsing reset → Task 2. ✓
- §4 "Heal with AI" action (gated by `isFeatureEnabled(Healing)`) + proposal section (Apply/Discard) → Task 3. ✓
- §5 `heal-preview.blade.php` (field → proposed selector → extracted; browser note) → Task 3. ✓
- §6 gating/synchronous/no-persist-until-Save → Tasks 1–3 + tests. ✓
- §7 testing (previewForUrl no-persist + disabled→null; healStoreForUrl regression; Livewire preview/apply/visibility) → Tasks 1–3. ✓

**Placeholder scan:** none — every step has full code and exact commands.

**Type consistency:** `previewForUrl(string,?Store,?string=null): ?array{fields,extracted,usedBrowser}`, `runAgentForUrl(...): array{0:HealingContext,1:?array{validated,extracted}}`, `$healPreview` shape `{fields:{field:{type,value,prepend,append}}, extracted:{field:value}, usedBrowser:bool}`, and `previewSelfHeal`/`applySelfHeal`/`discardSelfHeal` are consistent across tasks. The Blade `$preview` viewData key matches `$healPreview`'s shape.

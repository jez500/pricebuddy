# Test results presentation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Improve the store test modal's results: a comparison table (Scraped vs optional AI), an on-demand "Compare with AI" button, a collapsed-by-default raw HTML body, and a wider modal.

**Architecture:** `EditStore` gains a `compareWithAi()` method + `testAiResult` property that runs `AiExtractionService` on the scraped HTML using the store's provider. `StoreResource::testForm` gains a "Compare with AI" button (shown only when AI is configured and a scrape exists) and passes the AI array to the results view. The results blade is rewritten as a comparison table with image thumbnails, resolved availability, an AI column that appears after comparison, and a collapsible raw body.

**Tech Stack:** Laravel 12, Filament 3, Livewire, Pest/PHPUnit, Tailwind v3, Lando (run all artisan/test via `lando`).

**Spec:** `docs/superpowers/specs/2026-06-06-test-results-presentation-design.md`

---

## File structure

- `app/Filament/Resources/StoreResource/Pages/EditStore.php` — `testAiResult` property, `compareWithAi()`, `runScrape()` clears AI result, `mountUsing` resets both, modal width → 5xl.
- `app/Filament/Resources/StoreResource.php` — `testForm()` gains the Compare button + `ai` viewData.
- `resources/views/filament/resources/store-resource/test-results.blade.php` — rewritten as a comparison table + collapsible body.
- `tests/Feature/Filament/StoreTestModalTest.php` — new tests for AI compare + table rendering.

---

## Task 1: AI comparison backend on `EditStore`

**Files:**
- Modify: `app/Filament/Resources/StoreResource/Pages/EditStore.php`
- Test: `tests/Feature/Filament/StoreTestModalTest.php`

- [ ] **Step 1: Write the failing tests**

Add to the top imports of `tests/Feature/Filament/StoreTestModalTest.php` (alongside the existing `use` lines):
```php
use App\Dto\AiExtractionResultDto;
use App\Enums\StockStatus;
use App\Services\AiExtractionService;
```

Append these test methods to the class:
```php
    public function test_compare_with_ai_populates_ai_result(): void
    {
        $this->mockScrape('19.99', 'Widget');
        $store = Store::factory()->create([
            'settings' => ['scraper_service' => 'http'],
            'domains' => [['domain' => 'example.com']],
        ]);

        $this->mock(AiExtractionService::class, fn ($m) => $m->shouldReceive('extract')
            ->once()
            ->andReturn(new AiExtractionResultDto(
                title: 'AI Widget',
                price: 9.5,
                currency: 'USD',
                image: 'https://example.com/ai.png',
                stockStatus: StockStatus::InStock,
                confidence: 0.88,
            )));

        $component = Livewire::test(EditStore::class, ['record' => $store->getKey()])
            ->call('runScrape', 'https://example.com/p')
            ->call('compareWithAi');

        $ai = $component->get('testAiResult');
        $this->assertSame('AI Widget', $ai['title']);
        $this->assertSame(9.5, $ai['price']);
        $this->assertSame('USD', $ai['currency']);
        $this->assertSame(StockStatus::InStock->getLabel(), $ai['availability']);
        $this->assertSame(0.88, $ai['confidence']);
    }

    public function test_compare_with_ai_does_nothing_without_a_scrape(): void
    {
        $store = Store::factory()->create(['settings' => ['scraper_service' => 'http']]);
        $this->mock(AiExtractionService::class, fn ($m) => $m->shouldReceive('extract')->never());

        $component = Livewire::test(EditStore::class, ['record' => $store->getKey()])
            ->call('compareWithAi');

        $this->assertNull($component->get('testAiResult'));
    }

    public function test_run_scrape_clears_previous_ai_result(): void
    {
        $this->mockScrape('19.99', 'Widget');
        $store = Store::factory()->create([
            'settings' => ['scraper_service' => 'http'],
            'domains' => [['domain' => 'example.com']],
        ]);

        $component = Livewire::test(EditStore::class, ['record' => $store->getKey()])
            ->set('testAiResult', ['title' => 'stale'])
            ->call('runScrape', 'https://example.com/p');

        $this->assertNull($component->get('testAiResult'));
    }
```

- [ ] **Step 2: Run to verify they fail**

Run: `lando artisan test --compact --filter="compare_with_ai_populates_ai_result|compare_with_ai_does_nothing_without_a_scrape|run_scrape_clears_previous_ai_result"`
Expected: FAIL — `compareWithAi` / `testAiResult` do not exist.

- [ ] **Step 3: Implement on `EditStore`**

In `app/Filament/Resources/StoreResource/Pages/EditStore.php`, add imports:
```php
use App\Services\AiExtractionService;
use App\Services\Helpers\IntegrationHelper;
use Filament\Notifications\Notification;
```
Add the property after the existing `public ?array $testScrapeResult = null;`:
```php
    /** @var array<string, mixed>|null */
    public ?array $testAiResult = null;
```
In `runScrape()`, add a line clearing the AI result after assigning the scrape result, so the method body is:
```php
    public function runScrape(string $url): void
    {
        $this->authorizeAccess();

        $scrape = ScrapeUrl::new($url)->scrape([
            'store' => $this->buildUnsavedStore(),
            'use_cache' => false,
        ]);

        $this->testScrapeResult = $scrape;
        $this->testAiResult = null;
    }
```
Add the `compareWithAi()` method (e.g. after `runScrape()`):
```php
    public function compareWithAi(): void
    {
        $this->authorizeAccess();

        $body = data_get($this->testScrapeResult, 'body');

        if (blank($body)) {
            return;
        }

        $provider = IntegrationHelper::getAiProvider($this->buildUnsavedStore()->ai_provider_id);

        $result = AiExtractionService::new()->extract((string) $body, provider: $provider);

        if ($result === null) {
            Notification::make()->title('AI could not extract any data')->warning()->send();

            return;
        }

        $this->testAiResult = [
            'title' => $result->title,
            'price' => $result->price,
            'currency' => $result->currency,
            'image' => $result->image,
            'availability' => $result->stockStatus?->getLabel(),
            'confidence' => $result->confidence,
        ];
    }
```
In `getHeaderActions()`, change the modal width and the `mountUsing` reset:
```php
                ->modalWidth(MaxWidth::FiveExtraLarge)
                ->mountUsing(function (EditStore $livewire): void {
                    $livewire->testScrapeResult = null;
                    $livewire->testAiResult = null;
                })
```
(Leave the rest of the `test` action — `->form(...)` etc. — unchanged.)

- [ ] **Step 4: Run to verify they pass**

Run: `lando artisan test --compact --filter="compare_with_ai_populates_ai_result|compare_with_ai_does_nothing_without_a_scrape|run_scrape_clears_previous_ai_result"`
Expected: PASS (3 tests). If `$ai['availability']` mismatches, check `StockStatus::InStock->getLabel()` — assert against that exact method (the test already does), do not hardcode a string.

- [ ] **Step 5: Pint + commit**
```bash
vendor/bin/pint --dirty
git add app/Filament/Resources/StoreResource/Pages/EditStore.php tests/Feature/Filament/StoreTestModalTest.php
git commit -m "feat: on-demand AI comparison in store test modal (EditStore::compareWithAi)

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 2: Compare button + comparison-table results view

**Files:**
- Modify: `app/Filament/Resources/StoreResource.php` (`testForm`)
- Modify: `resources/views/filament/resources/store-resource/test-results.blade.php`
- Test: `tests/Feature/Filament/StoreTestModalTest.php`

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/Filament/StoreTestModalTest.php` imports:
```php
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
Append these tests:
```php
    public function test_scraped_results_render_in_the_table(): void
    {
        $this->mockScrape('19.99', 'Widget');
        $store = Store::factory()->create([
            'settings' => ['scraper_service' => 'http'],
            'domains' => [['domain' => 'example.com']],
        ]);

        Livewire::test(EditStore::class, ['record' => $store->getKey()])
            ->mountAction('test')
            ->call('runScrape', 'https://example.com/p')
            ->assertSee('Scraped'); // results table header — only the results table renders this
    }

    public function test_compare_with_ai_renders_the_ai_column(): void
    {
        $this->configureAi();
        $this->mockScrape('19.99', 'Widget');
        $store = Store::factory()->create([
            'settings' => ['scraper_service' => 'http'],
            'domains' => [['domain' => 'example.com']],
        ]);

        $this->mock(AiExtractionService::class, fn ($m) => $m->shouldReceive('extract')
            ->once()->andReturn(new AiExtractionResultDto(title: 'AI Widget', price: 9.5, confidence: 0.88)));

        Livewire::test(EditStore::class, ['record' => $store->getKey()])
            ->mountAction('test')
            ->call('runScrape', 'https://example.com/p')
            ->call('compareWithAi')
            ->assertSee('AI Widget'); // value only present in the AI column
    }

    public function test_compare_button_hidden_when_ai_not_configured(): void
    {
        $this->mockScrape('19.99', 'Widget');
        $store = Store::factory()->create([
            'settings' => ['scraper_service' => 'http'],
            'domains' => [['domain' => 'example.com']],
        ]);

        Livewire::test(EditStore::class, ['record' => $store->getKey()])
            ->mountAction('test')
            ->call('runScrape', 'https://example.com/p')
            ->assertDontSee('Compare with AI');
    }

    public function test_compare_button_shown_when_ai_configured_after_scrape(): void
    {
        $this->configureAi();
        $this->mockScrape('19.99', 'Widget');
        $store = Store::factory()->create([
            'settings' => ['scraper_service' => 'http'],
            'domains' => [['domain' => 'example.com']],
        ]);

        Livewire::test(EditStore::class, ['record' => $store->getKey()])
            ->mountAction('test')
            ->call('runScrape', 'https://example.com/p')
            ->assertSee('Compare with AI');
    }
```

- [ ] **Step 2: Run to verify they fail**

Run: `lando artisan test --compact --filter="scraped_results_render_in_the_table|compare_with_ai_renders_the_ai_column|compare_button_hidden_when_ai_not_configured|compare_button_shown_when_ai_configured_after_scrape"`
Expected: FAIL — no "Scraped" table header, no "Compare with AI" button, no AI column yet.

- [ ] **Step 3: Add the Compare button + `ai` viewData in `testForm`**

In `app/Filament/Resources/StoreResource.php`, add import:
```php
use App\Services\Helpers\IntegrationHelper;
```
In `testForm()`, inside the `->schema(array_values(array_filter([ ... ])))` array, insert a Compare-with-AI `Actions` row **between** the `TextInput::make('test_url')...` block and the `View::make(...)` block:
```php
                    Actions::make([
                        FormAction::make('compareWithAi')
                            ->label('Compare with AI')
                            ->icon('heroicon-m-sparkles')
                            ->action(fn (EditStore $livewire) => $livewire->compareWithAi()),
                    ])
                        ->visible(fn (EditStore $livewire): bool => IntegrationHelper::isAiEnabled() && filled($livewire->testScrapeResult)),
```
And update the `View` component's `viewData` to include the AI array:
```php
                    View::make('filament.resources.store-resource.test-results')
                        ->visible(fn (EditStore $livewire): bool => filled($livewire->testScrapeResult))
                        ->viewData(fn (EditStore $livewire): array => [
                            'scrape' => $livewire->testScrapeResult,
                            'ai' => $livewire->testAiResult,
                            'record' => $livewire->buildUnsavedStore(),
                        ]),
```

- [ ] **Step 4: Rewrite the results blade**

Replace the entire contents of `resources/views/filament/resources/store-resource/test-results.blade.php` with:
```blade
@php
    $ai = $ai ?? null;
    $hasAi = filled($ai);

    $fields = [
        'title' => 'Title',
        'price' => 'Price',
        'currency' => 'Currency',
        'image' => 'Image',
        'availability' => 'Availability',
        'description' => 'Description',
    ];

    $isUrl = fn ($v): bool => is_string($v) && (str_starts_with($v, 'http://') || str_starts_with($v, 'https://'));

    // Resolve scraped availability against the (unsaved) store config.
    $availabilityVal = data_get($scrape, 'availability');
    $matchConfig = data_get($record, 'scrape_strategy.availability.match');
    $resolvedStatus = \App\Enums\StockStatus::matchFromScrapedValue($availabilityVal, $matchConfig);

    $matchedRule = null;
    if (is_array($matchConfig)) {
        foreach ($matchConfig as $statusValue => $matchEntry) {
            if ($statusValue === 'default' || $matchEntry === '' || $matchEntry === null) {
                continue;
            }
            if (is_array($matchEntry)) {
                $matchValue = $matchEntry['value'] ?? '';
                $matchType = $matchEntry['type'] ?? 'match';
                if ($matchValue !== '' && \App\Enums\StockStatus::tryFrom($statusValue)?->value === $resolvedStatus->value) {
                    $matchedRule = $matchType === 'regex' ? "regex \"$matchValue\"" : "exact \"$matchValue\"";
                    break;
                }
            } elseif (is_string($matchEntry) && trim((string) $availabilityVal) === trim($matchEntry)) {
                $matchedRule = "exact \"$matchEntry\"";
                break;
            }
        }
    }
@endphp

<div>
    @if (empty($scrape))
        <p class="my-6">{{ __('Unable to find any data, check store settings') }}</p>
    @else
        <table class="w-full text-sm border-collapse">
            <thead>
                <tr class="border-b border-gray-200 dark:border-white/10 text-left">
                    <th class="py-2 pr-4 font-semibold w-32">Field</th>
                    <th class="py-2 pr-4 font-semibold">Scraped</th>
                    @if ($hasAi)
                        <th class="py-2 pr-4 font-semibold">AI ✨</th>
                    @endif
                </tr>
            </thead>
            <tbody>
                @foreach ($fields as $key => $label)
                    <tr class="border-b border-gray-100 dark:border-white/5 align-top">
                        <td class="py-2 pr-4 font-medium text-gray-500 dark:text-gray-400">{{ $label }}</td>

                        <td class="py-2 pr-4">
                            @php $scrapedVal = $key === 'currency' ? null : data_get($scrape, $key); @endphp
                            @if ($key === 'image' && $isUrl($scrapedVal))
                                <img src="{{ $scrapedVal }}" alt="" class="h-16 w-16 rounded object-contain bg-white" />
                            @elseif ($key === 'availability' && filled($scrapedVal))
                                {{ $resolvedStatus->getLabel() }}@if ($matchedRule) <span class="text-gray-400">— matched {{ $matchedRule }}</span>@elseif ($resolvedStatus === \App\Enums\StockStatus::InStock) <span class="text-gray-400">— no match (default)</span>@endif
                            @elseif (filled($scrapedVal))
                                <span class="break-words">{{ $scrapedVal }}</span>
                            @else
                                <span class="text-gray-400">—</span>
                            @endif
                        </td>

                        @if ($hasAi)
                            <td class="py-2 pr-4">
                                @php $aiVal = $key === 'description' ? null : data_get($ai, $key); @endphp
                                @if ($key === 'image' && $isUrl($aiVal))
                                    <img src="{{ $aiVal }}" alt="" class="h-16 w-16 rounded object-contain bg-white" />
                                @elseif (filled($aiVal))
                                    <span class="break-words">{{ $aiVal }}</span>
                                @else
                                    <span class="text-gray-400">—</span>
                                @endif
                            </td>
                        @endif
                    </tr>
                @endforeach

                @if ($hasAi)
                    <tr class="border-b border-gray-100 dark:border-white/5">
                        <td class="py-2 pr-4 font-medium text-gray-500 dark:text-gray-400">Confidence</td>
                        <td class="py-2 pr-4"><span class="text-gray-400">—</span></td>
                        <td class="py-2 pr-4">{{ number_format((float) data_get($ai, 'confidence', 0), 2) }}</td>
                    </tr>
                @endif
            </tbody>
        </table>

        @if (filled(data_get($scrape, 'errors')))
            <div class="mt-6">
                <x-filament::section heading="Errors">
                    <code class="block whitespace-pre-wrap break-all text-xs">{{ json_encode(data_get($scrape, 'errors'), JSON_PRETTY_PRINT) }}</code>
                </x-filament::section>
            </div>
        @endif

        @if (filled(data_get($scrape, 'body')))
            <div class="mt-6">
                <x-filament::section heading="Raw HTML body" collapsible collapsed>
                    <code class="block whitespace-pre-wrap break-all max-h-96 overflow-auto text-xs">{{ data_get($scrape, 'body') }}</code>
                </x-filament::section>
            </div>
        @endif
    @endif
</div>
```

- [ ] **Step 5: Run to verify they pass**

Run: `lando artisan test --compact --filter="scraped_results_render_in_the_table|compare_with_ai_renders_the_ai_column|compare_button_hidden_when_ai_not_configured|compare_button_shown_when_ai_configured_after_scrape"`
Expected: PASS (4 tests).

Run the whole modal suite to confirm no regression:
Run: `lando artisan test --compact --filter=StoreTestModalTest`
Expected: PASS (all tests).

- [ ] **Step 6: Pint + commit**
```bash
vendor/bin/pint --dirty
git add app/Filament/Resources/StoreResource.php resources/views/filament/resources/store-resource/test-results.blade.php tests/Feature/Filament/StoreTestModalTest.php
git commit -m "feat: comparison-table test results with AI column and collapsible body

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 3: Standards + full suite + manual check

**Files:** none (verification only)

- [ ] **Step 1: Coding standards**

Run: `lando phpcs-fix && lando phpcs`
Expected: Pint PASS, then PHPStan `[OK] No errors`. (A Pint failure short-circuits before PHPStan — reach the PHPStan `[OK]`.)

- [ ] **Step 2: Full suite**

Run: `lando artisan test --parallel`
Expected: all green.

- [ ] **Step 3: Commit any standards fixes**
```bash
git add -A
git commit -m "style: phpcs fixes for test results presentation"
```
(Skip if nothing changed.)

- [ ] **Step 4: Manual check (if Playwright MCP available)**

Base `http://price-buddy.lndo.site/admin`, `test@test.com` / `password`. Open a store's edit page → **Test**: confirm the modal is wider, results render as a table, the raw HTML body is collapsed (click to expand). With AI configured, click **Compare with AI** and confirm the AI column appears with values + a confidence row. If the MCP is unavailable, note it; automated tests cover the data + rendering paths.

---

## Self-review notes

- **Spec §1 (wider modal):** Task 1 Step 3 (`MaxWidth::FiveExtraLarge`).
- **Spec §2 (comparison table):** Task 2 Step 4 (blade) + Step 1 tests.
- **Spec §3 (on-demand AI):** Task 1 (`compareWithAi`, `testAiResult`, resets) + Task 2 (Compare button).
- **Spec §4 (confidence row):** Task 2 blade.
- **Spec §5 (collapsible body / errors):** Task 2 blade.
- **Spec testing section:** Tasks 1–2 tests; Task 3 phpcs + parallel + manual.
- **Type consistency:** `compareWithAi(): void`, public `?array $testAiResult`, AI array keys `title/price/currency/image/availability/confidence` — written identically in `compareWithAi`, the viewData, the blade, and the tests. `testForm` viewData passes `scrape`/`ai`/`record`; the blade reads exactly those.
- **Ordering safety:** Task 1 adds backend (button not yet wired — method tested directly, app consistent); Task 2 adds the button + viewData `ai` + blade together (blade's `$ai` provided same task), app consistent.

# Test modal refinements Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Six refinements to the store test modal: AI compare extracts description too, a test-only scraper dropdown, a modal description, a host-based URL placeholder, and Results-section gutter + header description.

**Architecture:** `AiExtractionService`/`AiExtractionResultDto` gain a `description` field (shared extractor; price pipeline ignores it). `EditStore::runScrape()` gains a `?string $scraper` override applied to the in-memory unsaved store. `StoreResource::testForm()` adds a `Scraper` select + URL placeholder + Results-section description/gutter; the `test` action gets a `modalDescription`.

**Tech Stack:** Laravel 12, Filament 3, Livewire, Pest/PHPUnit, Lando.

**Spec:** `docs/superpowers/specs/2026-06-06-test-modal-refinements-design.md`

---

## File structure

- `app/Services/AiExtractionService.php` — add `description` to prompt + schema + DTO mapping.
- `app/Dto/AiExtractionResultDto.php` — add `?string $description`.
- `app/Filament/Resources/StoreResource/Pages/EditStore.php` — `compareWithAi` maps description; `runScrape` scraper override; `test` action `modalDescription`.
- `app/Filament/Resources/StoreResource.php` — `testForm`: scraper select, URL placeholder, Results-section description + gutter, callers pass scraper.
- `resources/views/.../test-results.blade.php` — render AI description cell.
- Tests: `AiExtractionServiceTest`, `StoreTestModalTest`.

---

## Task 1: AI extraction includes description

**Files:**
- Modify: `app/Services/AiExtractionService.php`
- Modify: `app/Dto/AiExtractionResultDto.php`
- Test: `tests/Feature/Services/AiExtractionServiceTest.php`

- [ ] **Step 1: Update the mapping test**

In `tests/Feature/Services/AiExtractionServiceTest.php`, in `test_maps_a_structured_ai_result_to_a_dto`, add a `description` to the mocked structured array and assert it maps. Change the mocked return to include `'description' => 'A small widget',` and add after the other asserts:
```php
        $this->assertSame('A small widget', $result->description);
```

- [ ] **Step 2: Run to verify it fails**

Run: `lando artisan test --compact --filter=test_maps_a_structured_ai_result_to_a_dto`
Expected: FAIL — `$result->description` is undefined / null.

- [ ] **Step 3: Add `description` to the DTO**

In `app/Dto/AiExtractionResultDto.php`, add a property to the constructor (after `title`):
```php
    public function __construct(
        public ?string $title = null,
        public ?string $description = null,
        public ?float $price = null,
        public ?string $currency = null,
        public ?string $image = null,
        public ?StockStatus $stockStatus = null,
        public float $confidence = 0.0,
    ) {}
```

- [ ] **Step 4: Add `description` to the extractor (prompt + schema + mapping)**

In `app/Services/AiExtractionService.php`:

Add a rule line to `EXTRACTION_PROMPT` (before the `confidence` line):
```
        - description: a short product description if present.
```

Add to the structured schema closure (after `'name' => $schema->string(),`):
```php
                'description' => $schema->string(),
```

In the `return new AiExtractionResultDto(...)` call, add (after `title:`):
```php
            description: $result['description'] ?? null,
```

- [ ] **Step 5: Run to verify it passes**

Run: `lando artisan test --compact --filter=AiExtractionServiceTest`
Expected: PASS (all extraction-service tests).

- [ ] **Step 6: Pint + commit**
```bash
vendor/bin/pint --dirty
git add app/Services/AiExtractionService.php app/Dto/AiExtractionResultDto.php tests/Feature/Services/AiExtractionServiceTest.php
git commit -m "feat: AI extraction returns a product description

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 2: Compare maps description + results table shows it

**Files:**
- Modify: `app/Filament/Resources/StoreResource/Pages/EditStore.php` (`compareWithAi`)
- Modify: `resources/views/filament/resources/store-resource/test-results.blade.php`
- Test: `tests/Feature/Filament/StoreTestModalTest.php`

- [ ] **Step 1: Update the AI-column test**

In `tests/Feature/Filament/StoreTestModalTest.php`, `test_compare_with_ai_renders_the_ai_column`: change the mocked DTO to include a description and assert it renders. Replace the `->andReturn(new AiExtractionResultDto(...))` with:
```php
            ->once()->andReturn(new AiExtractionResultDto(title: 'AI Widget', description: 'AI description text', price: 9.5, confidence: 0.88)));
```
and add to the chain after `->assertSee('AI Widget')`:
```php
            ->assertSee('AI description text');
```

- [ ] **Step 2: Run to verify it fails**

Run: `lando artisan test --compact --filter=test_compare_with_ai_renders_the_ai_column`
Expected: FAIL — the blade suppresses the AI description and `compareWithAi` doesn't map it.

- [ ] **Step 3: Map description in `compareWithAi`**

In `app/Filament/Resources/StoreResource/Pages/EditStore.php`, in `compareWithAi()`'s `$this->testAiResult = [...]` array, add (after `'title' => $result->title,`):
```php
            'description' => $result->description,
```

- [ ] **Step 4: Render the AI description cell**

In `resources/views/filament/resources/store-resource/test-results.blade.php`, find the AI cell line:
```php
                            @php $aiVal = $key === 'description' ? null : data_get($ai, $key); @endphp
```
Replace it with:
```php
                            @php $aiVal = data_get($ai, $key); @endphp
```
(Leave the scraped cell's `$key === 'currency' ? null` line unchanged.)

- [ ] **Step 5: Run to verify it passes**

Run: `lando artisan test --compact --filter=StoreTestModalTest`
Expected: PASS (all modal tests).

- [ ] **Step 6: Pint + commit**
```bash
vendor/bin/pint --dirty
git add app/Filament/Resources/StoreResource/Pages/EditStore.php resources/views/filament/resources/store-resource/test-results.blade.php tests/Feature/Filament/StoreTestModalTest.php
git commit -m "feat: show AI description in test comparison table

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 3: Scraper dropdown + per-test scraper override

**Files:**
- Modify: `app/Filament/Resources/StoreResource/Pages/EditStore.php` (`runScrape`)
- Modify: `app/Filament/Resources/StoreResource.php` (`testForm`: select + caller args)
- Test: `tests/Feature/Filament/StoreTestModalTest.php`

- [ ] **Step 1: Write the failing test**

In `tests/Feature/Filament/StoreTestModalTest.php`, add import:
```php
use App\Services\ScrapeUrl;
```
Append:
```php
    public function test_run_scrape_uses_the_selected_scraper(): void
    {
        $store = Store::factory()->create([
            'settings' => ['scraper_service' => 'http'], // saved as http
            'domains' => [['domain' => 'example.com']],
        ]);

        // Capture the store passed to the scraper and assert the override took effect.
        $this->mock(ScrapeUrl::class, function ($m) {
            $m->shouldReceive('scrape')->once()
                ->withArgs(fn (array $opts): bool => $opts['store']->scraper_service === 'api')
                ->andReturn(['title' => 'Widget', 'price' => '9.99', 'body' => '<html>']);
        });

        $component = Livewire::test(EditStore::class, ['record' => $store->getKey()])
            ->call('runScrape', 'https://example.com/p', 'api');

        $this->assertSame('9.99', data_get($component->get('testScrapeResult'), 'price'));
    }
```

- [ ] **Step 2: Run to verify it fails**

Run: `lando artisan test --compact --filter=test_run_scrape_uses_the_selected_scraper`
Expected: FAIL — `runScrape` doesn't accept a scraper arg / doesn't apply it (the real `ScrapeUrl` would also try to run, but the `withArgs` store assertion fails because the override isn't applied).

- [ ] **Step 3: Add the scraper override to `runScrape`**

In `app/Filament/Resources/StoreResource/Pages/EditStore.php`, replace `runScrape()` with:
```php
    public function runScrape(string $url, ?string $scraper = null): void
    {
        $this->authorizeAccess();

        $store = $this->buildUnsavedStore();

        if (filled($scraper)) {
            $store->settings = array_merge((array) $store->settings, ['scraper_service' => $scraper]);
        }

        $scrape = ScrapeUrl::new($url)->scrape([
            'store' => $store,
            'use_cache' => false,
        ]);

        $this->testScrapeResult = $scrape;
        $this->testAiResult = null;
    }
```

- [ ] **Step 4: Run to verify it passes**

Run: `lando artisan test --compact --filter=test_run_scrape_uses_the_selected_scraper`
Expected: PASS.

- [ ] **Step 5: Add the Scraper select + pass it from the callers**

In `app/Filament/Resources/StoreResource.php` `testForm()`:

Add the `Select` immediately after the `TextInput::make('test_url')...->suffixAction(...)` block (before the `Results` Section):
```php
                Select::make('test_scraper')
                    ->label('Scraper')
                    ->options(ScraperService::class)
                    ->default(fn (): string => (string) data_get($store, 'settings.scraper_service', ScraperService::Http->value))
                    ->selectablePlaceholder(false),
```
(`Select` and `ScraperService` are already imported in this file.)

Update the **product shortcut** button action to pass the selected scraper — change:
```php
                            ->action(fn (EditStore $livewire) => $livewire->runScrape($url->url))
```
to:
```php
                            ->action(fn (Get $get, EditStore $livewire) => $livewire->runScrape($url->url, $get('test_scraper')))
```

Update the **inline scrape suffix action** closure — change:
```php
                                if (filled($url)) {
                                    $livewire->runScrape($url);
                                }
```
to:
```php
                                if (filled($url)) {
                                    $livewire->runScrape($url, $get('test_scraper'));
                                }
```

- [ ] **Step 6: Run the modal suite**

Run: `lando artisan test --compact --filter=StoreTestModalTest`
Expected: PASS (the existing product-button and inline-scrape tests still pass; `$get('test_scraper')` resolves to the select's default and the override is a no-op when it equals the store value).

- [ ] **Step 7: Pint + commit**
```bash
vendor/bin/pint --dirty
git add app/Filament/Resources/StoreResource/Pages/EditStore.php app/Filament/Resources/StoreResource.php tests/Feature/Filament/StoreTestModalTest.php
git commit -m "feat: test-only scraper dropdown overrides the scraper for the test run

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 4: Modal description, URL placeholder, Results gutter + header description

**Files:**
- Modify: `app/Filament/Resources/StoreResource/Pages/EditStore.php` (`test` action)
- Modify: `app/Filament/Resources/StoreResource.php` (`testForm`)
- Test: `tests/Feature/Filament/StoreTestModalTest.php`

- [ ] **Step 1: Write the failing tests**

In `tests/Feature/Filament/StoreTestModalTest.php`, append:
```php
    public function test_modal_description_without_ai(): void
    {
        $store = Store::factory()->create(['settings' => ['scraper_service' => 'http']]);

        Livewire::test(EditStore::class, ['record' => $store->getKey()])
            ->mountAction('test')
            ->assertSee('Dry run the current store settings')
            ->assertDontSee('and compare with AI');
    }

    public function test_modal_description_with_ai(): void
    {
        $this->configureAiProvider();
        $store = Store::factory()->create(['settings' => ['scraper_service' => 'http']]);

        Livewire::test(EditStore::class, ['record' => $store->getKey()])
            ->mountAction('test')
            ->assertSee('Dry run the current store settings and compare with AI');
    }
```

- [ ] **Step 2: Run to verify they fail**

Run: `lando artisan test --compact --filter="test_modal_description_without_ai|test_modal_description_with_ai"`
Expected: FAIL — no modal description yet.

- [ ] **Step 3: Add the modal description**

In `app/Filament/Resources/StoreResource/Pages/EditStore.php`, on the `test` action (after `->modalHeading('Test store')`), add:
```php
                ->modalDescription(fn (): string => 'Dry run the current store settings'
                    .(IntegrationHelper::isAiEnabled() ? ' and compare with AI' : ''))
```
(`IntegrationHelper` is already imported in this file.)

- [ ] **Step 4: Add the URL placeholder + Results gutter and description**

In `app/Filament/Resources/StoreResource.php` `testForm()`:

On the `test_url` TextInput, add (e.g. after `->label(...)`):
```php
                    ->placeholder(fn (): ?string => filled($host = data_get($store, 'domains.0.domain'))
                        ? 'https://'.$host.'/example-product'
                        : null)
```

On the `Results` `Section`, add a description and a top gutter. Change the section opening from:
```php
                Section::make('Results')
                    ->visible(fn (EditStore $livewire): bool => filled($livewire->testScrapeResult))
                    ->headerActions([
```
to:
```php
                Section::make('Results')
                    ->description('What we could find')
                    ->extraAttributes(['class' => 'mt-4'])
                    ->visible(fn (EditStore $livewire): bool => filled($livewire->testScrapeResult))
                    ->headerActions([
```

- [ ] **Step 5: Run to verify they pass**

Run: `lando artisan test --compact --filter="test_modal_description_without_ai|test_modal_description_with_ai"`
Expected: PASS.
Then: `lando artisan test --compact --filter=StoreTestModalTest` — expect ALL pass.

- [ ] **Step 6: Pint + commit**
```bash
vendor/bin/pint --dirty
git add app/Filament/Resources/StoreResource/Pages/EditStore.php app/Filament/Resources/StoreResource.php tests/Feature/Filament/StoreTestModalTest.php
git commit -m "feat: test modal description, URL placeholder, Results gutter + header description

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 5: Standards + full suite

**Files:** none (verification only)

- [ ] **Step 1: Standards** — Run: `lando phpcs-fix && lando phpcs` — expect Pint PASS then PHPStan `[OK] No errors`.
- [ ] **Step 2: Full suite** — Run: `lando artisan test --parallel` — expect all green (re-run once if a known-flaky Telegram channel test trips under parallel load; confirm it passes in isolation).
- [ ] **Step 3: Commit any standards fixes**
```bash
git add -A
git commit -m "style: phpcs fixes for test modal refinements"
```
(Skip if nothing changed.)

---

## Self-review notes

- **Spec §1 (AI description):** Tasks 1 + 2.
- **Spec §2 (scraper dropdown + override):** Task 3.
- **Spec §3 (modal description):** Task 4.
- **Spec §4 (URL placeholder):** Task 4.
- **Spec §5 (Results gutter):** Task 4.
- **Spec §6 (Results header description):** Task 4.
- **Spec testing:** description mapping (Task 1), AI description render (Task 2), scraper override behavioural test via `ScrapeUrl` mock (Task 3), modal description text (Task 4). The select-renders assertion is intentionally omitted — `ScraperService` enum labels collide with the edit form's existing scraper radio, so `assertSee` can't isolate the new select; the behavioural override test covers the feature.
- **Type consistency:** `AiExtractionResultDto` gains `?string $description` (named-arg call sites unaffected); `runScrape(string $url, ?string $scraper = null)`; `testAiResult` key `description`; blade reads `data_get($ai, 'description')`. All aligned.
- **Ordering:** Task 1 adds the field (unused downstream yet); Task 2 consumes it; Task 3 adds the scraper override + select together (callers + method same task); Task 4 is independent cosmetic + modal description.

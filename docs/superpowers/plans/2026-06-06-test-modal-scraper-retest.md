# Test modal reactive scraper re-test Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move the test-modal scraper select into the Results section (below the raw body), default it to the scraper in effect, re-test on change, and make the results columns equal thirds.

**Architecture:** `EditStore::runScrape()` remembers the URL + scraper used on public properties. The `test_scraper` select moves into the (results-gated) Results section as a `->live()` field that re-runs `runScrape($testUrl, $newScraper)` on change (fresh fetch, `use_cache=false`), defaulting to the current scraper via `afterStateHydrated`. The results blade table becomes fixed-layout with `w-1/3` columns.

**Tech Stack:** Laravel 12, Filament 3, Livewire, Pest/PHPUnit, Lando.

**Spec:** `docs/superpowers/specs/2026-06-06-test-modal-scraper-retest-design.md`

---

## File structure

- `app/Filament/Resources/StoreResource/Pages/EditStore.php` — `runScrape` records `$testUrl` + `$testScraper`.
- `app/Filament/Resources/StoreResource.php` — `testForm`: remove the standalone scraper select; add a reactive "Change scraper" select as the last element of the Results section.
- `resources/views/filament/resources/store-resource/test-results.blade.php` — table `table-fixed` + `w-1/3` headers.
- Test: `tests/Feature/Filament/StoreTestModalTest.php`.

---

## Task 1: `runScrape` remembers the URL + scraper used

**Files:**
- Modify: `app/Filament/Resources/StoreResource/Pages/EditStore.php`
- Test: `tests/Feature/Filament/StoreTestModalTest.php`

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/Filament/StoreTestModalTest.php`:
```php
    public function test_run_scrape_records_url_and_effective_scraper(): void
    {
        $this->mockScrape('19.99', 'Widget');
        $store = Store::factory()->create([
            'settings' => ['scraper_service' => 'http'],
            'domains' => [['domain' => 'example.com']],
        ]);

        $component = Livewire::test(EditStore::class, ['record' => $store->getKey()])
            ->call('runScrape', 'https://example.com/p', 'api');

        $this->assertSame('https://example.com/p', $component->get('testUrl'));
        $this->assertSame('api', $component->get('testScraper'));
    }

    public function test_run_scrape_records_store_scraper_when_no_override(): void
    {
        $this->mockScrape('19.99', 'Widget');
        $store = Store::factory()->create([
            'settings' => ['scraper_service' => 'http'],
            'domains' => [['domain' => 'example.com']],
        ]);

        $component = Livewire::test(EditStore::class, ['record' => $store->getKey()])
            ->call('runScrape', 'https://example.com/p');

        $this->assertSame('http', $component->get('testScraper'));
    }
```

- [ ] **Step 2: Run to verify they fail**

Run: `lando artisan test --compact --filter="test_run_scrape_records_url_and_effective_scraper|test_run_scrape_records_store_scraper_when_no_override"`
Expected: FAIL — `testUrl` / `testScraper` properties don't exist.

- [ ] **Step 3: Add the properties + recording**

In `app/Filament/Resources/StoreResource/Pages/EditStore.php`, add public properties near `testScrapeResult`:
```php
    public ?string $testUrl = null;

    public ?string $testScraper = null;
```
Update `runScrape()` to record them (after applying the override, before scraping):
```php
    public function runScrape(string $url, ?string $scraper = null): void
    {
        $this->authorizeAccess();

        $store = $this->buildUnsavedStore();

        if (filled($scraper)) {
            $store->settings = array_merge((array) $store->settings, ['scraper_service' => $scraper]);
        }

        $this->testUrl = $url;
        $this->testScraper = $store->scraper_service;

        $scrape = ScrapeUrl::new($url)->scrape([
            'store' => $store,
            'use_cache' => false,
        ]);

        $this->testScrapeResult = $scrape;
        $this->testAiResult = null;
    }
```

- [ ] **Step 4: Run to verify they pass**

Run: `lando artisan test --compact --filter="test_run_scrape_records_url_and_effective_scraper|test_run_scrape_records_store_scraper_when_no_override"`
Expected: PASS.

- [ ] **Step 5: Pint + commit**
```bash
vendor/bin/pint --dirty
git add app/Filament/Resources/StoreResource/Pages/EditStore.php tests/Feature/Filament/StoreTestModalTest.php
git commit -m "feat: record tested URL and effective scraper on EditStore

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 2: Move the scraper select into the Results section (reactive re-test)

**Files:**
- Modify: `app/Filament/Resources/StoreResource.php` (`testForm`)
- Test: `tests/Feature/Filament/StoreTestModalTest.php`

- [ ] **Step 1: Write the failing test (reactive re-test behaviour)**

Append to `tests/Feature/Filament/StoreTestModalTest.php` (the file imports `App\Services\ScrapeUrl`):
```php
    public function test_changing_scraper_retests_with_new_scraper_uncached(): void
    {
        $store = Store::factory()->create([
            'settings' => ['scraper_service' => 'http'],
            'domains' => [['domain' => 'example.com']],
        ]);

        $seen = [];
        $mock = \Mockery::mock(ScrapeUrl::class);
        $mock->shouldReceive('scrape')->andReturnUsing(function (array $opts) use (&$seen) {
            $seen[] = ['scraper' => $opts['store']->scraper_service, 'use_cache' => $opts['use_cache']];

            return ['title' => 'Widget', 'price' => '9.99', 'body' => '<html>'];
        });
        $this->app->bind(ScrapeUrl::class, fn () => $mock);

        Livewire::test(EditStore::class, ['record' => $store->getKey()])
            ->call('runScrape', 'https://example.com/p')        // initial: store scraper (http)
            ->call('runScrape', 'https://example.com/p', 'api'); // re-test with new scraper

        $this->assertSame(['scraper' => 'http', 'use_cache' => false], $seen[0]);
        $this->assertSame(['scraper' => 'api', 'use_cache' => false], $seen[1]);
    }
```
(This exercises the exact path the `afterStateUpdated` closure delegates to: `runScrape($testUrl, $newScraper)`, asserting a fresh, uncached re-fetch with the new scraper. The `->live()` select-to-`afterStateUpdated` wiring itself is a one-line delegate and is not invocable through Filament's action-form test API — a documented limitation.)

- [ ] **Step 2: Run to verify it fails or passes**

Run: `lando artisan test --compact --filter=test_changing_scraper_retests_with_new_scraper_uncached`
Expected: PASS already (it tests `runScrape`, which exists). This locks in the re-test contract before the UI rewiring. If it fails, fix before continuing.

- [ ] **Step 3: Move + rewire the select**

In `app/Filament/Resources/StoreResource.php` `testForm()`:

(a) **Remove** the standalone `Select::make('test_scraper')...` block that currently sits under the `test_url` field.

(b) In the `Results` `Section`, change its `->schema([...])` so the select is the **last** element after the `View`:
```php
                    ->schema([
                        View::make('filament.resources.store-resource.test-results')
                            ->visible(fn (EditStore $livewire): bool => filled($livewire->testScrapeResult))
                            ->viewData(fn (EditStore $livewire): array => [
                                'scrape' => $livewire->testScrapeResult,
                                'ai' => $livewire->testAiResult,
                                'record' => $livewire->buildUnsavedStore(),
                            ]),

                        Select::make('test_scraper')
                            ->label('Change scraper')
                            ->options(ScraperService::class)
                            ->selectablePlaceholder(false)
                            ->native(false)
                            ->afterStateHydrated(fn (Select $component, EditStore $livewire) => $component->state(
                                $component->getState() ?: ($livewire->testScraper ?? $livewire->buildUnsavedStore()->scraper_service)
                            ))
                            ->live()
                            ->afterStateUpdated(function (EditStore $livewire, ?string $state): void {
                                if (filled($livewire->testUrl) && filled($state)) {
                                    $livewire->runScrape($livewire->testUrl, $state);
                                }
                            }),
                    ]),
```
Keep the existing `View`'s `->viewData`/`->visible` exactly as they were (shown above for context — do not change them). `Select` and `ScraperService` are already imported.

(c) The product-shortcut button action and the inline scrape suffix action keep calling `$livewire->runScrape($url->url, $get('test_scraper'))` / `$livewire->runScrape($url, $get('test_scraper'))` — leave them unchanged. (On the first scrape the select is inside the hidden Results section, so `$get('test_scraper')` is null and the store scraper is used — correct.)

- [ ] **Step 4: Run the modal suite**

Run: `lando artisan test --compact --filter=StoreTestModalTest`
Expected: PASS. The existing `test_run_scrape_uses_the_selected_scraper` and product/inline tests still pass (the select moving into the section doesn't change the `runScrape` contract).

- [ ] **Step 5: Pint + commit**
```bash
vendor/bin/pint --dirty
git add app/Filament/Resources/StoreResource.php tests/Feature/Filament/StoreTestModalTest.php
git commit -m "feat: reactive Change scraper select inside the Results section

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 3: Results table columns at 33%

**Files:**
- Modify: `resources/views/filament/resources/store-resource/test-results.blade.php`

- [ ] **Step 1: Make the table fixed-layout with equal-third headers**

In `resources/views/filament/resources/store-resource/test-results.blade.php`, change the table opening tag:
```blade
        <table class="w-full text-sm border-collapse">
```
to:
```blade
        <table class="w-full text-sm border-collapse table-fixed">
```
And change the three header cells:
```blade
                    <th class="py-2 pr-4 font-semibold w-32">Field</th>
                    <th class="py-2 pr-4 font-semibold">Scraped</th>
                    @if ($hasAi)
                        <th class="py-2 pr-4 font-semibold">AI ✨</th>
                    @endif
```
to:
```blade
                    <th class="py-2 pr-4 font-semibold w-1/3">Field</th>
                    <th class="py-2 pr-4 font-semibold w-1/3">Scraped</th>
                    @if ($hasAi)
                        <th class="py-2 pr-4 font-semibold w-1/3">AI ✨</th>
                    @endif
```

- [ ] **Step 2: Verify the modal suite still passes**

Run: `lando artisan test --compact --filter=StoreTestModalTest`
Expected: PASS (no behavioural change; the table still renders the same content).

- [ ] **Step 3: Commit**
```bash
git add resources/views/filament/resources/store-resource/test-results.blade.php
git commit -m "style: equal-third columns in the test results table

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 4: Standards + full suite

**Files:** none (verification only)

- [ ] **Step 1: Standards** — Run: `lando phpcs-fix && lando phpcs` — expect Pint PASS then PHPStan `[OK] No errors`.
- [ ] **Step 2: Full suite** — Run: `lando artisan test --parallel` — expect all green (re-run once if the known-flaky Telegram channel test trips under parallel load; confirm it passes in isolation).
- [ ] **Step 3: Commit any standards fixes**
```bash
git add -A
git commit -m "style: phpcs fixes for test modal scraper re-test"
```
(Skip if nothing changed.)

---

## Self-review notes

- **Spec §1 (never empty / default current):** Task 2 — `afterStateHydrated` sets the select to `$testScraper` (or the store scraper) when it appears; `selectablePlaceholder(false)`. `$testScraper` recorded in Task 1.
- **Spec §2 (change re-tests, fresh fetch):** Task 1 (`testUrl`/`testScraper` recorded, `use_cache=false`) + Task 2 (`->live()` + `afterStateUpdated` → `runScrape`). Behaviour locked by `test_changing_scraper_retests_with_new_scraper_uncached`.
- **Spec §3 (only after results, in Results section under raw body, label "Change scraper"):** Task 2 — select is the last element of the results-gated Section, labelled "Change scraper".
- **Spec §4 (33% columns):** Task 3.
- **Type consistency:** `runScrape(string $url, ?string $scraper = null)`; public `?string $testUrl`, `?string $testScraper`; select key `test_scraper`; `afterStateUpdated` delegates to `runScrape($testUrl, $state)`. Aligned across tasks.
- **Test-API limitation (documented):** the `->live()` select → `afterStateUpdated` wiring and the conditionally-visible select's displayed value are not invocable/assertable via Filament's action-form test API; covered by the `runScrape` behavioural tests + manual/Playwright. Same class of limitation accepted in prior tasks.
- **Ordering:** Task 1 (properties + recording) is independent and safe; Task 2 depends on `testUrl`/`testScraper`; Task 3 is pure markup.

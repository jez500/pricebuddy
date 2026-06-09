# Test modal scraper reactivity fix Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the test-modal "Change scraper" select actually re-test on change (it currently does nothing), guarantee it's never empty, and show a loading indicator while a re-scrape runs.

**Architecture:** Root cause: `->native(false)` makes the select render a `wire:ignore` Alpine-`$entangle`-only Choices control with no `wire:model`, so changes never reach the server. Fix = native select (`wire:model.live` → fires `afterStateUpdated → runScrape`). Plus a broadened default fallback and a `wire:loading` results-area loader.

**Tech Stack:** Laravel 12, Filament 3, Livewire, Pest/PHPUnit, Lando.

**Spec:** `docs/superpowers/specs/2026-06-06-test-modal-scraper-reactivity-fix-design.md`

---

## File structure

- `app/Filament/Resources/StoreResource.php` — `testForm`: `test_scraper` select becomes native + broadened fallback.
- `resources/views/filament/resources/store-resource/test-results.blade.php` — `wire:loading` loader + `wire:loading.remove` wrapper around results.
- Test: `tests/Feature/Filament/StoreTestModalTest.php`.

---

## Task 1: Native select → reactive change listener

**Files:**
- Modify: `app/Filament/Resources/StoreResource.php` (`testForm`, the `test_scraper` select)
- Test: `tests/Feature/Filament/StoreTestModalTest.php`

- [ ] **Step 1: Write the failing regression test**

Append to `tests/Feature/Filament/StoreTestModalTest.php`:
```php
    public function test_change_scraper_select_has_a_live_wire_model_binding(): void
    {
        $this->mockScrape('19.99', 'Widget');
        $store = Store::factory()->create([
            'settings' => ['scraper_service' => 'http'],
            'domains' => [['domain' => 'example.com']],
        ]);

        Livewire::test(EditStore::class, ['record' => $store->getKey()])
            ->mountAction('test')
            ->call('runScrape', 'https://example.com/p')
            ->assertSeeHtml('wire:model.live="mountedActionsData.0.test_scraper"');
    }
```
This asserts the change→server binding the bug was missing — a native live select renders exactly this `wire:model.live` attribute, a non-native one does not.

- [ ] **Step 2: Run to verify it fails**

Run: `lando artisan test --compact --filter=test_change_scraper_select_has_a_live_wire_model_binding`
Expected: FAIL — with `->native(false)` the select renders a `wire:ignore` Alpine `$entangle` control and emits no `wire:model.live` attribute.

- [ ] **Step 3: Make the select native + broaden the fallback**

In `app/Filament/Resources/StoreResource.php`, replace the current `Select::make('test_scraper')...` block (inside the Results section schema) with:
```php
                        Select::make('test_scraper')
                            ->label('Change scraper')
                            ->options(ScraperService::class)
                            ->selectablePlaceholder(false)
                            ->afterStateHydrated(fn (Select $component, EditStore $livewire) => $component->state(
                                $component->getState()
                                    ?: $livewire->testScraper
                                    ?: $livewire->buildUnsavedStore()->scraper_service
                                    ?: ScraperService::Http->value
                            ))
                            ->live()
                            ->afterStateUpdated(function (EditStore $livewire, ?string $state): void {
                                if (filled($livewire->testUrl) && filled($state)) {
                                    $livewire->runScrape($livewire->testUrl, $state);
                                }
                            }),
```
Changes vs current: **remove `->native(false)`**, and broaden the hydration fallback chain to end in `?: ScraperService::Http->value`. (`ScraperService` is already imported.)

- [ ] **Step 4: Run to verify it passes**

Run: `lando artisan test --compact --filter=test_change_scraper_select_has_a_live_wire_model_binding`
Expected: PASS.

Then the full modal suite (no regressions):
Run: `lando artisan test --compact --filter=StoreTestModalTest`
Expected: PASS.

- [ ] **Step 5: Pint + commit**
```bash
vendor/bin/pint --dirty
git add app/Filament/Resources/StoreResource.php tests/Feature/Filament/StoreTestModalTest.php
git commit -m "fix: native Change scraper select so changing it re-tests (wire:model.live)

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 2: Loading indicator while re-scraping

**Files:**
- Modify: `resources/views/filament/resources/store-resource/test-results.blade.php`
- Test: `tests/Feature/Filament/StoreTestModalTest.php`

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/Filament/StoreTestModalTest.php`:
```php
    public function test_results_show_a_loading_indicator(): void
    {
        $this->mockScrape('19.99', 'Widget');
        $store = Store::factory()->create([
            'settings' => ['scraper_service' => 'http'],
            'domains' => [['domain' => 'example.com']],
        ]);

        Livewire::test(EditStore::class, ['record' => $store->getKey()])
            ->mountAction('test')
            ->call('runScrape', 'https://example.com/p')
            ->assertSee('Scraping…')
            ->assertSeeHtml('wire:loading.remove');
    }
```

- [ ] **Step 2: Run to verify it fails**

Run: `lando artisan test --compact --filter=test_results_show_a_loading_indicator`
Expected: FAIL — no loader markup yet.

- [ ] **Step 3: Add the loader + wrap the results**

In `resources/views/filament/resources/store-resource/test-results.blade.php`:

(a) Immediately after the `@endphp` (the availability-resolution block, ~line 45) and before `<table ...>`, insert the loader and open a `wire:loading.remove` wrapper:
```blade
        <div wire:loading class="flex items-center gap-2 py-6 text-sm text-gray-500 dark:text-gray-400">
            <x-filament::loading-indicator class="h-5 w-5" />
            {{ __('Scraping…') }}
        </div>

        <div wire:loading.remove>
```

(b) Immediately before the `@endif` that closes the `@else` branch (the `@endif` on the line just above the final `</div>`, after the Raw HTML body block), close the wrapper:
```blade
        </div>
    @endif
```
(So the `<table>`, the Errors `@if`, and the Raw HTML body `@if` all sit inside the new `<div wire:loading.remove>`.)

- [ ] **Step 4: Run to verify it passes**

Run: `lando artisan test --compact --filter=test_results_show_a_loading_indicator`
Expected: PASS.

Then: `lando artisan test --compact --filter=StoreTestModalTest`
Expected: PASS (all).

- [ ] **Step 5: Commit**
```bash
git add resources/views/filament/resources/store-resource/test-results.blade.php tests/Feature/Filament/StoreTestModalTest.php
git commit -m "feat: loading indicator while the test modal re-scrapes

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 3: Standards + full suite

**Files:** none (verification only)

- [ ] **Step 1: Standards** — Run: `lando phpcs-fix && lando phpcs` — expect Pint PASS then PHPStan `[OK] No errors`.
- [ ] **Step 2: Full suite** — Run: `lando artisan test --parallel` — expect all green (re-run once if the known-flaky Telegram channel test trips under parallel load; confirm it passes in isolation).
- [ ] **Step 3: Commit any standards fixes**
```bash
git add -A
git commit -m "style: phpcs fixes for test modal scraper reactivity fix"
```
(Skip if nothing changed.)

- [ ] **Step 4: Manual check (if Playwright MCP available)** — open a store's edit page → Test, run a scrape, then change the "Change scraper" dropdown: confirm a "Scraping…" loader appears and the results refresh with the new scraper. (The live select → re-scrape round-trip is the one path not coverable by the Filament test API; the `wire:model.live` binding assertion in Task 1 is the automated proxy.)

---

## Self-review notes

- **Spec §1 (native, reactive):** Task 1 — drops `native(false)`; regression test asserts `wire:model.live="mountedActionsData.0.test_scraper"`.
- **Spec §2 (never empty / fallback http):** Task 1 — `afterStateHydrated` fallback chain ends in `ScraperService::Http->value`.
- **Spec §3 (loading feedback):** Task 2 — `wire:loading` loader + `wire:loading.remove` wrapper in the results blade.
- **Spec testing:** binding-presence test (Task 1), loader-presence test (Task 2), phpcs + parallel + manual (Task 3).
- **Type consistency:** select key `test_scraper`; `afterStateUpdated` → `runScrape($testUrl, $state)` (unchanged signature from prior work); `ScraperService::Http->value` used as the final fallback.
- **No-placeholder check:** all blade/PHP shown verbatim; the wrapper open/close are explicit.

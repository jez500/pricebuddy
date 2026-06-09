# Test modal: reactive scraper re-test — design

## Goal

Four changes to the store test modal's scraper control + results table:

1. The scraper select must never be empty — it defaults to the scraper currently in effect.
2. Changing the scraper triggers an immediate re-test with the new scraper (fresh fetch, no stale cache).
3. The scraper select only appears once results have loaded — placed inside the Results section, **below the "Raw HTML body"**, labelled **"Change scraper"**.
4. The results comparison table's columns are each 33% wide.

## Background — current state

- `StoreResource::testForm(Form $form, Store $store)` (`app/Filament/Resources/StoreResource.php`): a top-level `Select::make('test_scraper')` sits under the `test_url` field. It is `->options(ScraperService::class)->default(fn (EditStore $livewire): string => $livewire->buildUnsavedStore()->scraper_service)->selectablePlaceholder(false)->native(false)`. After it comes the `Results` `Section` (`->description('What we could find')->extraAttributes(['class' => 'mt-4'])->visible(fn (EditStore $livewire) => filled($livewire->testScrapeResult))->headerActions([compareWithAi])->schema([View::make('...test-results')])`).
- `EditStore::runScrape(string $url, ?string $scraper = null)` builds the unsaved store, applies the scraper override when `filled($scraper)`, scrapes with `use_cache => false`, sets `$testScrapeResult`, clears `$testAiResult`.
- Product-shortcut buttons and the inline scrape suffix action call `runScrape($url, $get('test_scraper'))`.
- The results blade (`test-results.blade.php`) renders the comparison `<table>` (header `Field` `w-32`, then `Scraped`, then `AI ✨` when `$ai` is filled), an Errors section, and a collapsible "Raw HTML body" `x-filament::section` (collapsed) as its last element.
- `ScrapeUrl::scrape(['use_cache' => false])` → `setUseCache(false)` → fresh fetch (no cache). So a re-test is already cache-safe.

## Decisions

- The select moves into the Results section as its **last** schema element (after the results `View`, i.e. visually below the raw-body section).
- Label changes from "Scraper" to **"Change scraper"**.
- Defaults to the scraper actually used for the current results (never empty).
- Changing it re-runs `runScrape` for the last-tested URL with the new scraper; this clears the AI comparison (the user re-clicks "Compare with AI" if wanted — no automatic token spend).

## Detailed design

### 1. Remember the test URL + scraper on `EditStore`

Add two public properties:
```php
public ?string $testUrl = null;
public ?string $testScraper = null;
```
In `runScrape(string $url, ?string $scraper = null)`, after building the (possibly overridden) store, record what was used:
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

        $scrape = ScrapeUrl::new($url)->scrape(['store' => $store, 'use_cache' => false]);

        $this->testScrapeResult = $scrape;
        $this->testAiResult = null;
    }
```
`$store->scraper_service` reflects the effective scraper (the override when passed, else the unsaved-store value), so `$testScraper` is always the scraper that produced the current results.

### 2. Move the select into the Results section, reactive

Remove the standalone `test_scraper` select from under `test_url`. Add it as the **last** element of the `Results` section schema (after the `View`):
```php
                Section::make('Results')
                    ->description('What we could find')
                    ->extraAttributes(['class' => 'mt-4'])
                    ->visible(fn (EditStore $livewire): bool => filled($livewire->testScrapeResult))
                    ->headerActions([ /* compareWithAi — unchanged */ ])
                    ->schema([
                        View::make('filament.resources.store-resource.test-results')
                            ->visible(fn (EditStore $livewire): bool => filled($livewire->testScrapeResult))
                            ->viewData(/* unchanged */),

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
- `afterStateHydrated` guarantees the select shows the current scraper when the section appears (handles the conditionally-visible-field case where `->default()` alone may not populate). This mirrors the existing availability-select pattern in `StoreResource::form()` (`->afterStateHydrated(fn (Select $component, ?string $state) => $component->state($state ?? 'match'))`).
- `->live()` + `afterStateUpdated` re-runs the scrape for `$testUrl` with the chosen scraper. `runScrape` uses `use_cache => false`, so the re-test is a fresh fetch (cache concern covered). The re-test refreshes `$testScrapeResult` (table re-renders) and clears `$testAiResult`.
- `Select` is imported in `StoreResource`.

### 3. First-scrape path unchanged

Product-shortcut buttons and the inline scrape suffix action still call `runScrape($url, $get('test_scraper'))`. Since the select now lives in the (initially hidden) Results section, `$get('test_scraper')` is null on the first scrape, so `runScrape` uses the store's scraper (no override) — correct. After results load, the select appears showing that scraper, and changing it re-tests.

### 4. Results table columns at 33%

In `test-results.blade.php`, make the table fixed-layout with equal thirds:
- On `<table ...>` add `table-fixed`.
- On each `<th>` (Field, Scraped, AI) use `w-1/3` (replace the Field header's `w-32`).

## Testing

Extend `tests/Feature/Filament/StoreTestModalTest.php`:
- **`runScrape` records url + scraper** — after `->call('runScrape', 'https://example.com/p', 'api')`, assert `testUrl === 'https://example.com/p'` and `testScraper === 'api'`.
- **`testScraper` is the store default when no override** — after `->call('runScrape', $url)` on an `http` store, `testScraper === 'http'`.
- **Reactive re-test** — the `afterStateUpdated` path is exercised by directly calling `runScrape($testUrl, 'api')` after an initial scrape and asserting (via the `ScrapeUrl` mock capturing the store) the re-scrape ran with `scraper_service === 'api'` and `use_cache === false`, and `testScrapeResult` updated. (The Filament `afterStateUpdated` closure itself is a one-line delegate to `runScrape`, covered by the runScrape tests; the live-select wiring is not invocable via the test API — same documented limitation as the product-shortcut `$get`.)
- Existing modal tests stay green.
- `lando phpcs-fix && lando phpcs` to `[OK] No errors`; `lando artisan test --parallel`.

## Out of scope

- Auto re-running the AI comparison on scraper change (intentionally cleared; user re-triggers).
- Persisting the chosen test scraper.
- Changing `ScrapeUrl` caching (already bypassed for tests via `use_cache => false`).

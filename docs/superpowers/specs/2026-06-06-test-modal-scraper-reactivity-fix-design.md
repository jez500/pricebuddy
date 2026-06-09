# Test modal: fix scraper-change reactivity + loading feedback — design

## Goal

The "Change scraper" select in the store test modal does nothing when changed (no
change listener, no network, no re-scrape), and can render empty. Fix the
reactivity, guarantee it defaults to the current scraper (fallback `http`, never
empty), and show a loading indicator while a re-scrape is in flight.

## Root cause (debugged)

`StoreResource::testForm()`'s `test_scraper` select is `->native(false)->live()`.
Inspecting the rendered modal HTML, the select renders **only** an Alpine
`state: $wire.$entangle('mountedActionsData.0.test_scraper', true)` binding inside
a **`wire:ignore`, lazily `x-load`-ed** Choices component — with **no `wire:model`
attribute**.

Per Filament's `vendor/filament/forms/resources/views/components/select.blade.php`:
the element carrying `{{ $applyStateBindingModifiers('wire:model') }}` (i.e.
`wire:model.live`) is rendered **only** in the native branch
(`@if (! ($isSearchable() || $isMultiple()) && $isNative())`, lines 40/51). With
`->native(false)`, that branch is skipped. The Alpine `$entangle` two-way-binds
the value but does **not** fire Livewire's `updated` lifecycle / Filament's
`afterStateUpdated` in this lazy `wire:ignore` modal context — so `runScrape`
never runs on change. This matches the symptom exactly.

## Decisions

1. **Native select.** Remove `->native(false)`. The scraper select is neither
   searchable nor multiple, so the native branch renders a real
   `<select wire:model.live="mountedActionsData.0.test_scraper">`, which reliably
   fires `afterStateUpdated → runScrape`. Trade-off accepted: it renders as the
   browser-native dropdown rather than the styled Filament one.
2. **Never empty.** Keep the `afterStateHydrated` seed and add a final
   `ScraperService::Http->value` fallback so the value is always set.
3. **Loading feedback.** A `wire:loading` indicator inside the Results area shows
   "Scraping…" and hides the results table while a modal request is in flight.

## Detailed design

### 1. Native, reactive select

In `StoreResource::testForm()`, the `test_scraper` select becomes:
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
Change from current: **drop `->native(false)`**, and broaden the hydration
fallback to `?: ... ?: ScraperService::Http->value`. (`??` → `?:` is safe because
`ScraperService` values are non-empty strings, and we want an empty/null seed to
fall through to the default.)

`afterStateUpdated` / `runScrape` are unchanged otherwise; `runScrape` already
uses `use_cache => false`, so each re-test is a fresh fetch.

### 2. Loading feedback in the Results area

In `resources/views/.../test-results.blade.php`, wrap the results content so the
table is hidden and a loader shows during an in-flight request:

- A loading block (shown via `wire:loading`):
  ```blade
  <div wire:loading class="flex items-center gap-2 py-6 text-sm text-gray-500 dark:text-gray-400">
      <x-filament::loading-indicator class="h-5 w-5" />
      {{ __('Scraping…') }}
  </div>
  ```
- The existing results (`<table>…</table>`, Errors, Raw HTML body) wrapped so they
  hide while loading:
  ```blade
  <div wire:loading.remove>
      … existing table + errors + raw body …
  </div>
  ```

Scope: component-wide `wire:loading` (no target) — active during the scraper-change
re-scrape and the initial product/inline scrape. This is the simplest robust
option; it will also show briefly during "Compare with AI" (acceptable). The
loader lives inside the Results section, which is only visible once results exist,
so it appears on **re-tests** (results already shown); the first scrape's feedback
is already provided by the trigger buttons' built-in Filament loading state.

## Testing

- **Reactive binding regression test** (the core fix): render the mounted modal
  after a scrape and assert the HTML contains
  `wire:model.live="mountedActionsData.0.test_scraper"` (currently absent with
  `native(false)`; present once native). This directly asserts the change→server
  listener the bug was missing. Add to `StoreTestModalTest`.
- Existing `runScrape`/re-test behavioural tests stay green (the `runScrape`
  contract is unchanged).
- The `wire:loading` markup is presentational; assert the loader text "Scraping…"
  is present in the results blade output (rendered after a scrape).
- `lando phpcs-fix && lando phpcs`; `lando artisan test --parallel`.

Note: the live select → `afterStateUpdated` round-trip still cannot be invoked
through Filament's action-form test API (documented limitation); the HTML-binding
assertion + the `runScrape` behavioural tests are the automated coverage, with a
manual/Playwright pass for the live click if available.

## Out of scope

- Restyling the native select to match Filament's custom dropdown.
- Targeting the loader narrowly to exclude the AI-compare request.

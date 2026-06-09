# Test store in a modal (against unsaved form values) — design

## Goal

Move the store-testing experience from its dedicated page into a modal on the
store **edit** page, and make it test the **current (unsaved) edit-form values**
so an admin can iterate on scrape configuration without saving or changing pages.
The header **Test** button stays in place but opens a modal instead of
navigating. The modal offers the same UI as the old page (product shortcut
buttons + Product URL input with an inline scrape button) plus the scrape
results, all in one place.

## Background — current state

- **Entry point:** `EditStore::getHeaderActions()` has a `test` action that
  navigates to `StoreResource::getUrl('test', ...)` (`EditStore.php:20`).
- **Dedicated page:** `StoreResource\Pages\TestStore` (an `EditRecord`) renders
  the test form via `StoreResource::testForm(Form $form, Store $store)` and shows
  results through `TestResultsWidget`, which reads `session('test_scrape')` after
  `runScrape()` does `session()->put(...)` + `redirect()`.
- **Test form schema** (`StoreResource::testForm`): up-to-5 product shortcut
  `Actions` buttons (label = product title; each calls
  `$livewire->runScrape($url->url)`), and a `test_url` `TextInput` with a
  `suffixAction('scrape')` that validates and calls `runScrape`.
- **Scrape contract:** `ScrapeUrl::new($url)->scrape(['store' => $store, 'use_cache' => false])`
  reads scrape config off the passed `Store` model — `$store->scraper_service`
  and `$store->scraper_options` (from `settings`), and
  `data_get($store, 'scrape_strategy', [])`. The store is passed explicitly, so
  no domain matching is needed.
- **Related flows:** a footer **"Save & test"** action (`EditStore`) and
  **"Create & test"** (`CreateStore`), both via the `TestAfterEdit` trait, which
  saves then redirects to the test page. `runScrape` also persists
  `settings.test_url` on the store.

## Decisions

1. **Remove the dedicated test page entirely.** The header **Test** button is the
   single entry point. The `'test'` route, the `TestStore` page, the
   `TestAfterEdit` trait, the **"Save & test"** / **"Create & test"** actions and
   their `saveAndTest` methods, and the `TestResultsWidget` Filament widget class
   are all removed.
2. **Reuse the existing Filament form schema** inside the modal (schema-reuse
   approach), retargeting its button closures from `TestStore` to `EditStore`.
3. **Test against unsaved values:** build an in-memory, non-persisted `Store`
   from the live edit-form state and scrape that. **Nothing is persisted** during
   a test — no `session`, no `redirect`, no DB write.
4. **Results render inside the modal**, below the input; the modal stays open and
   the results refresh after each scrape.

## Detailed design

### 1. Entry point — `EditStore` header action

Change the `test` header action from `->url(...)` navigation to a modal action,
keeping the same label ("Test"), colour (gray) and icon
(`heroicon-o-rocket-launch`):

- `->modalHeading('Test store')`
- `->modalSubmitAction(false)` and `->modalCancelActionLabel('Close')` — the modal
  is interactive (buttons run scrapes); there is no single submit.
- `->form(fn (Form $form): Form => StoreResource::testForm($form, $this->record))`
  — the modal body is the reused schema.
- `->modalWidth('3xl')` (or similar) so results are readable.
- `->mountUsing(fn () => $this->testScrapeResult = null)` so each open starts with
  a clean results panel.

### 2. `StoreResource::testForm` — retarget and add results

Keep the signature `testForm(Form $form, Store $store): Form`. Changes:

- Replace the `TestStore $livewire` type hints in the shortcut-button and
  suffix-action closures with `EditStore $livewire`.
- The `test_url` input defaults from the saved value for convenience:
  `->default(fn (): string => (string) data_get($store, 'settings.test_url', ''))`
  (read-only convenience; never written back).
- Append a reactive results component below the input:
  ```php
  View::make('filament.resources.store-resource.test-results')
      ->visible(fn (EditStore $livewire): bool => filled($livewire->testScrapeResult))
      ->viewData(fn (EditStore $livewire): array => [
          'scrape' => $livewire->testScrapeResult,
          'record' => $livewire->getRecord(),
      ]),
  ```
  The view is the repurposed `test-results-widget.blade.php` content (the
  availability-match rendering is preserved), moved to
  `resources/views/filament/resources/store-resource/test-results.blade.php` and
  de-widgetised (plain Blade fed by `$scrape` / `$record`, no
  `<x-filament-widgets::widget>` wrapper).

The product shortcut query is unchanged (up to 5 distinct products with a URL on
this store).

### 3. `EditStore` — scrape against unsaved values

Add a public property and a shared method:

```php
/** @var array<string, mixed>|null */
public ?array $testScrapeResult = null;

public function runScrape(string $url): void
{
    $scrape = ScrapeUrl::new($url)->scrape([
        'store' => $this->buildUnsavedStore(),
        'use_cache' => false,
    ]);

    $this->testScrapeResult = $scrape;
}

protected function buildUnsavedStore(): Store
{
    /** @var Store $store */
    $store = $this->getRecord()->replicate();
    $store->forceFill($this->form->getRawState());

    return $store;
}
```

Notes:
- `$this->form->getRawState()` returns the live edit-form values **without
  validation**, so a partially-filled edit form does not block testing. (If
  `getRawState()` is not available/suitable in this Filament version, use
  `$this->data`, which holds the same edit-form state array.)
- `replicate()` gives a non-persisted clone; `forceFill` applies the form's
  `settings`, `scrape_strategy`, `domains`, etc. as attributes. Array casts apply
  on access, so `ScrapeUrl` reads the unsaved config via the model's accessors.
- No `session`, no `redirect`, no `update()`. The modal re-renders (Livewire) and
  the results `View` becomes visible with the new `$testScrapeResult`.

The product-button closures and the inline suffix action both call
`$livewire->runScrape(...)` exactly as before; the suffix action still validates
the manual `test_url` via the modal form's `StoreUrl` rule before calling it.

### 4. Removals

- Delete `app/Filament/Resources/StoreResource/Pages/TestStore.php`.
- Delete `app/Filament/Resources/StoreResource/Pages/Traits/TestAfterEdit.php`.
- Delete `app/Filament/Resources/StoreResource/Widgets/TestResultsWidget.php`
  (its blade content moves to `test-results.blade.php`; delete the old
  `widgets/test-results-widget.blade.php`).
- `StoreResource::getPages()`: remove the `'test' => TestStore::route(...)` entry
  and the `TestStore` import.
- `EditStore`: drop the `use TestAfterEdit`, the `getSaveAndTestAction(...)` entry
  from `getFormActions()` (leaving just `getSaveFormAction()`), and the
  `saveAndTest()` method; change the `test` header action to the modal action.
- `CreateStore`: drop the `use TestAfterEdit`, the `getSaveAndTestAction(...)`
  entry (leaving just `getCreateFormAction()`), and the `saveAndTest()` method.

### 5. Risk / fallback

The buttons are Filament *form-component actions* with direct `->action()`
closures (no nested modals), so they execute via Livewire and re-render the open
modal in place. This will be verified at the first implementation checkpoint. If
nested form-component actions misbehave inside the action modal, the fallback is
to render the shortcut buttons and URL input as plain `wire:click` / `wire:model`
controls in a custom `->modalContent()` view (same look via Filament button
components), with `runScrape` / a `testManualUrl` method on `EditStore`. The
public-property results panel is unaffected by that fallback.

## Testing

Replace `tests/Feature/Filament/StoreTestPageTest.php` with
`tests/Feature/Filament/StoreTestModalTest.php` (Pest/Livewire, authenticate as
the test user, fake the network with the existing `ScraperTrait::mockScrape`).

Cover:
- The `EditStore` `test` header action exists and opens a modal
  (`assertActionExists('test')`, `mountAction('test')`,
  `assertActionMounted('test')` / the action's form is present).
- The mounted modal form renders a button per attached product (titles visible),
  capped at 5.
- A store with no products still shows the `test_url` input and no shortcut
  buttons.
- `runScrape` scrapes the **current unsaved form values**: set the edit form to a
  scraper/strategy config that differs from the saved record, call
  `->call('runScrape', $url)`, assert `testScrapeResult` is populated and that the
  store row in the DB is **unchanged** (`settings.test_url` not written; the saved
  `settings` untouched).
- The dedicated test route is gone:
  `expect(fn () => StoreResource::getUrl('test', ['record' => $store]))->toThrow(...)`
  (or assert `'test'` is absent from `StoreResource::getPages()`).
- Existing `StoreTest` (edit/create) still passes after the footer-action removal.

Then `lando phpcs-fix && lando phpcs` to `[OK] No errors`, and
`lando artisan test --parallel` green. Manual Playwright pass if the MCP is
available (open a store's edit page, click **Test**, confirm the modal shows
shortcut buttons + input, click a shortcut and a manually typed URL, confirm
results render in the modal without leaving the page).

## Out of scope

- Adding the modal to the **create** page (no saved record / no products yet);
  after creating, the admin lands on the edit page and can test there.
- Changing the scrape engine, selectors, or `ScrapeUrl` internals.
- Persisting a "last tested URL" (the test no longer writes to the DB).

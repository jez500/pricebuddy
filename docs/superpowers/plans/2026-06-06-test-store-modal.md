# Test store modal Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move store testing from its dedicated page into a modal on the store edit page that scrapes using the *current unsaved* edit-form values, so an admin can iterate on scrape config without saving or leaving the page.

**Architecture:** The `EditStore` header **Test** button becomes a modal action whose body is the existing test form schema (product shortcut buttons + URL input + inline scrape button) plus a reactive results panel. A new `EditStore::runScrape()` builds an in-memory, non-persisted `Store` from the live form state and scrapes it, storing the result on a public property (no session, redirect, or DB write). The dedicated `TestStore` page, its route, the `TestAfterEdit` trait, the "Save & test"/"Create & test" actions, and the `TestResultsWidget` are removed.

**Tech Stack:** Laravel 12, Filament 3, Livewire, Pest/PHPUnit, Lando (run all artisan/test/composer via `lando`).

**Spec:** `docs/superpowers/specs/2026-06-06-test-store-modal-design.md`

---

## File structure

- `app/Filament/Resources/StoreResource/Pages/EditStore.php` — gains `testScrapeResult`, `runScrape()`, `buildUnsavedStore()`; the `test` header action becomes a modal; loses Save & test + `TestAfterEdit`.
- `app/Filament/Resources/StoreResource.php` — `testForm()` closures retarget to `EditStore`, gains a results `View` component + URL default; removes the `TestStore` page/route/import.
- `resources/views/filament/resources/store-resource/test-results.blade.php` — new plain-blade results partial (de-widgetised).
- `app/Filament/Resources/StoreResource/Pages/CreateStore.php` — loses Create & test + `TestAfterEdit`.
- Deleted: `Pages/TestStore.php`, `Pages/Traits/TestAfterEdit.php`, `Widgets/TestResultsWidget.php`, `widgets/test-results-widget.blade.php`.
- `tests/Feature/Filament/StoreTestModalTest.php` — new (replaces `StoreTestPageTest.php`).

---

## Task 1: `EditStore::runScrape` against unsaved form values

**Files:**
- Modify: `app/Filament/Resources/StoreResource/Pages/EditStore.php`
- Create: `tests/Feature/Filament/StoreTestModalTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Filament/StoreTestModalTest.php`:

```php
<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\StoreResource\Pages\EditStore;
use App\Models\Product;
use App\Models\Store;
use App\Models\Url;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;
use Tests\Traits\ScraperTrait;

class StoreTestModalTest extends TestCase
{
    use RefreshDatabase;
    use ScraperTrait;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        User::query()->delete();

        $this->user = User::factory()->create([
            'name' => 'Tester',
            'email' => 'tester@test.com',
            'password' => Hash::make('password'),
        ]);

        $this->actingAs($this->user);
    }

    private function storeWithProducts(int $count): Store
    {
        $store = Store::factory()->create(['settings' => ['scraper_service' => 'http']]);

        for ($i = 1; $i <= $count; $i++) {
            Url::factory()
                ->for($store)
                ->for(Product::factory()->create(['title' => "Shortcut Product {$i}"]))
                ->create();
        }

        return $store;
    }

    public function test_run_scrape_uses_unsaved_form_values_and_does_not_persist(): void
    {
        $this->mockScrape('19.99', 'Widget');

        // Saved config has a BROKEN price selector (won't match the mock page).
        $store = Store::factory()->create([
            'settings' => ['scraper_service' => 'http'],
            'domains' => [['domain' => 'example.com']],
            'scrape_strategy' => [
                'title' => ['type' => 'selector', 'value' => 'meta[property=og:title]|content'],
                'price' => ['type' => 'selector', 'value' => '.does-not-exist'],
            ],
        ]);

        $component = Livewire::test(EditStore::class, ['record' => $store->getKey()])
            // Fix the price selector in the form only (unsaved).
            ->set('data.scrape_strategy.price', ['type' => 'selector', 'value' => 'meta[property=og:price:amount]|content'])
            ->call('runScrape', 'https://example.com/p');

        // The scrape used the UNSAVED working selector.
        $this->assertSame('19.99', data_get($component->get('testScrapeResult'), 'price'));

        // Nothing persisted: the saved (broken) strategy is unchanged.
        $this->assertSame('.does-not-exist', data_get($store->fresh(), 'scrape_strategy.price.value'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `lando artisan test --compact --filter=test_run_scrape_uses_unsaved_form_values_and_does_not_persist`
Expected: FAIL — `Method App\Filament\Resources\StoreResource\Pages\EditStore::runScrape does not exist` (or property `testScrapeResult` missing).

- [ ] **Step 3: Add the property + methods to `EditStore`**

In `app/Filament/Resources/StoreResource/Pages/EditStore.php`, add imports:

```php
use App\Models\Store;
use App\Services\ScrapeUrl;
```

Add inside the class (e.g. after `protected static string $resource`):

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

Leave the existing header action / form actions untouched for now.

- [ ] **Step 4: Run test to verify it passes**

Run: `lando artisan test --compact --filter=test_run_scrape_uses_unsaved_form_values_and_does_not_persist`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty
git add app/Filament/Resources/StoreResource/Pages/EditStore.php tests/Feature/Filament/StoreTestModalTest.php
git commit -m "feat: scrape unsaved store form values from EditStore::runScrape

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 2: Modal action + retarget schema + remove the dedicated page

**Files:**
- Create: `resources/views/filament/resources/store-resource/test-results.blade.php`
- Modify: `app/Filament/Resources/StoreResource.php` (`testForm`, imports, `getPages`)
- Modify: `app/Filament/Resources/StoreResource/Pages/EditStore.php` (`test` header action, footer actions)
- Delete: `app/Filament/Resources/StoreResource/Pages/TestStore.php`
- Delete: `tests/Feature/Filament/StoreTestPageTest.php`
- Test: `tests/Feature/Filament/StoreTestModalTest.php`

- [ ] **Step 1: Create the results partial**

Create `resources/views/filament/resources/store-resource/test-results.blade.php`:

```blade
<div>
    @if (empty($scrape))
        <p class="my-6">{{ __('Unable to find any data, check store settings') }}</p>
    @else
        @foreach($scrape as $key => $val)
            @if ($key !== 'store')
                <div class="mb-8">
                    <x-filament::section :heading="str_replace('_', ' ', ucfirst($key))">
                        <code class="block whitespace-pre overflow-x-auto">{{ is_string($val) ? $val : json_encode($val, JSON_PRETTY_PRINT) }}</code>
                    </x-filament::section>
                    @if ($key === 'availability')
                        @php
                            $matchConfig = data_get($record, 'scrape_strategy.availability.match');
                            $resolvedStatus = \App\Enums\StockStatus::matchFromScrapedValue($val, $matchConfig);

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
                                    } elseif (is_string($matchEntry) && trim($val) === trim($matchEntry)) {
                                        $matchedRule = "exact \"$matchEntry\"";
                                        break;
                                    }
                                }
                            }
                        @endphp
                        <div class="mt-8">
                            <x-filament::section heading="Product status">
                                <code class="block whitespace-pre overflow-x-auto">{{ $resolvedStatus->getLabel() }}@if ($matchedRule) — matched {{ $matchedRule }}@elseif ($resolvedStatus === \App\Enums\StockStatus::InStock) — no match (default)@endif</code>
                            </x-filament::section>
                        </div>
                    @endif
                </div>
            @endif
        @endforeach
    @endif
</div>
```

- [ ] **Step 2: Retarget `testForm` and add the results panel**

In `app/Filament/Resources/StoreResource.php`:

Add imports (and remove the `TestStore` import line):

```php
use App\Filament\Resources\StoreResource\Pages\EditStore;
use Filament\Forms\Components\View;
```

Replace the body of `testForm()` (keep the signature `testForm(Form $form, Store $store): Form`) with:

```php
        /** @var \Illuminate\Database\Eloquent\Collection<int, Url> $shortcutUrls */
        $shortcutUrls = $store->urls()
            ->with('product')
            ->whereHas('product')
            ->get()
            ->unique('product_id')
            ->take(5);

        return $form->schema([
            Section::make('Test url scrape')
                ->description('See the results of scraping a url using the current store settings')
                ->columns(1)
                ->schema(array_values(array_filter([
                    $shortcutUrls->isNotEmpty()
                        ? Actions::make(
                            $shortcutUrls->map(fn (Url $url): FormAction => FormAction::make('product_'.$url->getKey())
                                ->label($url->product->title)
                                ->action(fn (EditStore $livewire) => $livewire->runScrape($url->url))
                            )->all()
                        )->label('Existing products')->key('product_shortcuts')
                        : null,

                    TextInput::make('test_url')
                        ->label('Product URL')
                        ->hintIcon(Icons::Help->value, 'The URL to scrape')
                        ->default(fn (): string => (string) data_get($store, 'settings.test_url', ''))
                        ->required()
                        ->rules([new StoreUrl])
                        ->suffixAction(
                            FormAction::make('scrape')
                                ->label('Test url scrape')
                                ->icon(Icons::Search->value)
                                ->action(function (\Filament\Forms\Get $get, EditStore $livewire): void {
                                    $url = (string) $get('test_url');

                                    if (filled($url)) {
                                        $livewire->runScrape($url);
                                    }
                                })
                        ),

                    View::make('filament.resources.store-resource.test-results')
                        ->visible(fn (EditStore $livewire): bool => filled($livewire->testScrapeResult))
                        ->viewData(fn (EditStore $livewire): array => [
                            'scrape' => $livewire->testScrapeResult,
                            'record' => $livewire->getRecord(),
                        ]),
                ]))),
        ]);
```

Note on the suffix action: the schema now lives inside a **header-action modal**, so the `test_url` value is read with Filament's `$get` form utility (which resolves the field within the action's own form container) rather than the page's main form. Full `StoreUrl` rule validation does not auto-fire here (the modal has no submit), so the action guards on `filled()` only; an invalid URL simply yields an empty scrape result, which is acceptable for a test tool. If form-component actions prove awkward inside the modal at the first checkpoint, the fallback (§5 of the spec) is plain `wire:click` controls — but try this first.

- [ ] **Step 3: Convert the `test` header action to a modal in `EditStore`**

In `app/Filament/Resources/StoreResource/Pages/EditStore.php`:

Add imports:

```php
use App\Filament\Resources\StoreResource;
use Filament\Forms\Form;
use Filament\Support\Enums\MaxWidth;
```

(`StoreResource` is likely already imported — keep one copy.)

Replace the `test` header action in `getHeaderActions()`:

```php
            Actions\Action::make('test')
                ->label('Test')->color('gray')
                ->icon('heroicon-o-rocket-launch')
                ->modalHeading('Test store')
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Close')
                ->modalWidth(MaxWidth::ThreeExtraLarge)
                ->mountUsing(fn (EditStore $livewire) => $livewire->testScrapeResult = null)
                ->form(fn (Form $form): Form => StoreResource::testForm($form, $this->record)),
```

Change `getFormActions()` to drop "Save & test":

```php
    protected function getFormActions(): array
    {
        return [
            $this->getSaveFormAction(),
        ];
    }
```

Remove `use ...Traits\TestAfterEdit;`, the `use TestAfterEdit;` line in the class, and the `saveAndTest()` method. (The `TestAfterEdit` trait file itself is deleted in Task 3, where `CreateStore` also stops using it.)

- [ ] **Step 4: Delete the dedicated page + its route**

```bash
git rm app/Filament/Resources/StoreResource/Pages/TestStore.php
git rm tests/Feature/Filament/StoreTestPageTest.php
```

In `app/Filament/Resources/StoreResource.php`, remove the `'test'` route from `getPages()`:

```php
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListStores::route('/'),
            'create' => Pages\CreateStore::route('/create'),
            'edit' => Pages\EditStore::route('/{record}/edit'),
        ];
    }
```

(Match the existing `getPages()` shape — only remove the `'test'` line. If pages are referenced as `Pages\ListStores` vs imported class names, keep the file's existing style.)

- [ ] **Step 5: Add the modal UI tests**

Append to `tests/Feature/Filament/StoreTestModalTest.php` (add `use App\Filament\Resources\StoreResource;` at the top):

```php
    public function test_test_action_opens_modal_with_product_shortcuts(): void
    {
        $store = $this->storeWithProducts(3);

        Livewire::test(EditStore::class, ['record' => $store->getKey()])
            ->assertActionExists('test')
            ->mountAction('test')
            ->assertSee('Shortcut Product 1')
            ->assertSee('Shortcut Product 2')
            ->assertSee('Shortcut Product 3')
            ->assertSee('Product URL');
    }

    public function test_modal_caps_product_shortcuts_at_five(): void
    {
        $store = $this->storeWithProducts(6);

        Livewire::test(EditStore::class, ['record' => $store->getKey()])
            ->mountAction('test')
            ->assertSee('Shortcut Product 1')
            ->assertSee('Shortcut Product 5')
            ->assertDontSee('Shortcut Product 6');
    }

    public function test_modal_without_products_still_shows_url_input(): void
    {
        $store = Store::factory()->create(['settings' => ['scraper_service' => 'http']]);

        Livewire::test(EditStore::class, ['record' => $store->getKey()])
            ->mountAction('test')
            ->assertSee('Product URL')
            ->assertDontSee('Existing products');
    }

    public function test_dedicated_test_route_is_removed(): void
    {
        $this->assertArrayNotHasKey('test', StoreResource::getPages());
    }
```

- [ ] **Step 6: Run tests**

Run: `lando artisan test --compact --filter=StoreTestModalTest`
Expected: PASS (5 tests). If `mountAction('test')->assertSee(...)` does not surface the modal form HTML in this Filament version, switch those assertions to `->assertActionMounted('test')` plus `->assertFormComponentExists('test_url', 'mountedActionForm')` and verify the product action exists; adjust until the rendering is genuinely asserted.

Run: `lando artisan test --compact tests/Feature/Filament/StoreTest.php`
Expected: PASS (existing edit/create tests unaffected so far).

- [ ] **Step 7: Commit**

```bash
vendor/bin/pint --dirty
git add -A
git commit -m "feat: test store via modal on edit page; remove dedicated test page

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 3: Remove Create & test + the trait and widget

**Files:**
- Modify: `app/Filament/Resources/StoreResource/Pages/CreateStore.php`
- Delete: `app/Filament/Resources/StoreResource/Pages/Traits/TestAfterEdit.php`
- Delete: `app/Filament/Resources/StoreResource/Widgets/TestResultsWidget.php`
- Delete: `resources/views/filament/resources/store-resource/widgets/test-results-widget.blade.php`

- [ ] **Step 1: Simplify `CreateStore`**

Replace `app/Filament/Resources/StoreResource/Pages/CreateStore.php` with:

```php
<?php

namespace App\Filament\Resources\StoreResource\Pages;

use App\Filament\Resources\StoreResource;
use Filament\Resources\Pages\CreateRecord;

class CreateStore extends CreateRecord
{
    protected static string $resource = StoreResource::class;
}
```

(Default create footer actions — `Create` / `Create & create another` — apply automatically.)

- [ ] **Step 2: Delete the trait, widget, and old widget blade**

```bash
git rm app/Filament/Resources/StoreResource/Pages/Traits/TestAfterEdit.php
git rm app/Filament/Resources/StoreResource/Widgets/TestResultsWidget.php
git rm resources/views/filament/resources/store-resource/widgets/test-results-widget.blade.php
```

- [ ] **Step 3: Confirm no dangling references**

Run: `grep -rn "TestAfterEdit\|TestResultsWidget\|saveAndTest\|getSaveAndTestAction\|test-results-widget\|getUrl('test'\|TestStore" app/ tests/ resources/`
Expected: **no matches** (every reference removed).

- [ ] **Step 4: Run the Store Filament suites**

Run: `lando artisan test --compact tests/Feature/Filament/StoreTest.php tests/Feature/Filament/StoreTestModalTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty
git add -A
git commit -m "chore: remove Create & test action, TestAfterEdit trait, and TestResultsWidget

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 4: Standards + full suite + manual check

**Files:** none (verification only)

- [ ] **Step 1: Coding standards**

Run: `lando phpcs-fix && lando phpcs`
Expected: Pint PASS, then PHPStan reaches `[OK] No errors`. (A Pint failure short-circuits before PHPStan — make sure you reach the PHPStan `[OK]`.)

- [ ] **Step 2: Full suite in parallel**

Run: `lando artisan test --parallel`
Expected: all green (the only removed tests are `StoreTestPageTest`, replaced by `StoreTestModalTest`).

- [ ] **Step 3: Commit any standards fixes**

```bash
git add -A
git commit -m "style: phpcs fixes for test-store modal"
```
(Skip if nothing changed.)

- [ ] **Step 4: Manual check (if Playwright MCP is available)**

Base `http://price-buddy.lndo.site/admin`, `test@test.com` / `password`. Open a store with products at `/admin/stores/{id}/edit`, click **Test**: confirm the modal shows product shortcut buttons above the URL input with an inline scrape button. Click a shortcut → results render in the modal without leaving the page. Type a URL and use the inline button → results render. Edit a strategy field in the form (without saving), reopen Test, and confirm the scrape reflects the unsaved change. If the MCP is unavailable, note it; the automated tests cover action existence, schema rendering, unsaved-value scraping, and no-persist.

---

## Self-review notes

- **Spec §1 (entry point modal):** Task 2 Step 3.
- **Spec §2 (reuse schema + results panel):** Task 2 Steps 1–2.
- **Spec §3 (scrape unsaved values, no persist):** Task 1.
- **Spec §4 (removals):** Tasks 2 (page, route, Save & test) and 3 (Create & test, trait, widget).
- **Spec §5 (risk/fallback):** flagged in Task 2 Steps 2 & 6.
- **Spec testing section:** Tasks 1–3 build `StoreTestModalTest`; Task 4 runs phpcs + parallel + manual.
- **Type consistency:** `runScrape(string): void`, `buildUnsavedStore(): Store`, public `?array $testScrapeResult`, `testForm(Form, Store): Form` with `EditStore $livewire` closures — used identically across Tasks 1–2.
- **Ordering safety:** Task 1 leaves the old page intact (app consistent); Task 2 retargets the schema to `EditStore` and removes `TestStore` in the same task (no half-retargeted state); Task 3 removes the trait only after both `EditStore` (Task 2) and `CreateStore` (Task 3 Step 1) stop using it.
```

# Test results presentation: comparison table + AI compare + collapsible body — design

## Goal

Improve the store test modal's results output: present the scrape result as a
clean comparison table (instead of a stack of raw code blocks), let the user
optionally run AI extraction on the same page for a side-by-side comparison,
collapse the raw HTML body by default, and widen the modal.

## Background — current state

- The test modal (header `test` action on `EditStore`) renders its results via
  `resources/views/filament/resources/store-resource/test-results.blade.php`, a
  `View` form component fed by `viewData` `['scrape' => $livewire->testScrapeResult, 'record' => $livewire->buildUnsavedStore()]`.
- The blade currently loops every scrape key (except `store`) and dumps each as a
  `<code>` block inside an `x-filament::section`, with a special availability
  block resolving `StockStatus`. The raw `body` (full HTML) renders as a giant
  code block.
- Scrape result keys: `title`, `description`, `price`, `image`, `availability`
  (the configured strategy fields), plus `body` (raw HTML), `errors`, `store`.
- `EditStore::runScrape(string $url)` builds an unsaved `Store`
  (`buildUnsavedStore()`), scrapes, and stores the array in public
  `?array $testScrapeResult`. Modal width is `MaxWidth::ThreeExtraLarge`.
- AI extraction exists: `AiExtractionService::extract(string $html, ?Collection $schemaOrg = null, ?AiProviderConfigDto $provider = null): ?AiExtractionResultDto`
  returns `title, price (float), currency, image, stockStatus (StockStatus), confidence (float)`.
  `IntegrationHelper::isAiEnabled(): bool` (AI globally configured) and
  `IntegrationHelper::getAiProvider(?string $id)` (store provider → global default
  fallback) are available. `Store` exposes `ai_provider_id`.

## Decisions

1. **Widen** the modal to `MaxWidth::FiveExtraLarge`.
2. **Comparison table** layout: one row per field, a "Scraped" column always, and
   an "AI" column that appears only after the user runs AI comparison.
3. **On-demand AI**: a "Compare with AI" button (shown only when AI is configured
   and a scrape result exists) runs AI extraction on the scraped HTML using the
   store's provider. Not automatic — tokens are spent only on click.
4. **Confidence** is its own row (AI column only).
5. **Raw body** moves to a collapsed-by-default collapsible section; `errors`
   render separately.

## Detailed design

### 1. `EditStore` — AI comparison state + method

Add a public property and method:

```php
/** @var array<string, mixed>|null */
public ?array $testAiResult = null;

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

- Storing a **plain array** (not the DTO/enum) keeps the Livewire public property
  cleanly serializable.
- `runScrape()` gains `$this->testAiResult = null;` (a fresh scrape invalidates a
  prior AI comparison).
- Imports: `App\Services\AiExtractionService`, `App\Services\Helpers\IntegrationHelper`,
  `Filament\Notifications\Notification`.

### 2. Modal action (in `EditStore::getHeaderActions`)

- Change `->modalWidth(MaxWidth::ThreeExtraLarge)` → `->modalWidth(MaxWidth::FiveExtraLarge)`.
- `->mountUsing` resets both properties:
  ```php
  ->mountUsing(function (EditStore $livewire): void {
      $livewire->testScrapeResult = null;
      $livewire->testAiResult = null;
  })
  ```

### 3. `StoreResource::testForm` — Compare with AI button

Add a "Compare with AI" form action to the schema, after the `test_url` input (a
standalone `Actions::make([...])` row, or appended near the input). It is visible
only when AI is configured **and** a scrape result exists:

```php
Actions::make([
    FormAction::make('compareWithAi')
        ->label('Compare with AI')
        ->icon('heroicon-m-sparkles')
        ->action(fn (EditStore $livewire) => $livewire->compareWithAi()),
])
    ->visible(fn (EditStore $livewire): bool => IntegrationHelper::isAiEnabled() && filled($livewire->testScrapeResult)),
```

(`IntegrationHelper` import added to `StoreResource`.)

The results `View` `viewData` gains the AI array:

```php
->viewData(fn (EditStore $livewire): array => [
    'scrape' => $livewire->testScrapeResult,
    'ai' => $livewire->testAiResult,
    'record' => $livewire->buildUnsavedStore(),
]),
```

### 4. Results view — `test-results.blade.php` rewrite

Replace the body with:

- **Empty state** unchanged ("Unable to find any data, check store settings" when
  `$scrape` is empty).
- A **comparison table** built from a fixed field map:
  `['title' => 'Title', 'price' => 'Price', 'currency' => 'Currency', 'image' => 'Image', 'availability' => 'Availability', 'description' => 'Description']`.
  - Columns: **Field**, **Scraped**, and **AI** (the AI `<th>`/`<td>`s render only
    when `$ai` is filled).
  - **Scraped** values come from `$scrape[$key]` (currency/description may be
    absent → `—`); **AI** values from `$ai[$key]`.
  - **Image** cells: if the value looks like a URL (`Str::startsWith($value, ['http://','https://'])`),
    render `<img src="..." class="h-16 ...">`; else the raw string; else `—`.
  - **Availability** (scraped) keeps the resolved `StockStatus` + matched-rule
    display (the existing logic, computed from `$record` = the unsaved store). The
    AI availability cell shows `$ai['availability']` (already a label) or `—`.
  - A **Confidence** row renders only when `$ai` is filled: Field "Confidence",
    Scraped `—`, AI = `number_format($ai['confidence'], 2)`.
  - All empty values render as `—`.
- A **collapsible raw body** below the table:
  ```blade
  @if (filled(data_get($scrape, 'body')))
      <x-filament::section heading="Raw HTML body" collapsible collapsed class="mt-6">
          <code class="block whitespace-pre-wrap break-all max-h-96 overflow-auto text-xs">{{ data_get($scrape, 'body') }}</code>
      </x-filament::section>
  @endif
  ```
- **Errors** (if `filled(data_get($scrape, 'errors'))`): a small section rendering
  the errors as JSON.
- The `store` key is never rendered.

Use Tailwind utility classes consistent with the project (Filament/Tailwind v3).
Table styling: a simple bordered/striped table with header row; keep it readable
in the wider modal.

### 5. Testing

Extend `tests/Feature/Filament/StoreTestModalTest.php` (Pest/Livewire, auth as the
test user, `ScraperTrait::mockScrape`):

- **Scraped results render:** after `->call('runScrape', $url)` (with a working
  store config + `mockScrape('19.99', 'Widget')`), the component HTML shows the
  scraped title and price (`assertSee('Widget')`, `assertSee('19.99')`).
- **Compare with AI populates the AI column:** mock `AiExtractionService` to return
  an `AiExtractionResultDto` (e.g. title 'AI Widget', price 9.5, confidence 0.88);
  after `runScrape` then `->call('compareWithAi')`, assert `testAiResult` is set
  (`expect($component->get('testAiResult'))` non-null with the mapped values) and
  the AI value renders (`assertSee('AI Widget')`).
- **compareWithAi no-ops without a scrape:** calling `compareWithAi` when
  `testScrapeResult` is null leaves `testAiResult` null (mock `extract` never
  called — or simply assert null result).
- **Compare action visibility:** `assertActionExists`/visibility — when AI is not
  configured the `compareWithAi` form-component action is absent; when configured
  and a scrape exists it is present. (Form-component-action visibility may be
  awkward to assert directly; an acceptable substitute is asserting the rendered
  modal does/does not contain the "Compare with AI" label after a scrape, gated by
  configuring AI via app settings.)
- **runScrape clears prior AI result:** set `testAiResult`, call `runScrape`,
  assert it is null again.

Then `lando phpcs-fix && lando phpcs` to `[OK] No errors`, and
`lando artisan test --parallel` green. Manual Playwright pass if available
(scrape, expand body, Compare with AI, confirm the AI column appears).

## Out of scope

- AI verification / arbitration / stock-status verification (separate roadmap).
- Changing the scrape engine, selectors, or `AiExtractionService` internals.
- Persisting AI comparison results.

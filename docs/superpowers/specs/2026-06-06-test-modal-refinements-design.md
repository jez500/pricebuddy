# Test modal refinements — design

## Goal

Six refinements to the store test modal (`EditStore` `test` action + `StoreResource::testForm`):

1. "Compare with AI" extracts **every** comparable attribute, including `description`.
2. A **scraper dropdown** under the Product URL field to choose which scraper the test run uses.
3. A **modal description** under the title.
4. A **Product URL placeholder** built from the store's first host.
5. ~1rem **top gutter** above the Results section.
6. A **description on the Results section header**.

## Background — current state

- `EditStore::compareWithAi()` runs `AiExtractionService::new()->extract($body, provider:)` and maps the `AiExtractionResultDto` to `$testAiResult` (keys: title, price, currency, image, availability, confidence). It does **not** include `description`.
- `AiExtractionService` is shared with the price-recovery pipeline (`AiScrapeEnhancer`, which only reads `price`). Its structured schema returns `name, price, currency, imageUrl, stockStatus, confidence`; its prompt is price-focused; `AiExtractionResultDto` has `title, price, currency, image, stockStatus, confidence`.
- `resources/views/.../test-results.blade.php` renders a Field / Scraped / AI table over `['title','price','currency','image','availability','description']`. The AI cell explicitly suppresses description: `$aiVal = $key === 'description' ? null : data_get($ai, $key);`.
- `EditStore::runScrape(string $url)` scrapes `$this->buildUnsavedStore()` (built from the live edit-form state) — its `scraper_service` comes from the form's `settings.scraper_service`.
- `StoreResource::testForm()` schema (no outer Section): optional product-shortcut `Actions` (`product_shortcuts`), `test_url` TextInput with a `scrape` suffix action, then a `Results` `Section` (visible when `testScrapeResult` is filled) holding the results `View`, with a `compareWithAi` header action.
- The `test` header action (`EditStore::getHeaderActions`): `modalHeading('Test store')`, `modalSubmitAction(false)`, `modalCancelAction(false)`, `modalWidth(FiveExtraLarge)`. No `modalDescription`.
- `App\Enums\ScraperService` implements `HasLabel`/`HasDescription` (cases `Http`, `Api`). `Store::scraperService` reads `settings.scraper_service` (default Http). `Store::$domains` is an array of `['domain' => '...']`.

## Decisions

- AI compare gains `description` via the **shared** extractor (price pipeline ignores it).
- Scraper dropdown **defaults to the store's current scraper** and is **test-only** (never persisted).
- Placeholder: `https://{firstHost}/example-product`.

## Detailed design

### 1. AI extracts description

`app/Services/AiExtractionService.php`:
- Add a rule to `EXTRACTION_PROMPT`: `- description: a short product description if present.`
- Add to the structured schema: `'description' => $schema->string(),`.
- In `extract()`'s DTO construction add `description: $result['description'] ?? null,`.

`app/Dto/AiExtractionResultDto.php`: add `public ?string $description = null,` (after `title`, before `price`, or anywhere — keep named-arg call sites valid).

`EditStore::compareWithAi()`: add `'description' => $result->description,` to the `$testAiResult` array.

`resources/views/.../test-results.blade.php`: change the AI cell line from
`@php $aiVal = $key === 'description' ? null : data_get($ai, $key); @endphp`
to
`@php $aiVal = data_get($ai, $key); @endphp`
so the AI description renders. (The scraped cell already shows description; currency remains scraped-suppressed via its own `$key === 'currency' ? null` line.)

### 2. Scraper dropdown

In `StoreResource::testForm()`, add a `Select` immediately **after** the `test_url` TextInput:
```php
Select::make('test_scraper')
    ->label('Scraper')
    ->options(ScraperService::class)
    ->default(fn (): string => (string) data_get($store, 'settings.scraper_service', ScraperService::Http->value))
    ->selectablePlaceholder(false),
```
(`Select` and `ScraperService` are imported in `StoreResource`.)

`EditStore::runScrape()` gains a scraper override:
```php
public function runScrape(string $url, ?string $scraper = null): void
{
    $this->authorizeAccess();

    $store = $this->buildUnsavedStore();

    if (filled($scraper)) {
        $store->settings = array_merge((array) $store->settings, ['scraper_service' => $scraper]);
    }

    $scrape = ScrapeUrl::new($url)->scrape(['store' => $store, 'use_cache' => false]);

    $this->testScrapeResult = $scrape;
    $this->testAiResult = null;
}
```
Both callers pass the selected scraper via `$get`:
- product buttons: `->action(fn (Get $get, EditStore $livewire) => $livewire->runScrape($url->url, $get('test_scraper')))`
- inline scrape suffix action: inside its closure, `$livewire->runScrape($url, $get('test_scraper'))` (it already injects `Get $get`).

`compareWithAi()` is unchanged — it analyses the already-fetched `testScrapeResult['body']`, so the scraper choice is already reflected.

### 3. Modal description

On the `test` action in `EditStore::getHeaderActions()`, add:
```php
->modalDescription(fn (): string => 'Dry run the current store settings'
    .(IntegrationHelper::isAiEnabled() ? ' and compare with AI' : ''))
```
(`IntegrationHelper` is imported in `EditStore`.)

### 4. Product URL placeholder

On the `test_url` TextInput in `testForm()`, add:
```php
->placeholder(fn (): ?string => filled($host = data_get($store, 'domains.0.domain'))
    ? 'https://'.$host.'/example-product'
    : null)
```

### 5. Results section gutter

On the `Results` `Section`, add `->extraAttributes(['class' => 'mt-4'])` (Tailwind `mt-4` = 1rem top margin).

### 6. Results header description

On the `Results` `Section`, add `->description('What we could find')`.

## Testing

Extend `tests/Feature/Filament/StoreTestModalTest.php` and the AI tests:

- **AI extracts description** — `tests/Feature/Services/AiExtractionServiceTest.php`: the structured-result mock returns a `description`; assert the returned DTO's `description` is mapped. (Reuses the file's existing `provider()` + `AiService` mock pattern.)
- **Compare renders description** — in `StoreTestModalTest`, the `AiExtractionResultDto` mock in the AI-column test includes `description: 'AI desc'`; assert `assertSee('AI desc')` after `compareWithAi`.
- **Scraper selection is used** — assert `runScrape($url, 'api')` causes the scrape to run against a store whose `scraper_service` is `api`. Practical approach: configure the store saved as `http`, call `->call('runScrape', 'https://example.com/p', 'api')` with `mockScrape(...)` faking the network, and assert the scrape still succeeds (the override doesn't break) AND — to prove the override is applied — spy on `ScrapeUrl`/capture the store, or assert via a partial mock of `ScrapeUrl` that the passed store's `scraper_service` is `api`. If capturing the store proves impractical, fall back to asserting the select renders with the store's default and that `runScrape` accepts and applies the param (e.g. expose nothing new but assert no regression + the select default).
- **Scraper select renders + defaults** — `mountAction('test')` then assert the `test_scraper` field exists (`assertFormComponentExists` may not apply to action forms; use `assertSee('Scraper')` or assert the mounted action form has the field).
- **Modal description** — assert the rendered modal contains "Dry run the current store settings"; with AI configured, contains "and compare with AI".
- Existing tests stay green (placeholder/gutter/section-description are non-asserted cosmetic tweaks).

Then `lando phpcs-fix && lando phpcs` to `[OK] No errors`; `lando artisan test --parallel`.

## Out of scope

- Persisting the test scraper choice.
- Changing the price-recovery pipeline behaviour (it ignores `description`).
- AI verification/arbitration features.

### ProductSource Feature Specification Document

---

### Overview

This specification outlines the implementation of a `ProductSource` feature that enables the system to search for products across multiple online sources (both deal sites and online stores) and extract product information including titles and URLs.

---

### Database Schema

#### Migration: `create_product_sources_table`

Create a new table `product_sources` with the following structure:

```php
Schema::create('product_sources', function (Blueprint $table) {
    $table->id();
    $table->string('name'); // e.g., "OzBargain", "Amazon AU"
    $table->string('slug')->unique();
    $table->text('search_url'); // URL template with :search_term placeholder
    $table->string('type'); // PHP enum: ProductSourceType (deals_site, online_store)
    $table->foreignIdFor(Store::class)->nullable(); // Only if type is 'online_store'
    $table->json('extraction_strategy'); // How to extract products from search results
    $table->json('settings')->nullable(); // Additional settings (scraper service, etc.)
    $table->string('status')->default('active'); // PHP enum: ProductSourceStatus
    $table->foreignIdFor(User::class)->nullable();
    $table->text('notes')->nullable();
    $table->timestamps();
});
```

**Key Fields:**
- `search_url`: Template URL like `https://www.ozbargain.com.au/search/node/:search_term`
- `type`: String field storing PHP enum `ProductSourceType` value (deals_site, online_store)
- `store_id`: Foreign key to `stores` table, only populated when `type` is `online_store`
- `extraction_strategy`: JSON containing strategies for extracting product list items, titles, and URLs
- `status`: String field storing PHP enum `ProductSourceStatus` value (active, inactive, draft)

---

### PHP Enums

#### ProductSourceType Enum

**Location:** `app/Enums/ProductSourceType.php`

```php
<?php

namespace App\Enums;

use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasLabel;

enum ProductSourceType: string implements HasDescription, HasLabel
{
    case DealsSite = 'deals_site';
    case OnlineStore = 'online_store';

    public function getLabel(): string
    {
        return match ($this) {
            self::DealsSite => 'Deals Site',
            self::OnlineStore => 'Online Store',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::DealsSite => 'Site that aggregates deals/links (e.g., OzBargain)',
            self::OnlineStore => 'Site that sells products directly (e.g., Amazon)',
        };
    }
}
```

#### ProductSourceStatus Enum

**Location:** `app/Enums/ProductSourceStatus.php`

```php
<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ProductSourceStatus: string implements HasLabel, HasColor
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Draft = 'draft';

    public function getLabel(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Inactive => 'Inactive',
            self::Draft => 'Draft',
        };
    }

    public function getColor(): string|array
    {
        return match ($this) {
            self::Active => 'success',
            self::Inactive => 'danger',
            self::Draft => 'gray',
        };
    }
}
```

---

### Models & Relationships

#### ProductSource Model

**Location:** `app/Models/ProductSource.php`

**Relationships:**
- `belongsTo(User::class)` - Optional owner
- `belongsTo(Store::class)` - Only when type is `online_store`
- Consider adding a polymorphic relationship or pivot table if you want to track which products came from which source

**Key Attributes:**
```php
protected $fillable = [
    'name',
    'search_url',
    'type',
    'store_id',
    'extraction_strategy',
    'settings',
    'status',
    'notes',
    'user_id',
];

protected function casts(): array
{
    return [
        'type' => ProductSourceType::class,
        'status' => ProductSourceStatus::class,
        'extraction_strategy' => 'array',
        'settings' => 'array',
    ];
}
```

**Slug Configuration:**
```php
use Spatie\Sluggable\HasSlug;
use Spatie\Sluggable\SlugOptions;

public function getSlugOptions(): SlugOptions
{
    return SlugOptions::create()
        ->generateSlugsFrom('name')
        ->saveSlugsTo('slug');
}
```

**Validation Rules:**
- `search_url` must contain `:search_term` placeholder
- `store_id` must be null when `type` is `deals_site`
- `store_id` must be present when `type` is `online_store`

---

### Extraction Strategy Structure

The `extraction_strategy` JSON field should follow this structure:

```json
{
  "list_container": {
    "type": "selector",
    "value": ".search-results .item"
  },
  "product_title": {
    "type": "selector",
    "value": "h2.title a|textContent"
  },
  "product_url": {
    "type": "selector",
    "value": "h2.title a|href"
  },
  "product_price": {
    "type": "selector",
    "value": ".price|textContent"
  }
}
```

**Strategy Types:** (reuse existing `ScraperStrategyType` enum)
- `selector` - CSS selectors (with pipe notation for attributes: `element|attribute`)
- `xpath` - XPath expressions
- `regex` - Regular expressions
- `json` - JSON path (for API responses)

**Required Fields:**
- `list_container` - How to find individual product items in search results
- `product_title` - How to extract the title from each item
- `product_url` - How to extract the product/deal URL from each item

**Optional Fields:**
- `product_price` - How to extract price if visible in search results
- `product_image` - How to extract thumbnail image if needed

---

### Service Layer

#### ProductSourceSearchService

**Location:** `app/Services/ProductSourceSearchService.php`

**Purpose:** Execute searches across ProductSources and extract results

**Key Methods:**

```php
public static function search(ProductSource $source, string $query): Collection
{
    // 1. Replace :search_term in search_url with encoded query
    // 2. Use scraper service (HTTP or API based on settings)
    // 3. Apply extraction_strategy to get list of products
    // 4. Return Collection of ProductSearchResultDto
}

public function extractProducts(string $html, array $strategy): Collection
{
    // Parse HTML/JSON and extract products based on strategy
    // Return collection of arrays with 'title', 'url', 'price' keys
}
```

**DTO: ProductSearchResultDto**
```php
class ProductSearchResultDto
{
    public function __construct(
        public string $title,
        public string $url,
        public ?float $price = null,
        public ?string $image = null,
        public ?int $source_id = null,
    ) {}
}
```

---

### Filament Resource

#### ProductSourceResource

**Location:** `app/Filament/Resources/ProductSourceResource.php`

**Navigation:**
- Icon: `heroicon-o-magnifying-glass-circle` or `heroicon-o-globe-alt`
- Label: "Product Sources"
- Sort: 25 (after Stores which is 20)

**Form Schema:**

```php
Forms\Components\Section::make('Basics')->schema([
    TextInput::make('name')
        ->required()
        ->label('Source Name')
        ->helperText('e.g., "OzBargain", "Amazon Australia"'),
    
    Forms\Components\Radio::make('type')
        ->options(ProductSourceType::class)
        ->required()
        ->reactive()
        ->default(ProductSourceType::OnlineStore),
    
    Forms\Components\Select::make('store_id')
        ->label('Associated Store')
        ->relationship('store', 'name')
        ->visible(fn (Get $get) => $get('type') === ProductSourceType::OnlineStore->value)
        ->helperText('Link to existing store for scraping product pages'),
    
    Forms\Components\Select::make('status')
        ->options(ProductSourceStatus::class)
        ->required()
        ->default(ProductSourceStatus::Active),
]),

Forms\Components\Section::make('Search Configuration')->schema([
    TextInput::make('search_url')
        ->required()
        ->label('Search URL Template')
        ->helperText('Use :search_term as placeholder. Example: https://www.example.com/search?q=:search_term')
        ->placeholder('https://www.example.com/search?q=:search_term')
        ->rules(['required', 'url', new ContainsSearchTermPlaceholder]),
]),

Forms\Components\Section::make('Extraction Strategy')->schema([
    Forms\Components\Group::make(self::makeExtractionInput('list_container'))
        ->columns(2)
        ->label('List Container')
        ->helperText('How to find individual product items in search results'),
    
    Forms\Components\Group::make(self::makeExtractionInput('product_title'))
        ->columns(2)
        ->label('Product Title')
        ->helperText('How to extract product title from each item'),
    
    Forms\Components\Group::make(self::makeExtractionInput('product_url'))
        ->columns(2)
        ->label('Product URL')
        ->helperText('How to extract product/deal URL from each item'),
    
    Forms\Components\Group::make(self::makeExtractionInput('product_price'))
        ->columns(2)
        ->label('Product Price (Optional)'),
])
    ->statePath('extraction_strategy')
    ->description('Define how to extract products from search results'),

// Reuse scraper service section from StoreResource
Forms\Components\Section::make('Scraper Service')->schema([
    // Same as StoreResource
]),

Forms\Components\Section::make('Notes')->schema([
    Forms\Components\RichEditor::make('notes')->hiddenLabel(),
]),
```

**Helper Method:**
```php
protected static function makeExtractionInput(string $key): array
{
    // Similar to StoreResource::makeStrategyInput()
    // Include type selector (CSS/XPath/Regex/JSON) and value input
}
```

**Table Columns:**
- Name (searchable, sortable)
- Type badge (using ProductSourceType enum)
- Associated Store (when applicable)
- Status badge (using ProductSourceStatus enum)
- Actions: Edit, Delete, Test Search

**Custom Actions:**

1. **TestSearchAction** - Test the search and extraction
```php
Action::make('test')
    ->label('Test Search')
    ->form([
        TextInput::make('search_query')
            ->label('Search Query')
            ->required()
            ->placeholder('e.g., "laptop"'),
    ])
    ->action(function (ProductSource $record, array $data) {
        $results = ProductSourceSearchService::search($record, $data['search_query']);
        // Display results in modal or notification
    })
```

---

### Integration with Existing Search

#### Enhance CreateViaSearchForm Widget

**Location:** `app/Filament/Resources/ProductResource/Widgets/CreateViaSearchForm.php`

**Enhancement:** Add option to search across configured ProductSources in addition to existing search functionality.

**New Form Field:**
```php
Forms\Components\CheckboxList::make('product_sources')
    ->label('Search Product Sources')
    ->options(ProductSource::where('status', ProductSourceStatus::Active)->pluck('name', 'id'))
    ->helperText('Search configured sources for products')
```

**Search Logic:**
```php
public function search()
{
    $results = collect();
    
    // Existing search logic
    $existingResults = SearchService::new($this->searchQuery)->getResults();
    
    // Add ProductSource searches
    if (!empty($this->product_sources)) {
        foreach ($this->product_sources as $sourceId) {
            $source = ProductSource::find($sourceId);
            $sourceResults = ProductSourceSearchService::search($source, $this->searchQuery);
            $results = $results->merge($sourceResults);
        }
    }
    
    // Display combined results
}
```

---

### Testing Requirements

#### Unit Tests

**File:** `tests/Unit/Models/ProductSourceTest.php`

```php
it('validates search_url contains search_term placeholder')
it('requires store_id when type is ProductSourceType::OnlineStore')
it('disallows store_id when type is ProductSourceType::DealsSite')
it('generates slug from name')
it('casts type to ProductSourceType enum')
it('casts status to ProductSourceStatus enum')
it('casts extraction_strategy to array')
it('casts settings to array')
it('belongs to store when type is online_store')
it('belongs to user')
it('defaults status to active')
```

**File:** `tests/Unit/Services/ProductSourceSearchServiceTest.php`

```php
it('replaces search_term placeholder in url')
it('extracts products using css selector strategy')
it('extracts products using xpath strategy')
it('extracts products using regex strategy')
it('extracts products using json strategy')
it('handles empty search results')
it('returns collection of ProductSearchResultDto')
```

#### Feature Tests

**File:** `tests/Feature/Filament/ProductSourceTest.php`

```php
it('can render product source index page')
it('can render create product source page')
it('can create deals_site product source')
it('can create online_store product source with store')
it('validates store_id required for online_store')
it('validates search_url contains placeholder')
it('can edit product source')
it('can delete product source')
it('can test search functionality')
it('can update status')
it('can filter by status')
it('can filter by type')
```

**File:** `tests/Feature/Services/ProductSourceSearchTest.php`

```php
it('can search a deals site and extract results')
it('can search an online store and extract results')
it('handles failed http requests gracefully')
it('respects scraper service settings')
```

---

### Validation Rules

#### Custom Rule: ContainsSearchTermPlaceholder

**Location:** `app/Rules/ContainsSearchTermPlaceholder.php`

```php
public function validate(string $attribute, mixed $value, Closure $fail): void
{
    if (!str_contains($value, ':search_term')) {
        $fail('The :attribute must contain :search_term placeholder');
    }
}
```

---

### Development Environment & Commands

This project uses Lando for local development running inside Docker containers. When working on a remote server, all Lando commands must be executed via SSH.

#### Running Lando Commands Remotely

**Command Pattern:**
```bash
ssh dev "cd ~/sites/price-buddy; /home/jez/.lando/bin/lando [command]"
```

**Common Commands:**

```bash
# Run tests
ssh dev "cd ~/sites/price-buddy; /home/jez/.lando/bin/lando artisan test"

# Run specific test file
ssh dev "cd ~/sites/price-buddy; /home/jez/.lando/bin/lando artisan test tests/Unit/Models/ProductSourceTest.php"

# Run tests with filter
ssh dev "cd ~/sites/price-buddy; /home/jez/.lando/bin/lando artisan test --filter=ProductSource"

# Run migrations
ssh dev "cd ~/sites/price-buddy; /home/jez/.lando/bin/lando artisan migrate"

# Run code formatter
ssh dev "cd ~/sites/price-buddy; /home/jez/.lando/bin/lando composer pint"

# Create Artisan files
ssh dev "cd ~/sites/price-buddy; /home/jez/.lando/bin/lando artisan make:model ProductSource"

# Run seeders
ssh dev "cd ~/sites/price-buddy; /home/jez/.lando/bin/lando artisan db:seed --class=ProductSourceSeeder"
```

**Note:** The root of the codebase is mounted inside the container at `/app`.

---

### Implementation Steps

**Important:** This project follows Test-Driven Development (TDD). Always write tests BEFORE implementing functionality. Each phase should start with writing comprehensive tests that will initially fail, then implement the code to make tests pass.

#### Phase 1: Enums & Database (TDD)
1. **Write enum tests first** - Create unit tests for `ProductSourceType` and `ProductSourceStatus` enums
2. Create `ProductSourceType` enum (`app/Enums/ProductSourceType.php`)
3. Create `ProductSourceStatus` enum (`app/Enums/ProductSourceStatus.php`)
4. Run enum tests - ensure they pass
5. **Write migration tests** - Create tests that verify migration structure
6. Create migration for `product_sources` table (use string fields for enums, not database enums)
7. Run migration tests - ensure they pass

#### Phase 2: Model (TDD)
1. **Write model unit tests first** - All tests from the "Unit Tests" section above
2. Create `ProductSource` model with:
   - Relationships (belongsTo User, belongsTo Store)
   - Slug configuration
   - Enum casts for `type` and `status`
   - Array casts for `extraction_strategy` and `settings`
3. Run model tests - ensure they pass
4. Create factory for `ProductSource` with states for different types
5. Create seeder with example data (OzBargain as DealsSite, Amazon as OnlineStore)

#### Phase 3: Validation Rules (TDD)
1. **Write validation rule tests first**
2. Create `ContainsSearchTermPlaceholder` rule
3. Run validation tests - ensure they pass

#### Phase 4: Service Layer (TDD)
1. **Write unit tests for `ProductSourceSearchService` first** - All tests from "Unit Tests" section
2. Create `ProductSearchResultDto`
3. Implement `ProductSourceSearchService` with extraction logic
4. Reuse existing `ScrapeUrl` service where possible
5. Run service tests - ensure they pass

#### Phase 5: Filament Resource (TDD)
1. **Write Filament feature tests first** - All tests from "Feature Tests" section
2. Create `ProductSourceResource` using artisan command
3. Customize form schema:
   - Use enum classes directly in options (not manual arrays)
   - Use Select for status (not Toggle)
   - Add conditional store_id field
4. Customize table with columns, filters, and actions
5. Create `TestSearchAction` for testing sources
6. Run Filament tests - ensure they pass

#### Phase 6: Integration (TDD)
1. **Write integration tests first**
2. Add ProductSource search to existing search widget
3. Update queries to use `ProductSourceStatus::Active` instead of `is_active`
4. Test combined search functionality
5. Run integration tests - ensure they pass

#### Phase 7: Polish & Final Validation
1. Add appropriate icons and styling
2. Add helpful validation messages
3. Create documentation in notes/examples
4. Run code formatter to ensure style compliance
5. Run full test suite - ensure ALL tests pass
6. Manual testing of complete workflow

---

### Questions for Clarification

#### 1. Result Storage
**Q:** Should search results from ProductSources be stored temporarily (like `UrlResearch`) or only displayed in real-time?

**Recommendation:** Follow the existing `UrlResearch` pattern - store results temporarily with pruning after 30 days.

#### 2. Deal Site URL Handling
**Q:** When a ProductSource is a deals_site, the extracted URL points to a deal page (not a store product page). Should we:
- a) Follow the deal link to find the actual store product URL?
- b) Store the deal URL as-is?
- c) Extract the store URL from the deal page?

**Recommendation:** Option (b) initially - store the deal URL as-is. Later enhancement can add logic to extract actual store URLs.

#### 3. Multiple Sources
**Q:** Should users be able to search multiple ProductSources simultaneously or one at a time?

**Recommendation:** Allow multiple sources (checkbox list) with results merged and displayed in a unified table.

#### 4. Source Priority
**Q:** Should ProductSources have a priority/order field for controlling which sources are searched first?

**Recommendation:** Add `priority` integer field (default 0) for future sorting capability.

#### 5. Rate Limiting
**Q:** Should there be rate limiting or delays between searching multiple sources?

**Recommendation:** Yes - implement configurable delay in settings (e.g., 1-2 seconds between sources) to avoid overwhelming external sites.

---

### Additional Considerations

#### Caching
Consider caching search results per query for 5-10 minutes to avoid repeated requests.

#### Error Handling
Each ProductSource search should fail gracefully - if one source fails, others should still work.

#### Logging
Log search attempts, successes, and failures for debugging and monitoring.

#### Settings Inheritance
If ProductSource is linked to a Store, consider inheriting scraper settings from the Store unless overridden.

---

### Example Data

#### OzBargain (Deals Site)
```php
[
    'name' => 'OzBargain',
    'type' => ProductSourceType::DealsSite,
    'status' => ProductSourceStatus::Active,
    'search_url' => 'https://www.ozbargain.com.au/search/node/:search_term',
    'extraction_strategy' => [
        'list_container' => [
            'type' => 'selector',
            'value' => '.node.node-ozbdeal'
        ],
        'product_title' => [
            'type' => 'selector',
            'value' => 'h2.title a'
        ],
        'product_url' => [
            'type' => 'selector',
            'value' => 'h2.title a|href'
        ],
    ],
]
```

#### Amazon AU (Online Store)
```php
[
    'name' => 'Amazon Australia',
    'type' => ProductSourceType::OnlineStore,
    'status' => ProductSourceStatus::Active,
    'store_id' => 1, // Assumes Amazon store exists
    'search_url' => 'https://www.amazon.com.au/s?k=:search_term',
    'extraction_strategy' => [
        'list_container' => [
            'type' => 'selector',
            'value' => 'div[data-component-type="s-search-result"]'
        ],
        'product_title' => [
            'type' => 'selector',
            'value' => 'h2 a span'
        ],
        'product_url' => [
            'type' => 'selector',
            'value' => 'h2 a|href'
        ],
        'product_price' => [
            'type' => 'selector',
            'value' => '.a-price-whole'
        ],
    ],
]
```

---

### Summary

This specification provides a complete blueprint for implementing ProductSource functionality. The feature leverages existing scraping infrastructure (scrapers, strategies, services) while adding a new abstraction for searching across multiple sources. The implementation follows Laravel and Filament best practices with comprehensive testing at each phase.

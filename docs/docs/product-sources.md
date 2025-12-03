# Product Sources

## Overview

Product Sources are external websites that can be searched for products. They enable automated product discovery across multiple online platforms including deal aggregators (like OzBargain) and online stores (like Amazon).

## Purpose

Product Sources allow you to:

- **Search Multiple Platforms**: Query various deal sites and online stores for products
- **Aggregate Results**: Collect product information from diverse sources in one place
- **Automated Discovery**: Find products and deals without manual browsing
- **Flexible Configuration**: Define custom extraction strategies for any website

## Types of Product Sources

### Deals Site (Aggregator)
Sites that aggregate deals and product links from multiple sources.

**Examples**: OzBargain, Slickdeals, HotUKDeals

**Characteristics**:
- URLs typically point to deal pages, not direct product pages
- May require additional processing to extract actual store URLs
- No associated Store record required

### Online Store
Sites that sell products directly.

**Examples**: Amazon, eBay, specialized retailers

**Characteristics**:
- URLs point directly to product pages
- Must be linked to an existing Store record
- Can inherit scraping settings from the associated Store

## Configuration

### Basic Information

| Field | Required | Description |
|-------|----------|-------------|
| Name | Yes | Human-readable name (e.g., "Amazon Australia") |
| Type | Yes | Either "Deals Site" or "Online Store" |
| Status | Yes | Active, Inactive, or Draft |
| Store | Conditional | Required for Online Store type, must be null for Deals Site |
| Search URL | Yes | URL template with `:search_term` placeholder |

### Search URL Configuration

The Search URL must contain the placeholder `:search_term` which will be replaced with the actual search query.

**Examples**:
```
https://www.ozbargain.com.au/search/node/:search_term
https://www.amazon.com.au/s?k=:search_term
https://www.example.com/search?q=:search_term&type=products
```

### Extraction Strategy

The extraction strategy defines how to parse search results and extract product information. It consists of three required components:

#### 1. List Container
Identifies individual product items in search results. Note: Ideally this returns HTML rather than plain text so we can
extract the title and URL easier, to do this with a CSS selector use `!` at the beginning of the selector.

**Example (Full html for each result)**:
```json
{
  "type": "selector",
  "value": "!.product-item"
}
```

#### 2. Product Title (Inside list container)
Extracts the product title from each item.

**Example (Plain text for each result)**:
```json
{
  "type": "selector",
  "value": "h2.title a"
}
```

#### 3. Product URL (Inside list container)
Extracts the product/deal URL from each item.

**Example(Extracting the link text)***:
```json
{
  "type": "selector",
  "value": "a.product-link|href"
}
```

### Extraction Strategy Types

| Type | Description | Example |
|------|-------------|---------|
| `selector` | CSS selectors | `.price\|textContent` or `h2 a\|href` |
| `xpath` | XPath expressions | `//div[@class="price"]/text()` |
| `regex` | Regular expressions | `/\$([0-9.]+)/` |
| `json` | JSON path | `data.products[0].title` |

**Note**: For CSS selectors, use the pipe character (`|`) to extract attributes:
- `a|href` - Extract href attribute
- `img|src` - Extract src attribute
- `div` - Extract text content (default)

### Scraper Settings

Product Sources can configure scraper service settings:

| Setting | Default | Description |
|---------|---------|-------------|
| Scraper Service | HTTP | Service to use (HTTP or API) |
| Service Options | - | Key-value pairs for scraper configuration |

## Using Product Sources

### Via Filament Admin Panel

1. Navigate to **Product Sources** in the admin panel
2. Click **New Product Source**
3. Fill in the required fields:
   - Name and type
   - Search URL with `:search_term` placeholder
   - Extraction strategy for list container, title, and URL
4. Optionally configure scraper settings
5. Save and test the source

### Via API

Product Sources are available via RESTful API endpoints.

#### Authentication
All endpoints require authentication using Laravel Sanctum tokens.

#### Base URL
```
/api/product-sources
```

#### Endpoints

**List Product Sources** (GET)
```http
GET /api/product-sources
```

Query Parameters:
- `page` - Page number for pagination
- `per_page` - Results per page (max 100)
- `filter[status]` - Filter by status (active, inactive, draft)
- `filter[type]` - Filter by type (deals_site, online_store)
- `filter[store_id]` - Filter by store ID
- `sort` - Sort field (name, slug, type, status, created_at, updated_at)
- `include` - Include relationships (store, user)
- `fields[product_sources]` - Select specific fields

Example:
```http
GET /api/product-sources?filter[status]=active&include=store&sort=name
```

**Get Product Source** (GET)
```http
GET /api/product-sources/{id}
```

Query Parameters:
- `include` - Include relationships (store, user)

**Create Product Source** (POST)
```http
POST /api/product-sources
Content-Type: application/json

{
  "name": "Example Store",
  "search_url": "https://example.com/search?q=:search_term",
  "type": "online_store",
  "store_id": 1,
  "status": "active",
  "extraction_strategy": {
    "list_container": {
      "type": "selector",
      "value": ".product-item"
    },
    "product_title": {
      "type": "selector",
      "value": "h2.title"
    },
    "product_url": {
      "type": "selector",
      "value": "a.product-link|href"
    }
  },
  "settings": {
    "scraper_service": "http"
  }
}
```

**Update Product Source** (PUT)
```http
PUT /api/product-sources/{id}
Content-Type: application/json

{
  "name": "Updated Name",
  "status": "inactive"
}
```

**Delete Product Source** (DELETE)
```http
DELETE /api/product-sources/{id}
```

### Programmatic Usage

#### Searching a Product Source

```php
use App\Models\ProductSource;

$source = ProductSource::find(1);
$results = $source->search('laptop');

// Results collection contains:
// [
//   ['title' => 'Product Name', 'url' => 'https://...', 'content' => '...'],
//   ...
// ]
```

#### Using the Search Service

```php
use App\Services\ProductSourceSearchService;
use App\Models\ProductSource;

$source = ProductSource::find(1);
$service = ProductSourceSearchService::new($source);

$searchUrl = $service->buildSearchUrl('gaming laptop');
$results = $service->search('gaming laptop');
```

#### Querying Active Sources

```php
use App\Models\ProductSource;
use App\Enums\ProductSourceStatus;

// Get all active sources
$sources = ProductSource::enabled()->get();

// Get active sources by type
$dealsSites = ProductSource::enabled()
    ->where('type', ProductSourceType::DealsSite)
    ->get();
```

## Example Configurations

### OzBargain (Deals Site)

```json
{
  "name": "OzBargain",
  "type": "deals_site",
  "status": "active",
  "search_url": "https://www.ozbargain.com.au/search/node/:search_term",
  "extraction_strategy": {
    "list_container": {
      "type": "selector",
      "value": ".node.node-ozbdeal"
    },
    "product_title": {
      "type": "selector",
      "value": "h2.title a"
    },
    "product_url": {
      "type": "selector",
      "value": "h2.title a|href"
    }
  },
  "settings": {
    "scraper_service": "http"
  }
}
```

### Amazon Australia (Online Store)

```json
{
  "name": "Amazon Australia",
  "type": "online_store",
  "status": "active",
  "store_id": 1,
  "search_url": "https://www.amazon.com.au/s?k=:search_term",
  "extraction_strategy": {
    "list_container": {
      "type": "selector",
      "value": "div[data-component-type='s-search-result']"
    },
    "product_title": {
      "type": "selector",
      "value": "h2 a span"
    },
    "product_url": {
      "type": "selector",
      "value": "h2 a|href"
    }
  },
  "settings": {
    "scraper_service": "http"
  }
}
```

## Best Practices

### URL Configuration
- Always test the search URL in a browser first
- Ensure the placeholder `:search_term` is in the correct position
- Use URL encoding - spaces will be converted to `+` or `%20` automatically

### Extraction Strategy
- Start with simple selectors and refine as needed
- Use browser DevTools to inspect search result HTML structure
- Test selectors thoroughly with various search terms
- Consider using more specific selectors to avoid false matches

### Performance
- Search results are not cached by default
- Consider implementing rate limiting for multiple source searches
- Use appropriate scraper service settings to avoid IP blocks
- Monitor error logs for failed scraping attempts

### Maintenance
- Regularly verify extraction strategies (sites change their HTML)
- Keep sources with status "draft" while testing
- Use "inactive" status to temporarily disable problematic sources
- Document any special considerations in the Notes field

## Troubleshooting

### No Results Returned
1. Verify the search URL is correct
2. Check that `:search_term` placeholder exists
3. Test selectors in browser DevTools
4. Review error logs for scraping failures
5. Verify the site allows scraping (check robots.txt)

### Incorrect Data Extracted
1. Inspect the HTML structure of the search results page
2. Verify selectors match the current site structure
3. Test with different search terms
4. Check if the site uses dynamic loading (may need API scraper)

### Authentication Required
1. Some sites require login - consider using API scraper service
2. Add authentication credentials in scraper settings
3. May need custom scraper implementation for complex authentication

## Related Documentation

- [Stores](./stores.md) - Configure stores for product scraping
- [Products](./products.md) - Managing tracked products
- API Reference - Full API endpoint documentation


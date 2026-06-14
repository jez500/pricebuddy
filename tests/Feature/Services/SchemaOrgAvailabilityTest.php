<?php

namespace Tests\Feature\Services;

use App\Dto\AvailabilityStrategyDto;
use App\Enums\StockStatus;
use App\Models\Store;
use App\Services\ScrapeUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SchemaOrgAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    private function jsonLdPage(string $availabilityLabel): string
    {
        $json = json_encode([
            '@context' => 'https://schema.org/',
            '@type' => 'Product',
            'name' => 'Widget',
            'offers' => [
                '@type' => 'Offer',
                'priceCurrency' => 'USD',
                'price' => '19.99',
                'availability' => 'https://schema.org/'.$availabilityLabel,
            ],
        ]);

        return "<html><head><script type=\"application/ld+json\">{$json}</script></head><body></body></html>";
    }

    private function schemaOrgStore(): Store
    {
        return Store::factory()->create([
            'domains' => [['domain' => 'example.com']],
            'scrape_strategy' => [
                'title' => ['type' => 'schema_org', 'value' => null],
                'price' => ['type' => 'schema_org', 'value' => null],
                'availability' => ['type' => 'schema_org', 'value' => null],
            ],
            'settings' => ['scraper_service' => 'http'],
        ]);
    }

    public function test_schema_org_out_of_stock_resolves_without_match_config(): void
    {
        $store = $this->schemaOrgStore();
        Http::fake(['*' => Http::response($this->jsonLdPage('OutOfStock'))]);

        $scrape = ScrapeUrl::new('https://example.com/p')->scrape();

        $this->assertSame(StockStatus::OutOfStock, StockStatus::resolveAvailability(
            data_get($scrape, 'availability'),
            data_get($store, 'scrape_strategy.availability'),
        ));
    }

    public function test_schema_org_in_stock_resolves_in_stock_without_match_config(): void
    {
        $store = $this->schemaOrgStore();
        Http::fake(['*' => Http::response($this->jsonLdPage('InStock'))]);

        $scrape = ScrapeUrl::new('https://example.com/p')->scrape();

        $this->assertSame(StockStatus::InStock, StockStatus::resolveAvailability(
            data_get($scrape, 'availability'),
            data_get($store, 'scrape_strategy.availability'),
        ));
    }
}

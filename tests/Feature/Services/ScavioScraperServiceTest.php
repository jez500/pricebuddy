<?php

namespace Tests\Feature\Services;

use App\Enums\ScraperService;
use App\Enums\StockStatus;
use App\Models\Store;
use App\Services\ScrapeUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ScavioScraperServiceTest extends TestCase
{
    use RefreshDatabase;

    const AMAZON_URL = 'https://www.amazon.com/dp/B0DYB9TNB4';

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('cache:clear');
        config(['services.scavio.key' => 'test-key']);
        Store::query()->delete();
    }

    protected function fixture(string $name): array
    {
        return json_decode((string) file_get_contents(base_path('tests/Fixtures/Scavio/'.$name.'.json')), true);
    }

    protected function scavioStore(array $strategy = []): Store
    {
        return Store::factory()->create([
            'domains' => [['domain' => 'amazon.com'], ['domain' => 'www.amazon.com']],
            'scrape_strategy' => array_merge([
                'title' => ['type' => 'schema_org', 'value' => null],
                'price' => ['type' => 'schema_org', 'value' => null],
                'image' => ['type' => 'schema_org', 'value' => null],
                'availability' => ['type' => 'schema_org', 'value' => null],
            ], $strategy),
            'settings' => ['scraper_service' => ScraperService::Scavio->value],
        ]);
    }

    public function test_scrape_reads_the_product_from_scavio(): void
    {
        $store = $this->scavioStore();
        Http::fake(['api.scavio.dev/*' => Http::response($this->fixture('amazon-product'))]);

        $scrape = ScrapeUrl::new(self::AMAZON_URL)->scrape();

        $this->assertSame('Lexar 1TB PLAY PRO micro SD Express Card, 900MB/s Read, 4K, V30, UHS-I', $scrape['title']);
        $this->assertSame('429.99', $scrape['price']);
        $this->assertSame('https://m.media-amazon.com/images/I/61z50jYMXkL._AC_SL1500_.jpg', $scrape['image']);
        $this->assertSame(StockStatus::InStock, ScrapeUrl::resolveStockStatus($scrape, $store->scrape_strategy->availability));
        Http::assertSentCount(1);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'amazon.com/dp'));
    }

    public function test_json_strategies_read_the_raw_response(): void
    {
        $this->scavioStore(['price' => ['type' => 'json', 'value' => 'data.price']]);
        Http::fake(['api.scavio.dev/*' => Http::response($this->fixture('amazon-product'))]);

        $scrape = ScrapeUrl::new(self::AMAZON_URL)->scrape();

        $this->assertSame('429.99', $scrape['price']);
    }

    public function test_unavailable_product_is_out_of_stock_not_an_error(): void
    {
        $store = $this->scavioStore();
        $response = $this->fixture('amazon-product');
        $response['data']['price'] = null;
        $response['data']['availability'] = 'Currently unavailable.';
        Http::fake(['api.scavio.dev/*' => Http::response($response)]);

        $scrape = ScrapeUrl::new(self::AMAZON_URL)->scrape();

        $this->assertNotEmpty($scrape['title']);
        $this->assertNull($scrape['price']);
        $this->assertSame(StockStatus::OutOfStock, ScrapeUrl::resolveStockStatus($scrape, $store->scrape_strategy->availability));
    }

    public function test_api_error_leaves_no_title_or_price(): void
    {
        $this->scavioStore();
        Http::fake(['api.scavio.dev/*' => Http::response(['error' => 'Invalid API key'], 401)]);

        $scrape = ScrapeUrl::new(self::AMAZON_URL)->setMaxAttempts(1)->setSendUiNotifications(false)->scrape();

        $this->assertArrayNotHasKey('title', $scrape);
        $this->assertArrayNotHasKey('price', $scrape);
    }

    public function test_a_failed_scavio_call_is_not_retried(): void
    {
        $this->scavioStore();
        Http::fake(['api.scavio.dev/*' => Http::response(['error' => 'Product not found'], 404)]);

        ScrapeUrl::new(self::AMAZON_URL)->setMaxAttempts(3)->setSendUiNotifications(false)->scrape();

        Http::assertSentCount(1);
    }

    public function test_other_scraper_services_are_unchanged(): void
    {
        Store::factory()->create([
            'domains' => [['domain' => 'example.com']],
            'scrape_strategy' => ['title' => ['type' => 'schema_org', 'value' => null], 'price' => ['type' => 'schema_org', 'value' => null]],
            'settings' => ['scraper_service' => ScraperService::Http->value],
        ]);
        $json = json_encode(['@type' => 'Product', 'name' => 'Widget', 'offers' => ['price' => '19.99']]);
        Http::fake(['*' => Http::response("<html><head><script type=\"application/ld+json\">{$json}</script></head></html>")]);

        $scrape = ScrapeUrl::new('https://example.com/p')->scrape();

        $this->assertSame('Widget', $scrape['title']);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'scavio'));
    }

    public function test_self_healing_is_off_for_scavio_stores(): void
    {
        $this->assertTrue($this->scavioStore()->ai_self_healing_disabled);
        $this->assertFalse(Store::factory()->create()->ai_self_healing_disabled);
    }
}

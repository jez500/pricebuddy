<?php

namespace Tests\Feature\Models;

use App\Enums\ScraperService;
use App\Models\Price;
use App\Models\Product;
use App\Models\Store;
use App\Models\Url;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_initials_are_generated_correctly()
    {
        $store = Store::factory()->create(['name' => 'Example Store']);
        $this->assertEquals('ES', $store->initials);
    }

    public function test_initials_handle_single_word_name()
    {
        $store = Store::factory()->create(['name' => 'Example']);
        $this->assertEquals('EX', $store->initials);
    }

    public function test_initials_use_provided_value()
    {
        $store = Store::factory()->create(['name' => 'Example Store', 'initials' => 'EXS']);
        $this->assertEquals('EXS', $store->initials);
    }

    public function test_domains_html_returns_correct_format()
    {
        $store = Store::factory()->create(['domains' => [['domain' => 'example.com'], ['domain' => 'test.com']]]);
        $this->assertEquals('example.com, test.com', $store->domains_html);
    }

    public function test_domains_html_handles_empty_domains()
    {
        $store = Store::factory()->create(['domains' => []]);
        $this->assertEquals('', $store->domains_html);
    }

    public function test_scraper_service_returns_default_value()
    {
        $store = Store::factory()->create(['settings' => []]);
        $this->assertEquals(ScraperService::Http->value, $store->scraper_service);
    }

    public function test_scraper_service_returns_custom_value()
    {
        $store = Store::factory()->create(['settings' => ['scraper_service' => ScraperService::Api->value]]);
        $this->assertEquals(ScraperService::Api->value, $store->scraper_service);
    }

    public function test_scraper_options_returns_correct_format()
    {
        $store = Store::factory()->create(['settings' => ['scraper_service_settings' => "option1=value1\noption2=value2"]]);
        $this->assertEquals(['option1' => 'value1', 'option2' => 'value2'], $store->scraper_options);
    }

    public function test_scraper_options_handles_empty_settings()
    {
        $store = Store::factory()->create(['settings' => ['scraper_service_settings' => '']]);
        $this->assertEmpty($store->scraper_options);
    }

    public function test_scraper_options_ignores_invalid_entries()
    {
        $store = Store::factory()->create(['settings' => ['scraper_service_settings' => "option1=value1\ninvalid_entry"]]);
        $this->assertEquals(['option1' => 'value1'], $store->scraper_options);
    }

    public function test_deleting_store_cascades_to_urls_and_prices_and_updates_product_caches()
    {
        // Create a store with multiple URLs across multiple products
        $store = Store::factory()->create();
        $product1 = Product::factory()->create();
        $product2 = Product::factory()->create();

        // Create URLs for the store
        $url1 = Url::factory()->create(['store_id' => $store->id, 'product_id' => $product1->id]);
        $url2 = Url::factory()->create(['store_id' => $store->id, 'product_id' => $product1->id]);
        $url3 = Url::factory()->create(['store_id' => $store->id, 'product_id' => $product2->id]);

        // Create prices for each URL
        Price::factory()->create(['url_id' => $url1->id, 'store_id' => $store->id, 'price' => 100]);
        Price::factory()->create(['url_id' => $url2->id, 'store_id' => $store->id, 'price' => 150]);
        Price::factory()->create(['url_id' => $url3->id, 'store_id' => $store->id, 'price' => 200]);

        // Create another URL from a different store for product1 to ensure it's not deleted
        $otherStore = Store::factory()->create();
        $otherUrl = Url::factory()->create(['store_id' => $otherStore->id, 'product_id' => $product1->id]);
        $otherPrice = Price::factory()->create(['url_id' => $otherUrl->id, 'store_id' => $otherStore->id, 'price' => 75]);

        // Record initial counts
        $this->assertEquals(4, Url::count());
        $this->assertEquals(4, Price::count());

        // Delete the store
        $store->delete();

        // Assert URLs for the deleted store are removed
        $this->assertDatabaseMissing('urls', ['id' => $url1->id]);
        $this->assertDatabaseMissing('urls', ['id' => $url2->id]);
        $this->assertDatabaseMissing('urls', ['id' => $url3->id]);

        // Assert prices for deleted URLs are removed
        $this->assertEquals(1, Price::count());
        $this->assertDatabaseHas('prices', ['id' => $otherPrice->id]);

        // Assert URLs from other stores remain
        $this->assertDatabaseHas('urls', ['id' => $otherUrl->id]);

        // Assert product price caches were updated
        $product1->refresh();
        $product2->refresh();
        $this->assertNotNull($product1->price_cache);
        $this->assertNotNull($product2->price_cache);
    }
}

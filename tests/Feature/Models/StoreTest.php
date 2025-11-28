<?php

namespace Tests\Feature\Models;

use App\Enums\ScraperService;
use App\Models\Price;
use App\Models\Product;
use App\Models\Store;
use App\Models\Url;
use App\Models\User;
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

    public function test_deleting_store_deletes_associated_urls()
    {
        $user = User::factory()->create();
        $store = Store::factory()->create();
        $product = Product::factory()->create(['user_id' => $user->id]);
        $url = Url::factory()->create([
            'store_id' => $store->id,
            'product_id' => $product->id,
        ]);

        $this->assertDatabaseHas('urls', ['id' => $url->id]);

        $store->delete();

        $this->assertDatabaseMissing('urls', ['id' => $url->id]);
    }

    public function test_deleting_store_deletes_associated_prices()
    {
        $user = User::factory()->create();
        $store = Store::factory()->create();
        $product = Product::factory()->create(['user_id' => $user->id]);
        $url = Url::factory()->create([
            'store_id' => $store->id,
            'product_id' => $product->id,
        ]);
        $price = Price::factory()->create(['url_id' => $url->id]);

        $this->assertDatabaseHas('prices', ['id' => $price->id]);

        $store->delete();

        $this->assertDatabaseMissing('prices', ['id' => $price->id]);
    }

    public function test_deleting_store_updates_product_price_cache()
    {
        $user = User::factory()->create();
        $store1 = Store::factory()->create(['name' => 'Store 1']);
        $store2 = Store::factory()->create(['name' => 'Store 2']);
        $product = Product::factory()->create(['user_id' => $user->id]);

        // Create URLs for both stores.
        $url1 = Url::factory()->create([
            'store_id' => $store1->id,
            'product_id' => $product->id,
        ]);
        $url2 = Url::factory()->create([
            'store_id' => $store2->id,
            'product_id' => $product->id,
        ]);

        // Create prices for both URLs.
        Price::factory()->create(['url_id' => $url1->id, 'price' => 100]);
        Price::factory()->create(['url_id' => $url2->id, 'price' => 150]);

        // Update price cache to include both stores.
        $product->updatePriceCache();
        $product->refresh();

        // Verify both stores are in price cache.
        $this->assertCount(2, $product->price_cache);
        $storeIdsInCache = collect($product->price_cache)->pluck('store_id')->toArray();
        $this->assertContains($store1->id, $storeIdsInCache);
        $this->assertContains($store2->id, $storeIdsInCache);

        // Delete store1.
        $store1->delete();

        // Refresh product and verify price cache only contains store2.
        $product->refresh();
        $this->assertCount(1, $product->price_cache);
        $storeIdsInCache = collect($product->price_cache)->pluck('store_id')->toArray();
        $this->assertNotContains($store1->id, $storeIdsInCache);
        $this->assertContains($store2->id, $storeIdsInCache);
    }

    public function test_deleting_store_with_multiple_products_updates_all_price_caches()
    {
        $user = User::factory()->create();
        $store = Store::factory()->create();
        $product1 = Product::factory()->create(['user_id' => $user->id]);
        $product2 = Product::factory()->create(['user_id' => $user->id]);

        // Create URLs for the store pointing to different products.
        $url1 = Url::factory()->create([
            'store_id' => $store->id,
            'product_id' => $product1->id,
        ]);
        $url2 = Url::factory()->create([
            'store_id' => $store->id,
            'product_id' => $product2->id,
        ]);

        // Create prices.
        Price::factory()->create(['url_id' => $url1->id, 'price' => 100]);
        Price::factory()->create(['url_id' => $url2->id, 'price' => 200]);

        // Update price caches.
        $product1->updatePriceCache();
        $product2->updatePriceCache();
        $product1->refresh();
        $product2->refresh();

        // Verify both products have price cache entries.
        $this->assertNotEmpty($product1->price_cache);
        $this->assertNotEmpty($product2->price_cache);

        // Delete the store.
        $store->delete();

        // Refresh products and verify price caches are updated (empty since store was only source).
        $product1->refresh();
        $product2->refresh();
        $this->assertEmpty($product1->price_cache);
        $this->assertEmpty($product2->price_cache);
    }
}

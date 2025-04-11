<?php

namespace Tests\Feature\Models;

use App\Dto\PriceCacheDto;
use App\Enums\Statuses;
use App\Enums\Trend;
use App\Models\Price;
use App\Models\Product;
use App\Models\Store;
use App\Models\Url;
use App\Models\User;
use App\Services\Helpers\SettingsHelper;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

class ProductTest extends TestCase
{
    use RefreshDatabase;

    const DEFAULT_URL = 'https://example.com';

    public function test_product_has_urls()
    {
        $this->assertIsString(self::DEFAULT_URL);
        $product = $this->createOneProductWithUrlAndPrices();

        $url = $product->urls->first();
        $this->assertInstanceOf(Url::class, $url);
        $this->assertEquals(self::DEFAULT_URL, $url->url);
    }

    public function test_product_belongs_to_user()
    {
        $user = User::factory()->create();
        $product = Product::factory()->createOne(['user_id' => $user->id]);

        $this->assertEquals($user->id, $product->user->id);
    }

    public function test_product_has_prices_through_urls()
    {
        $product = $this->createOneProductWithUrlAndPrices();

        foreach ($product->prices as $price) {
            $this->assertInstanceOf(Price::class, $price);
            $this->assertIsFloat($price->price);
        }
    }

    public function test_scope_published()
    {
        $publishedProduct = Product::factory()->setStatus(Statuses::Published)->createOne();
        $archivedProduct = Product::factory()->setStatus(Statuses::Archived)->createOne();

        $this->assertTrue(Product::published()->get()->contains($publishedProduct));
        $this->assertFalse(Product::published()->get()->contains($archivedProduct));
    }

    public function test_scope_current_user()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $product = Product::factory()->create(['user_id' => $user->id]);
        $otherProduct = Product::factory()->create(['user_id' => 999999]);

        $this->assertTrue(Product::currentUser()->get()->contains($product));
        $this->assertFalse(Product::currentUser()->get()->contains($otherProduct));
    }

    public function test_scope_lowest_price_in_days()
    {
        $this->markTestSkipped('Scope needs more work');

        //        // 60 is not the lowest price in the last 7 days
        //        $product = $this->createOneProductWithUrlAndPrices(prices: [30, 50, 40, 29, 60, 30]);
        //        $this->assertFalse(Product::lowestPriceInDays(4)->get()->contains($product));
        //        $product->delete();
        //
        //        // 60 is not the lowest price in the last 7 days
        //        $product = $this->createOneProductWithUrlAndPrices(prices: [29, 50, 40, 50, 60, 30]);
        //        $this->assertTrue(Product::lowestPriceInDays(4)->get()->contains($product));
        //        $product->delete();
        //
        //        // 60 is not the lowest price in the last 7 days
        //        $product = $this->createOneProductWithUrlAndPrices(prices: [50, 40, 50, 30]);
        //        $this->assertTrue(Product::lowestPriceInDays(7)->get()->contains($product));
    }

    public function test_price_trend_for_lowest_priced_store()
    {
        $product = $this->createOneProductWithUrlAndPrices(prices: [60, 60, 30]);
        $this->assertSame(Trend::Lowest->value, $product->trend);

        $product = $this->createOneProductWithUrlAndPrices(prices: [60, 50, 30, 40]);
        $this->assertSame(Trend::Down->value, $product->trend);

        $product = $this->createOneProductWithUrlAndPrices(prices: [60, 60, 70]);
        $this->assertSame(Trend::Up->value, $product->trend);

        $product = $this->createOneProductWithUrlAndPrices(prices: [60, 59, 61, 60]);
        $this->assertSame(Trend::None->value, $product->trend);
    }

    public function test_price_aggregates_for_lowest_priced_store()
    {
        SettingsHelper::setSetting('default_locale_settings', ['locale' => 'en', 'currency' => 'USD']);

        $product = $this->createOneProductWithUrlAndPrices(prices: [50, 60, 70]);

        $aggregates = $product->price_aggregates;
        $this->assertSame('$50.00', $aggregates['min']);
        $this->assertSame('$70.00', $aggregates['max']);
        $this->assertSame('$60.00', $aggregates['avg']);
    }

    public function test_primary_image_with_fallback()
    {
        $product = $this->createOneProductWithUrlAndPrices(attrs: ['image' => '']);
        $this->assertEquals(asset('/images/placeholder.png'), $product->primary_image);

        $product->update(['image' => 'https://example.com/image.png']);
        $this->assertEquals('https://example.com/image.png', $product->primary_image);
    }

    public function test_short_version_of_product_title()
    {
        $product = $this->createOneProductWithUrlAndPrices(attrs: ['title' => 'This is a very long product title that should be shortened']);
        $this->assertEquals('This is a very long...', $product->title_short);
    }

    public function test_view_url_for_product()
    {
        $product = $this->createOneProductWithUrlAndPrices();
        $this->assertSame('/admin/products/'.$product->getKey(), $product->view_url);
    }

    public function test_key_urls_for_product()
    {
        $product = $this->createOneProductWithUrlAndPrices();
        $actionUrls = $product->action_urls;
        $base = '/admin/products/'.$product->getKey();

        $this->assertSame($base, $actionUrls['view']);
        $this->assertSame($base.'/edit', $actionUrls['edit']);
        $this->assertSame($base.'/fetch', $actionUrls['fetch']); // deprecated, uses livewire.
    }

    public function test_price_cache_is_sorted_by_price()
    {
        $product = Product::factory()->createOne(
            ['price_cache' => [
                ['price' => 20, 'history' => []],
                ['price' => 10, 'history' => []],
                ['price' => 30, 'history' => []],
            ]]);

        $priceCache = $product->getPriceCache();
        $this->assertEquals(10.0, $priceCache->first()->getPrice());
        $this->assertEquals(30.0, $priceCache->last()->getPrice());
    }

    public function test_price_cache_aggregate_calculates_correctly()
    {
        $product = Product::factory()->create(['price_cache' => [
            ['price' => 20, 'history' => [5, 30, 25]],
            ['price' => 10, 'history' => [30, 20, 10]],
            ['price' => 30, 'history' => [50, 20, 20]],
        ]]);

        $this->assertEquals(23.33, $product->getPriceCacheAggregate('avg'));
        $this->assertEquals(50, $product->getPriceCacheAggregate('max'));
        $this->assertEquals(5, $product->getPriceCacheAggregate('min'));
    }

    public function test_build_price_cache_creates_correct_structure()
    {
        Carbon::setTestNow(Carbon::create(2025, 1, 10));
        $product = $this->createOneProductWithUrlAndPrices(prices: [20.2, 30.3, 5.1]);
        Url::factory()->withPrices([30.1, 20.2, 20.2])->createOne(['product_id' => $product->getKey(), 'url' => 'https://example-other.com']);
        Url::factory()->withPrices([30.1, 20.2, 21.2])->createOne(['product_id' => $product->getKey(), 'url' => 'https://example-other2.com']);

        $priceCache = $product->buildPriceCache();

        $this->assertCount(3, $priceCache);
        $first = $priceCache->first();
        $firstStore = Store::find($first['store_id']);
        $this->assertInstanceOf(Store::class, $firstStore);
        $this->assertIsInt($first['store_id']);
        $this->assertSame($firstStore->name, $first['store_name']);

        $firstUrl = Url::find($first['url_id']);
        $this->assertInstanceOf(Url::class, $firstUrl);
        $this->assertIsInt($first['url_id']);
        $this->assertSame($firstUrl->url, $first['url']);

        $this->assertSame('lowest', $first['trend']);
        $this->assertSame(5.1, $first['price']);
        $this->assertSame([
            '2025-01-07' => 20.2,
            '2025-01-08' => 30.3,
            '2025-01-09' => 5.1,
        ], $first['history']);

        $product->updatePriceCache();
        $this->assertSame($priceCache->toArray(), $product->price_cache);
    }

    public function test_all_prices_query_returns_correct_data()
    {
        $product = $this->createOneProductWithUrlAndPrices(prices: [30, 40, 50]);

        $prices = $product->getAllPricesQuery()->get();

        $this->assertCount(3, $prices);

        foreach ($prices as $price) {
            $this->assertIsFloat($price->price);
            $this->assertIsInt($price->store_id);
            $this->assertIsInt($price->url_id);
            $this->assertIsString($price->created_at);
        }
    }

    public function test_update_all_cache()
    {
        Http::fake([
            self::DEFAULT_URL => Http::response(View::make('tests.product-page', ['price' => '$15.00'])->render()),
            'https://example-foo.com' => Http::response(View::make('tests.product-page', ['price' => '$20.00'])->render()),
            'https://example-bar.com' => Http::response(View::make('tests.product-page', ['price' => '$30.00'])->render()),
        ]);

        $product = $this->createOneProductWithUrlAndPrices(prices: [10, 20, 30]);
        Url::factory()->withPrices([30, 20, 20])->createOne([
            'product_id' => $product->getKey(),
            'url' => 'https://example-foo.com',
            'store_id' => Store::factory()->createOne(['domains' => ['domain' => 'example-foo.com']])->getKey(),
        ]);
        Url::factory()->withPrices([30, 20, 20])->createOne([
            'product_id' => $product->getKey(),
            'url' => 'https://example-bar.com',
            'store_id' => Store::factory()->createOne(['domains' => ['domain' => 'example-bar.com']])->getKey(),
        ]);

        $product->updatePrices();

        $this->assertSame([15.0, 20.0, 30.0], $product->getPriceCache()->map(fn ($item) => $item->getPrice())->toArray());
    }

    public function test_price_history_returns_correct_data()
    {
        $product = $this->createOneProductWithUrlAndPrices(prices: [10, 20, 30]);
        $url = $product->urls->first();

        $history = $product->getPriceHistory();
        $this->assertCount(1, $history);
        $this->assertArrayHasKey($url->getKey(), $history);
        $this->assertEquals(30, $history[$url->id]->last());
    }

    public function test_price_history_with_single_price_extended_back_one_day()
    {
        $product = $this->createOneProductWithUrlAndPrices(prices: [20]);
        $url = $product->urls->first();

        $history = $product->getPriceHistory();
        $this->assertCount(1, $history);
        $this->assertArrayHasKey($url->id, $history);
        $this->assertCount(2, $history[$url->id]);
        $this->assertEquals(20.0, $history[$url->id]->first());
    }

    public function test_price_history_excludes_zero_prices()
    {
        $product = $this->createOneProductWithUrlAndPrices(prices: [20, 0, 20]);
        $url = $product->urls->first();

        $history = $product->getPriceHistory();
        $this->assertCount(1, $history);
        $this->assertArrayHasKey($url->id, $history);
        $this->assertEquals(20.0, $history[$url->id]->last());
    }

    public function test_price_cache_dto()
    {
        $product = Product::factory()->addUrlsAndPrices(3, 3)->createOne();
        $this->assertCount(3, $product->urls);
        $this->assertCount(9, $product->prices);

        $cache = $product->refresh()->getPriceCache();
        $this->assertCount(3, $cache);

        /** @var PriceCacheDto $firstItem */
        $firstItem = $cache->first();

        $this->assertIsInt($firstItem->getStoreId());
        $this->assertIsString($firstItem->getStoreName());
        $this->assertIsInt($firstItem->getUrlId());
        $this->assertIsString($firstItem->getUrl());
        $this->assertTrue(in_array($firstItem->getTrend(), ['up', 'down', 'lowest', 'none']));
        $this->assertIsFloat($firstItem->getPrice());
        $this->assertInstanceOf(Collection::class, $firstItem->getHistory());
    }

    public function test_should_notify_on_price()
    {
        $product = $this->createOneProductWithUrlAndPrices(
            prices: [30, 50],
            attrs: [
                'notify_price' => 50.0,
                'notify_percent' => null,
            ]);

        $this->assertTrue($product->shouldNotifyOnPrice(new Price(['price' => 49.99])));
        $this->assertTrue($product->shouldNotifyOnPrice(new Price(['price' => 50])));
        $this->assertFalse($product->shouldNotifyOnPrice(new Price(['price' => 60.01])));

        $product = $this->createOneProductWithUrlAndPrices(
            prices: [30, 50],
            attrs: [
                'notify_price' => null,
                'notify_percent' => 10.0,
            ]);

        $this->assertFalse($product->shouldNotifyOnPrice(new Price(['price' => 30.0])));
        $this->assertFalse($product->shouldNotifyOnPrice(new Price(['price' => 27.01])));
        $this->assertTrue($product->shouldNotifyOnPrice(new Price(['price' => 27])));
        $this->assertTrue($product->shouldNotifyOnPrice(new Price(['price' => 5])));
    }

    protected function createOneProductWithUrlAndPrices(string $url = self::DEFAULT_URL, array $prices = [10, 15, 20], array $attrs = []): Product
    {
        return Product::factory()
            ->addUrlWithPrices($url, $prices)
            ->createOne($attrs);
    }
}

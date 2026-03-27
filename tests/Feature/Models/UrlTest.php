<?php

namespace Tests\Feature\Models;

use App\Enums\StockStatus;
use App\Models\Price;
use App\Models\Product;
use App\Models\Store;
use App\Models\Url;
use App\Models\User;
use App\Services\Helpers\CurrencyHelper;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;
use Tests\Traits\ScraperTrait;

class UrlTest extends TestCase
{
    use RefreshDatabase;
    use ScraperTrait;

    const TEST_URL = 'https://example.com/product';

    protected User $user;

    protected Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = Store::factory()->createOne([
            'domains' => [['domain' => parse_url(self::TEST_URL, PHP_URL_HOST)]],
        ]);

        $this->user = User::factory()->create();
    }

    public function test_user_is_required_to_create_url()
    {
        $this->mockScrape(10, 'test');

        $this->expectException(AuthorizationException::class);

        Url::createFromUrl(self::TEST_URL);
    }

    public function test_create_from_url_with_valid_data()
    {
        $this->actingAs($this->user);

        $scrapeData = [
            'title' => 'Example Product',
            'price' => 100,
        ];

        $this->mockScrape($scrapeData['price'], $scrapeData['title']);

        $urlModel = Url::createFromUrl(self::TEST_URL);

        $this->assertInstanceOf(Url::class, $urlModel);
        $this->assertEquals(self::TEST_URL, $urlModel->url);
        $this->assertInstanceOf(Store::class, $urlModel->store);
        $this->assertEquals($scrapeData['title'], $urlModel->product->title);
    }

    public function test_create_from_url_with_invalid_data()
    {
        $this->mockScrape('', '');

        $urlModel = Url::createFromUrl(self::TEST_URL);

        $this->assertFalse($urlModel);
    }

    public function test_update_price_with_valid_data()
    {
        $product = Product::factory()->create();
        $url = Url::factory()->createOne([
            'url' => self::TEST_URL,
            'product_id' => $product->id,
            'store_id' => $this->store->id,
        ]);

        $this->mockScrape('$100', 'foo');

        $priceModel = $url->updatePrice();

        $this->assertInstanceOf(Price::class, $priceModel);
        $this->assertEquals(100.0, $priceModel->price);
        $this->assertEquals(100.0, $priceModel->unit_price);
        $this->assertEquals(1, $priceModel->price_factor);
    }

    public function test_update_price_calculates_unit_price_with_factor()
    {
        $product = Product::factory()->create();
        $url = Url::factory()->createOne([
            'url' => self::TEST_URL,
            'product_id' => $product->id,
            'store_id' => $this->store->id,
            'price_factor' => 6,
        ]);

        $this->mockScrape('$12', 'foo');

        $priceModel = $url->updatePrice();

        $this->assertInstanceOf(Price::class, $priceModel);
        $this->assertEquals(12.0, $priceModel->price);
        $this->assertEquals(2.0, $priceModel->unit_price);
        $this->assertEquals(6, $priceModel->price_factor);
    }

    public function test_update_price_with_default_factor()
    {
        $product = Product::factory()->create();
        $url = Url::factory()->createOne([
            'url' => self::TEST_URL,
            'product_id' => $product->id,
            'store_id' => $this->store->id,
        ]);

        $priceModel = $url->updatePrice(50);

        $this->assertInstanceOf(Price::class, $priceModel);
        $this->assertEquals(50.0, $priceModel->price);
        $this->assertEquals(50.0, $priceModel->unit_price);
        $this->assertEquals(1, $priceModel->price_factor);
    }

    public function test_sync_stored_prices_for_current_factor_recalculates_existing_prices_without_creating_new_rows()
    {
        $product = Product::factory()
            ->addUrlWithPrices(self::TEST_URL, [12, 18, 24], priceFactor: 6)
            ->createOne();

        $url = $product->urls()->firstOrFail();
        $originalLatestPriceId = $url->prices()->latest('id')->value('id');

        $url->update(['price_factor' => 12]);
        $url->syncStoredPricesForCurrentFactor();
        $url->product->refresh()->updatePriceCache();

        $latestPrice = $url->prices()->latest('id')->first();

        $this->assertCount(3, $url->prices);
        $this->assertSame($originalLatestPriceId, $url->prices()->latest('id')->value('id'));
        $this->assertSame(2.0, $latestPrice->unit_price);
        $this->assertSame(12.0, $latestPrice->price_factor);
        $this->assertSame(2.0, $url->product->fresh()->getPriceCache()->first()->getUnitPrice());
    }

    public function test_update_price_with_invalid_data()
    {
        $product = Product::factory()->create();
        $url = Url::factory()->createOne([
            'url' => self::TEST_URL,
            'product_id' => $product->id,
            'store_id' => $this->store->id,
        ]);

        $this->mockScrape('invalid', 'invalid');

        $priceModel = $url->updatePrice();

        $this->assertNull($priceModel);
    }

    public function test_create_from_url_with_unavailable_product_and_no_price()
    {
        $this->actingAs($this->user);

        $this->store->update([
            'scrape_strategy' => array_merge($this->store->scrape_strategy ?? [], [
                'availability' => [
                    'type' => 'selector',
                    'value' => '.availability',
                    'match' => [
                        'out_of_stock' => ['type' => 'match', 'value' => 'OutOfStock'],
                        'default' => 'in_stock',
                    ],
                ],
            ]),
        ]);

        $this->mockScrape('', 'Out of Stock Product', null, 'OutOfStock');

        $urlModel = Url::createFromUrl(self::TEST_URL);

        $this->assertInstanceOf(Url::class, $urlModel);
        $this->assertSame(StockStatus::OutOfStock, $urlModel->availability);
        $this->assertEquals('Out of Stock Product', $urlModel->product->title);
        $this->assertCount(0, $urlModel->prices);
        $this->assertCount(1, $urlModel->product->fresh()->getPriceCache());
    }

    public function test_update_price_unavailable_no_price_does_not_create_price()
    {
        $product = Product::factory()->create();
        $url = Url::factory()->createOne([
            'url' => self::TEST_URL,
            'product_id' => $product->id,
            'store_id' => $this->store->id,
        ]);

        $this->store->update([
            'scrape_strategy' => array_merge($this->store->scrape_strategy ?? [], [
                'availability' => [
                    'type' => 'selector',
                    'value' => '.availability',
                    'match' => [
                        'out_of_stock' => ['type' => 'match', 'value' => 'OutOfStock'],
                        'default' => 'in_stock',
                    ],
                ],
            ]),
        ]);

        $this->mockScrape('', 'foo', null, 'OutOfStock');

        $priceModel = $url->updatePrice();

        $this->assertNull($priceModel);
        $this->assertCount(0, $url->prices);
        $this->assertSame(StockStatus::OutOfStock, $url->fresh()->availability);
    }

    public function test_update_price_unavailable_to_in_stock_creates_price_and_clears_availability()
    {
        $product = Product::factory()->create();
        $url = Url::factory()->createOne([
            'url' => self::TEST_URL,
            'product_id' => $product->id,
            'store_id' => $this->store->id,
            'availability' => StockStatus::OutOfStock,
        ]);

        $this->store->update([
            'scrape_strategy' => array_merge($this->store->scrape_strategy ?? [], [
                'availability' => [
                    'type' => 'selector',
                    'value' => '.availability',
                    'match' => [
                        'out_of_stock' => ['type' => 'match', 'value' => 'OutOfStock'],
                        'default' => 'in_stock',
                    ],
                ],
            ]),
        ]);

        $this->mockScrape('$25', 'foo', null, null);

        $priceModel = $url->updatePrice();

        $this->assertInstanceOf(Price::class, $priceModel);
        $this->assertEquals(25.0, $priceModel->price);
        $this->assertNull($url->fresh()->availability);
    }

    public function test_unavailable_url_does_not_send_notifications_for_new_price()
    {
        Notification::fake();

        $product = Product::factory()->create([
            'user_id' => $this->user->id,
            'notify_price' => 100.0,
        ]);

        $url = Url::factory()->createOne([
            'product_id' => $product->id,
            'store_id' => $this->store->id,
            'availability' => StockStatus::OutOfStock,
        ]);

        Price::factory()->create([
            'url_id' => $url->id,
            'store_id' => $this->store->id,
            'price' => 50.0,
        ]);

        Notification::assertNothingSent();
    }

    public function test_product_name_short_returns_correct_value()
    {
        $product = Product::factory()->create(['title' => 'A long title that is too long, it should be trimmed to a limit so not so long']);
        $url = Url::factory()->create(['product_id' => $product->id]);

        $this->assertEquals('A long title that is...', $url->product_name_short);
    }

    public function test_store_name_returns_correct_value()
    {
        $store = Store::factory()->create(['name' => 'Example Store']);
        $url = Url::factory()->create(['store_id' => $store->id]);

        $this->assertEquals('Example Store', $url->store_name);
    }

    public function test_product_url_returns_correct_value()
    {
        $product = Product::factory()->create();
        $url = Url::factory()->create(['product_id' => $product->id]);

        $this->assertEquals($product->action_urls['view'], $url->product_url);
    }

    public function test_latest_price_formatted_returns_correct_value()
    {
        $url = Url::factory()->create();
        $price = Price::factory()->create(['url_id' => $url->id, 'price' => 100]);

        $this->assertEquals(CurrencyHelper::toString(100), $url->latest_price_formatted);
    }

    public function test_average_price_returns_correct_value()
    {
        $url = Url::factory()->create();
        Price::factory()->create(['url_id' => $url->id, 'price' => 100]);
        Price::factory()->create(['url_id' => $url->id, 'price' => 200]);

        $this->assertEquals(CurrencyHelper::toString(150), $url->average_price);
    }

    public function test_affiliate_code_gets_added_to_url()
    {
        $url = Url::factory()->create(['url' => 'https://example.com/product?foo=bar#baz']);

        config([
            'affiliates.enabled' => true,
            'affiliates.sites' => [
                [
                    'domains' => ['example.com'],
                    'query_params' => ['ref' => '123'],
                ],
            ],
        ]);

        $this->assertEquals('https://example.com/product?foo=bar&ref=123#baz', $url->buy_url);
    }

    public function test_affiliate_urls()
    {
        $urls = [
            'https://www.ebay.com.au/itm/315209278735' => 'https://www.ebay.com.au/itm/315209278735?mkrid=705-53470-19255-0&mkcid=1&campid=5339100273&siteid=15&toolid=10001&mkevt=1',
            'https://www.amazon.com.au/dp/B08P3V5K7Y' => 'https://www.amazon.com.au/dp/B08P3V5K7Y?tag=pricebuddy-22',
            'https://www.amazon.com/dp/B08P3V5K7Y' => 'https://www.amazon.com/dp/B08P3V5K7Y?tag=pricebuddy07-20',
        ];

        foreach ($urls as $original => $new) {
            $urlModel = Url::factory()->create(['url' => $original]);
            $this->assertSame($new, $urlModel->buy_url);
        }
    }

    public function test_should_notify_on_price()
    {
        Price::withoutEvents(function () {
            // Not previously notified.
            $product = $this->createOneProductWithUrlAndPrices(prices: [10, 10, 10]);
            /** @var Url $url */
            $url = $product->urls()->first();
            $url->prices->each(fn (Price $price) => $price->update(['notified' => false]));
            $this->assertTrue($url->shouldNotifyOnPrice($url->prices()->latest()->first()));

            // First price notified already.
            $product = $this->createOneProductWithUrlAndPrices(prices: [10, 10, 10]);
            /** @var Url $url */
            $url = $product->urls()->first();
            $url->prices->each(fn (Price $price) => $price->update(['notified' => false]));
            $url->prices()->oldest()->first()->update(['notified' => true]);
            $this->assertFalse($url->shouldNotifyOnPrice($url->prices()->latest()->first()));

            // Price has changed, should re-notify.
            $product = $this->createOneProductWithUrlAndPrices(prices: [10, 20, 10]);
            /** @var Url $url */
            $url = $product->urls()->first();
            $url->prices->sortBy('created_at')
                ->values()
                ->each(fn (Price $price, int $idx) => $price->update(['notified' => $idx === 0]));
            $this->assertTrue($url->shouldNotifyOnPrice($url->prices()->oldest()->first()));
        });

    }

    protected function createOneProductWithUrlAndPrices(string $url = 'https://example.com', array $prices = [10, 15, 20], array $attrs = []): Product
    {
        return Product::factory()
            ->addUrlWithPrices($url, $prices)
            ->createOne($attrs);
    }
}

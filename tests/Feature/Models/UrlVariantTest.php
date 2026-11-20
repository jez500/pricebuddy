<?php

namespace Tests\Feature\Models;

use App\Enums\StockStatus;
use App\Events\AvailabilityChangedEvent;
use App\Models\Product;
use App\Models\Store;
use App\Models\Url;
use App\Models\User;
use App\Notifications\StockAlertNotification;
use App\Services\AiConfigHealer;
use App\Services\ScrapeUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;
use Tests\Traits\VariantTrait;

class UrlVariantTest extends TestCase
{
    use RefreshDatabase;
    use VariantTrait;

    const string URL = 'https://example.com/trail-shorts';

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    protected function createUrl(?array $variant, ?int $productId = null): Url
    {
        $url = Url::createFromUrl(self::URL, $productId, $this->user->getKey(), false, 1, $variant);

        $this->assertInstanceOf(Url::class, $url);

        return $url;
    }

    public function test_tracked_variant_is_out_of_stock_while_the_page_is_in_stock()
    {
        $this->createVariantStore();
        $this->mockVariantPage();

        $small = $this->createUrl(['values' => ['Small']]);
        $whole = $this->createUrl(null, $small->product_id);

        $this->assertSame(['values' => ['Small']], $small->fresh()->variant);
        $this->assertSame(StockStatus::OutOfStock, $small->fresh()->availability);
        $this->assertNull($whole->fresh()->availability);

        $cache = $small->product->fresh()->getPriceCache()->keyBy(fn ($dto) => $dto->getUrlId());
        $this->assertSame('Small', $cache->get($small->getKey())->getVariant());
        $this->assertTrue($cache->get($small->getKey())->isUnavailable());
        $this->assertNull($cache->get($whole->getKey())->getVariant());
    }

    public function test_tracked_variant_price_replaces_the_page_price()
    {
        $this->createVariantStore();
        $this->mockVariantPage();

        $large = $this->createUrl(['values' => ['large']]);

        $this->assertNull($large->fresh()->availability);
        $this->assertSame(32.5, (float) $large->prices()->first()->price);
    }

    public function test_back_in_stock_alert_fires_when_the_tracked_variant_returns()
    {
        Notification::fake();
        $this->createVariantStore();
        $this->mockVariantPage();

        $small = $this->createUrl(['values' => ['Small']]);
        $small->product->update(['notify_in_stock' => true]);

        // Other sizes being in stock is not enough.
        $small->fresh()->updatePrice();
        Notification::assertNothingSent();

        $this->setSmallInStock(true);
        $small->fresh()->updatePrice();

        $this->assertNull($small->fresh()->availability);
        Notification::assertSentTo($this->user, StockAlertNotification::class);
    }

    public function test_missing_variant_data_fails_closed()
    {
        Event::fake([AvailabilityChangedEvent::class]);
        $this->createVariantStore(withVariants: false);
        $this->mockVariantPage();

        $product = Product::factory()->for($this->user)->addUrlWithPrices(self::URL, [10, 20])->createOne();
        /** @var Url $url */
        $url = $product->urls()->first();
        $url->update(['variant' => ['values' => ['Small']], 'availability' => StockStatus::OutOfStock]);

        $this->mock(AiConfigHealer::class)->shouldNotReceive('heal');

        $this->assertNull($url->fresh()->updatePrice());

        $this->assertSame(2, $url->prices()->count());
        $this->assertSame(StockStatus::OutOfStock, $url->fresh()->availability);
        Event::assertNotDispatched(AvailabilityChangedEvent::class);
    }

    public function test_adding_a_url_fails_when_its_variant_cannot_be_found()
    {
        $this->createVariantStore(withVariants: false);
        $this->mockVariantPage();

        $this->assertFalse(Url::createFromUrl(self::URL, null, $this->user->getKey(), false, 1, ['values' => ['Small']]));
        $this->assertSame(0, Url::count());
    }

    public function test_a_variant_missing_from_the_page_is_out_of_stock()
    {
        $this->createVariantStore();
        $this->mockVariantPage();

        $url = $this->createUrl(['values' => ['XX Large']]);

        $this->assertSame(StockStatus::OutOfStock, $url->fresh()->availability);
        $this->assertSame(0, $url->prices()->count());
    }

    public function test_changing_the_variant_refreshes_stock_without_an_alert()
    {
        $this->createVariantStore();
        $this->mockVariantPage();

        $url = $this->createUrl(['values' => ['Small']]);
        $this->assertSame(StockStatus::OutOfStock, $url->fresh()->availability);

        Event::fake([AvailabilityChangedEvent::class]);

        $this->assertTrue($url->fresh()->changeVariant(['values' => ['X Small'], 'id' => 'TEST100_X-Small']));

        $url->refresh();
        $this->assertSame(['values' => ['X Small'], 'id' => 'TEST100_X-Small'], $url->getVariantFilter()->toArray());
        $this->assertNull($url->availability);
        Event::assertNotDispatched(AvailabilityChangedEvent::class);

        // Clearing the variant tracks the page as a whole again.
        $this->assertTrue($url->changeVariant(['values' => []]));
        $this->assertNull($url->fresh()->variant);
    }

    public function test_respelling_the_variant_keeps_its_stock_so_a_restock_still_alerts()
    {
        $this->createVariantStore();
        $this->mockVariantPage();
        $url = $this->createUrl(['values' => ['Small']]);

        // Small came back in stock, but the next fetch hasn't run yet.
        $this->setSmallInStock(true);
        Event::fake([AvailabilityChangedEvent::class]);

        // Re-saving the same variant with different spelling is not a new variant.
        $this->assertTrue($url->fresh()->changeVariant(['values' => ['small']]));
        $this->assertSame(['values' => ['small']], $url->fresh()->variant);
        $this->assertSame(StockStatus::OutOfStock, $url->fresh()->availability);
        Event::assertNotDispatched(AvailabilityChangedEvent::class);

        // So the next fetch still reports the restock.
        $url->fresh()->updatePrice();
        Event::assertDispatched(AvailabilityChangedEvent::class, fn (AvailabilityChangedEvent $event) => $event->isBackInStock());
    }

    public function test_changing_the_variant_keeps_the_old_one_when_the_page_cannot_be_read()
    {
        $this->createVariantStore();
        $this->mockVariantPage();
        $url = $this->createUrl(['values' => ['Small']]);

        Event::fake([AvailabilityChangedEvent::class]);
        $this->setVariantPageFails(true);

        $this->assertFalse($url->fresh()->changeVariant(['values' => ['X Small']]));

        $this->assertSame(['values' => ['Small']], $url->fresh()->variant);
        $this->assertSame(StockStatus::OutOfStock, $url->fresh()->availability);
        Event::assertNotDispatched(AvailabilityChangedEvent::class);
    }

    public function test_changing_the_variant_keeps_the_old_one_when_the_store_lists_no_variants()
    {
        $store = $this->createVariantStore();
        $this->mockVariantPage();
        $url = $this->createUrl(['values' => ['Small']]);

        $strategy = $store->scrape_strategy->toArray();
        unset($strategy['variants']);
        $store->update(['scrape_strategy' => $strategy]);
        Cache::flush();

        $this->assertFalse($url->fresh()->changeVariant(['values' => ['X Small']]));
        $this->assertSame(['values' => ['Small']], $url->fresh()->variant);
        $this->assertSame(StockStatus::OutOfStock, $url->fresh()->availability);

        // Once the variant is confirmed later, there's no stock change to alert about:
        // the stored stock still belongs to Small.
        $store->update(['scrape_strategy' => $strategy + ['variants' => $this->nextDataVariantStrategy()]]);
        Cache::flush();
        Event::fake([AvailabilityChangedEvent::class]);

        $url->fresh()->updatePrice();
        Event::assertNotDispatched(AvailabilityChangedEvent::class);
    }

    public function test_a_failed_fetch_leaves_the_tracked_variant_stock_alone()
    {
        Notification::fake();
        $this->createVariantStore();
        $this->mockVariantPage();
        $url = $this->createUrl(['values' => ['Small']]);
        $url->product->update(['notify_in_stock' => true]);
        $priceCount = $url->prices()->count();

        $this->setVariantPageFails(true);

        $this->assertNull($url->fresh()->updatePrice());
        $this->assertSame(StockStatus::OutOfStock, $url->fresh()->availability);
        $this->assertSame($priceCount, $url->prices()->count());
        Notification::assertNotSentTo($this->user, StockAlertNotification::class);
    }

    public function test_a_scrape_for_a_replaced_variant_is_discarded()
    {
        $this->createVariantStore();
        $this->mockVariantPage();
        $url = $this->createUrl(['values' => ['Small']]);
        $priceCount = $url->prices()->count();

        // A scheduled update loads the URL and scrapes it for Small...
        $job = $url->fresh();
        $scrape = ScrapeUrl::new(self::URL)->scrape(['variant' => $job->getVariantFilter()]);

        // ...while the user switches it to X Small, which is in stock.
        Url::query()->whereKey($url->getKey())->update(['variant' => json_encode(['values' => ['X Small']]), 'availability' => null]);
        Event::fake([AvailabilityChangedEvent::class]);

        $job->updatePrice(data_get($scrape, 'price'), $scrape);

        $this->assertNull($url->fresh()->availability);
        $this->assertSame($priceCount, $url->prices()->count());
        Event::assertNotDispatched(AvailabilityChangedEvent::class);
    }

    public function test_variant_status_wins_over_the_store_availability_rules()
    {
        // The store reads the heading as availability and matches nothing, so its
        // default status (out of stock) would apply to the page.
        $this->createVariantStore(strategy: ['availability' => [
            'type' => 'selector',
            'value' => 'h1',
            'match' => [
                'pre_order' => ['type' => 'match', 'value' => 'Coming soon'],
                'default' => 'out_of_stock',
            ],
        ]]);
        $this->mockVariantPage();

        $page = ScrapeUrl::new(self::URL)->scrape();
        $variant = ScrapeUrl::new(self::URL)->scrape(['variant' => ['values' => ['X Small']]]);
        $strategy = Store::first()->scrape_strategy->availability;

        $this->assertSame(StockStatus::OutOfStock, ScrapeUrl::resolveStockStatus($page, $strategy));
        $this->assertSame(StockStatus::InStock, ScrapeUrl::resolveStockStatus($variant, $strategy));
        $this->assertSame(['TEST100_X-Small'], collect(data_get($variant, 'variant.matched'))->pluck('id')->all());
    }

    public function test_listing_variants_does_not_change_the_page_result()
    {
        $this->createVariantStore();
        $this->mockVariantPage();

        $scrape = ScrapeUrl::new(self::URL)->scrape(['with_variants' => true]);

        $this->assertSame('30.00', $scrape['price']);
        $this->assertArrayNotHasKey(ScrapeUrl::VARIANT_KEY, $scrape);
        $this->assertSame(
            ['X Small', 'Small', 'Medium', 'Large'],
            collect($scrape[ScrapeUrl::VARIANTS_KEY])->pluck('label')->all(),
        );
    }

    public function test_store_variants_strategy_is_saved_with_the_store()
    {
        $store = $this->createVariantStore();

        $this->assertSame($this->nextDataVariantStrategy(), $store->fresh()->scrape_strategy->variants->toArray());
        $this->assertSame('selector', data_get($store->fresh()->scrape_strategy->toArray(), 'variants.type'));
    }
}

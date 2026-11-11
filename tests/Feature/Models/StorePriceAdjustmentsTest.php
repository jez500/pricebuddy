<?php

namespace Tests\Feature\Models;

use App\Exceptions\PriceRecalculationRunningException;
use App\Jobs\RecalculateStorePrices;
use App\Models\Price;
use App\Models\Product;
use App\Models\Store;
use App\Models\Url;
use App\Models\User;
use App\Notifications\PriceAlertNotification;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * A store's price adjustments turn the page price into the price the user pays. The
 * running example is Amazon.de delivering to Portugal: the page shows German VAT (19%),
 * checkout charges Portugal's 23% and €7.65 shipping, so €324.80 costs €343.37.
 */
class StorePriceAdjustmentsTest extends TestCase
{
    use RefreshDatabase;

    private const string URL = 'https://example.com/dp/B0H41D4KFT';

    private const string SHIPPING_STRATEGY = '#mir-layout-DELIVERY_BLOCK [data-csa-c-delivery-price]|data-csa-c-delivery-price';

    private const array AMAZON_DE = ['tax_included_percent' => 19, 'tax_percent' => 23, 'shipping_cost' => 7.65];

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        RecalculateStorePrices::$batchSize = 1000;
        RecalculateStorePrices::$productBatchSize = 50;
        Store::$priceLockWait = 30;

        $this->user = User::factory()->create();
    }

    protected function makeStore(array $adjustments = [], bool $shippingStrategy = false): Store
    {
        $store = Store::factory()->create([
            'name' => 'Amazon.de',
            'settings' => [
                'scraper_service' => 'http',
                'locale_settings' => ['locale' => 'en_US', 'currency' => 'EUR'],
                'price_adjustments' => $adjustments,
            ],
        ]);

        if ($shippingStrategy) {
            $strategy = $store->scrape_strategy->toArray();
            $strategy['shipping'] = ['type' => 'selector', 'value' => self::SHIPPING_STRATEGY];
            $store->update(['scrape_strategy' => $strategy]);
        }

        return $store;
    }

    protected function makeUrl(Store $store, array $productAttributes = [], float $priceFactor = 1): Url
    {
        $product = Product::factory()->create(array_merge([
            'title' => 'Ryzen 7 5800X3D',
            'user_id' => $this->user->id,
            'notify_price' => null,
            'notify_percent' => null,
        ], $productAttributes));

        return Url::factory()->for($store)->for($product)->create(['url' => self::URL, 'price_factor' => $priceFactor]);
    }

    /**
     * Recalculate a store's saved prices the way the app does, in the background. The test
     * queue runs the whole chain straight away.
     */
    protected function recalculate(Store $store): void
    {
        $this->assertTrue($store->fresh()->queuePriceRecalculation(), 'the run started');
    }

    /**
     * A product page with Amazon's delivery block (markup trimmed from amazon.de and
     * amazon.es, 2026-11-11). Null leaves the delivery block out.
     */
    protected function mockPage(string $price, ?string $delivery): void
    {
        Http::fake(['*' => Http::response($this->pageHtml($price, $delivery))]);
    }

    protected function pageHtml(string $price, ?string $delivery): string
    {
        $block = $delivery === null ? '' : <<<HTML
<div id="mir-layout-DELIVERY_BLOCK"><div id="mir-layout-DELIVERY_BLOCK-slot-PRIMARY_DELIVERY_MESSAGE_LARGE" class="a-spacing-base">
<span data-csa-c-type="element" data-csa-c-content-id="DEXUnifiedCXPDM" data-csa-c-delivery-price="{$delivery}" data-csa-c-delivery-type="delivery" data-csa-c-delivery-time="Thursday, 19 November" data-csa-c-mir-type="DELIVERY"> {$delivery} delivery <span class="a-text-bold">Thursday, 19 November</span> </span>
</div></div>
HTML;

        return <<<HTML
<html><head>
<meta property="og:title" content="AMD Ryzen 7 5800X3D">
<meta property="og:price:amount" content="{$price}">
<meta property="og:image" content="https://example.com/image.jpg">
</head><body>{$block}</body></html>
HTML;
    }

    public function test_saves_the_price_you_pay_and_keeps_the_page_price(): void
    {
        $url = $this->makeUrl($this->makeStore(self::AMAZON_DE), priceFactor: 2);

        $price = $url->updatePrice(324.80)->fresh();

        $this->assertSame(343.37, (float) $price->price);
        $this->assertSame(324.80, (float) $price->scraped_price);
        $this->assertSame(171.685, (float) $price->unit_price);
        $this->assertSame(10.92, $price->adjusted_price->tax);
        $this->assertSame(7.65, $price->adjusted_price->shipping);
        $this->assertNull($price->adjusted_price->pageShipping);
    }

    public function test_a_store_without_adjustments_saves_the_page_price(): void
    {
        $url = $this->makeUrl($this->makeStore());

        $price = $url->updatePrice(324.80)->fresh();

        $this->assertSame(324.80, (float) $price->price);
        $this->assertSame(324.80, (float) $price->scraped_price);
        $this->assertNull($price->price_adjustments);
        $this->assertNull($price->adjusted_price);
    }

    public function test_reads_paid_shipping_from_the_page(): void
    {
        $url = $this->makeUrl($this->makeStore(['tax_included_percent' => 19, 'tax_percent' => 23], shippingStrategy: true));
        $this->mockPage('€324.80', '€7.65');

        $price = $url->updatePrice()->fresh();

        $this->assertSame(343.37, (float) $price->price);
        $this->assertSame(7.65, $price->adjusted_price->shipping);
        $this->assertSame(7.65, $price->adjusted_price->pageShipping);
    }

    public function test_reads_free_shipping_from_the_page(): void
    {
        $url = $this->makeUrl($this->makeStore(['shipping_cost' => 7.65], shippingStrategy: true));
        $this->mockPage('€350.65', 'FREE');

        $price = $url->updatePrice()->fresh();

        $this->assertSame(350.65, (float) $price->price);
        $this->assertSame(0.0, $price->adjusted_price->shipping);
        $this->assertSame(0.0, $price->adjusted_price->pageShipping);
        $this->assertSame(0.0, $price->getPageShipping());
    }

    public function test_uses_the_flat_fee_and_logs_when_the_page_shows_no_shipping(): void
    {
        $url = $this->makeUrl($this->makeStore(['shipping_cost' => 5], shippingStrategy: true));
        $this->mockPage('€100.00', null);

        $price = $url->updatePrice()->fresh();

        $this->assertSame(105.0, (float) $price->price);
        $this->assertNull($price->adjusted_price->pageShipping);
        $this->assertDatabaseHas('log_messages', [
            'message' => 'Shipping not found on the page, used the flat shipping fee',
        ]);
    }

    public function test_alerts_compare_the_price_you_pay_with_the_target(): void
    {
        $url = $this->makeUrl($this->makeStore(self::AMAZON_DE), ['notify_price' => 330]);

        // €324.80 on the page is under the target, but €343.37 to pay is not.
        $url->updatePrice(324.80);
        Notification::assertNothingSent();

        // €310 on the page is €328.07 to pay.
        $this->travel(1)->hours();
        $url->updatePrice(310);

        Notification::assertSentTo($this->user, PriceAlertNotification::class, function (PriceAlertNotification $notification): bool {
            $data = $notification->toArray($this->user);

            return $data['title'] === 'Price drop: Ryzen 7 5800X3D (€328.07)'
                && $data['summary'] === "Amazon.de has had a price drop for Ryzen 7 5800X3D - €328.07\n(page €310.00 + €10.42 tax + €7.65 shipping)"
                && $data['priceBreakdown'] === '(page €310.00 + €10.42 tax + €7.65 shipping)';
        });
    }

    public function test_no_change_alert_when_only_the_settings_changed(): void
    {
        $store = $this->makeStore();
        $url = $this->makeUrl($store, ['notify_on_change' => true]);
        $url->updatePrice(324.80);

        $store->update(['settings' => array_merge($store->settings, ['price_adjustments' => self::AMAZON_DE])]);
        $this->travel(1)->hours();
        $url->refresh()->updatePrice(324.80);

        Notification::assertNothingSent();

        // A new page price is a change.
        $this->travel(1)->hours();
        $url->updatePrice(320);

        Notification::assertSentTo($this->user, PriceAlertNotification::class);
    }

    public function test_a_change_in_page_shipping_is_a_change(): void
    {
        $url = $this->makeUrl($this->makeStore(shippingStrategy: true), ['notify_on_change' => true]);

        Http::fake(['*' => Http::sequence()
            ->push($this->pageHtml('€100.00', 'FREE'))
            ->push($this->pageHtml('€100.00', '€4.99'))]);

        $url->updatePrice();
        $this->travel(1)->hours();
        Cache::flush(); // the scraper caches pages
        $url->updatePrice();

        Notification::assertSentTo($this->user, PriceAlertNotification::class);
    }

    public function test_recalculates_past_prices_from_their_page_price(): void
    {
        $store = $this->makeStore();
        $url = $this->makeUrl($store, priceFactor: 2);
        // Prices saved before price adjustments existed have no page price of their own.
        $old = $url->prices()->create(['price' => 324.80, 'unit_price' => 162.40, 'price_factor' => 2, 'store_id' => $store->id, 'created_at' => now()->subDays(3)]);
        $new = $url->updatePrice(310);

        $old->forceFill(['notified' => true])->saveQuietly();
        $createdAt = $old->fresh()->created_at;

        $store->update(['settings' => array_merge($store->settings, ['price_adjustments' => self::AMAZON_DE])]);
        $this->recalculate($store);

        $this->assertSame(2, Price::query()->count(), 'updated in place');
        $this->assertEquals($createdAt, $old->fresh()->created_at);
        $this->assertTrue($old->fresh()->notified, 'other columns are left alone');
        $this->assertSame(343.37, (float) $old->fresh()->price);
        $this->assertSame(324.80, (float) $old->fresh()->scraped_price);
        $this->assertSame(171.685, (float) $old->fresh()->unit_price);
        $this->assertSame(328.07, (float) $new->fresh()->price);

        $cache = $url->product->fresh()->getPriceCache()->first();
        // Price factor 2: the shown price is per unit, the breakdown is for the pack.
        $this->assertSame('€310.00 + €10.42 tax + €7.65 shipping = €328.07 for 2 units', $cache->getPriceBreakdownText());

        // Clearing the adjustments brings the page prices back.
        $store->update(['settings' => array_merge($store->settings, ['price_adjustments' => []])]);
        $this->recalculate($store);

        $this->assertSame(324.80, (float) $old->fresh()->price);
        $this->assertNull($old->fresh()->price_adjustments);
        $this->assertNull($url->product->fresh()->getPriceCache()->first()->getPriceBreakdownText());
    }

    public function test_recalculates_from_midnight_of_a_date(): void
    {
        $store = $this->makeStore();
        $url = $this->makeUrl($store);
        $before = $url->prices()->create(['price' => 100, 'store_id' => $store->id, 'created_at' => '2026-11-09 23:59:59']);
        $midnight = $url->prices()->create(['price' => 100, 'store_id' => $store->id, 'created_at' => '2026-11-10 00:00:00']);
        $after = $url->prices()->create(['price' => 100, 'store_id' => $store->id, 'created_at' => '2026-11-10 12:00:00']);

        $store->update(['settings' => array_merge($store->settings, ['price_adjustments' => ['tax_percent' => 23]])]);
        $store->fresh()->queuePriceRecalculation(Carbon::parse('2026-11-10'));

        $this->assertSame(100.0, (float) $before->fresh()->price);
        $this->assertNull($before->fresh()->price_adjustments);
        $this->assertSame(123.0, (float) $midnight->fresh()->price);
        $this->assertSame(123.0, (float) $after->fresh()->price);
    }

    public function test_background_recalculation_runs_in_batches_and_refreshes_the_caches(): void
    {
        RecalculateStorePrices::$batchSize = 2;
        $store = $this->makeStore();
        $url = $this->makeUrl($store);
        $prices = collect(range(1, 5))->map(fn (int $day) => $url->prices()->create([
            'price' => 100, 'scraped_price' => 100, 'store_id' => $store->id, 'created_at' => now()->subDays(6 - $day),
        ]));
        $url->product->updatePriceCache();

        $store->update(['settings' => array_merge($store->settings, ['price_adjustments' => ['tax_percent' => 23]])]);
        Queue::fake();
        $store->fresh()->queuePriceRecalculation();

        // Run the chain by hand: each batch queues the next.
        $this->runQueuedChain($store->fresh()->price_recalculation_run);

        $steps = Queue::pushed(RecalculateStorePrices::class)->map(fn (RecalculateStorePrices $job) => $job->step)->all();
        $this->assertSame(['prices', 'prices', 'prices', 'caches'], $steps, 'prices in 2 + 2 + 1, then the product caches');
        $prices->each(fn (Price $price) => $this->assertSame(123.0, (float) $price->fresh()->price));
        $this->assertSame(123.0, (float) $url->product->fresh()->current_price);
        $this->assertTrue(Cache::lock(Store::priceLockKey($store->id), 1)->get(), 'the lock was released');
        $this->assertNull($store->fresh()->price_recalculation_run, 'the run ended');
    }

    public function test_background_recalculation_leaves_prices_saved_after_it_started(): void
    {
        RecalculateStorePrices::$batchSize = 1;
        $store = $this->makeStore(['tax_percent' => 23]);
        $url = $this->makeUrl($store);
        $first = $url->prices()->create(['price' => 100, 'scraped_price' => 100, 'store_id' => $store->id]);
        $second = $url->prices()->create(['price' => 100, 'scraped_price' => 100, 'store_id' => $store->id]);

        Queue::fake();
        $store->queuePriceRecalculation();
        Queue::pushed(RecalculateStorePrices::class)->first()->handle();
        $later = $url->prices()->create(['price' => 100, 'scraped_price' => 100, 'store_id' => $store->id]);

        $this->runQueuedChain($store->fresh()->price_recalculation_run, done: 1);

        $this->assertSame(123.0, (float) $first->fresh()->price);
        $this->assertSame(123.0, (float) $second->fresh()->price);
        $this->assertSame(100.0, (float) $later->fresh()->price);
    }

    public function test_the_cutoff_is_taken_when_the_run_starts_not_when_it_is_queued(): void
    {
        $store = $this->makeStore(['tax_percent' => 23]);
        $url = $this->makeUrl($store);

        Queue::fake();
        $store->queuePriceRecalculation();

        // A scrape that read the old settings saves its price after the run was queued,
        // but before it started.
        $scraped = $url->prices()->create(['price' => 100, 'scraped_price' => 100, 'store_id' => $store->id]);

        $this->runQueuedChain($store->fresh()->price_recalculation_run);

        $this->assertSame(123.0, (float) $scraped->fresh()->price);
    }

    public function test_a_second_start_during_a_run_is_refused(): void
    {
        $store = $this->makeStore(['tax_percent' => 23]);
        $price = $this->makeUrl($store)->prices()->create(['price' => 100, 'scraped_price' => 100, 'store_id' => $store->id]);
        Queue::fake();

        $this->assertTrue($store->queuePriceRecalculation());
        $runId = $store->price_recalculation_run;
        $this->assertFalse($store->fresh()->queuePriceRecalculation());

        $this->assertSame($runId, $store->fresh()->price_recalculation_run, 'the run is not replaced');
        Queue::assertPushed(RecalculateStorePrices::class, 1);

        $this->runQueuedChain($runId);
        $this->assertSame(123.0, (float) $price->fresh()->price);
        $this->assertTrue($store->fresh()->queuePriceRecalculation(), 'free again once it ends');
    }

    public function test_price_adjustments_cannot_change_while_a_run_is_under_way(): void
    {
        $this->freezeSecond();
        $store = $this->makeStore(['tax_percent' => 23]);
        $price = $this->makeUrl($store)->prices()->create(['price' => 100, 'scraped_price' => 100, 'store_id' => $store->id]);
        Queue::fake();
        $store->queuePriceRecalculation();
        $this->travel(4)->seconds();

        try {
            $store->fresh()->update(['settings' => array_merge($store->settings, ['price_adjustments' => ['tax_percent' => 10]])]);
            $this->fail('expected the save to be refused');
        } catch (PriceRecalculationRunningException $e) {
            $this->assertSame('Still recalculating (started 4 s ago). Try again in a moment.', $e->getMessage());
        }

        $this->assertSame(23.0, $store->fresh()->price_adjustments->taxPercent);
        $this->runQueuedChain($store->price_recalculation_run);
        $this->assertSame(123.0, (float) $price->fresh()->price, 'the run used the settings it started with');

        $store->fresh()->update(['settings' => array_merge($store->settings, ['price_adjustments' => ['tax_percent' => 10]])]);
        $this->assertSame(10.0, $store->fresh()->price_adjustments->taxPercent, 'saved once the run ended');
    }

    public function test_a_store_loaded_before_the_run_started_cannot_change_them_either(): void
    {
        $store = $this->makeStore(['tax_percent' => 23], shippingStrategy: true);
        $loadedEarlier = Store::find($store->id);
        Queue::fake();
        $store->queuePriceRecalculation();

        try {
            $loadedEarlier->update(['scrape_strategy' => collect($loadedEarlier->scrape_strategy->toArray())->except('shipping')->all()]);
            $this->fail('expected the save to be refused');
        } catch (PriceRecalculationRunningException) {
        }

        $this->assertTrue($store->fresh()->hasShippingStrategy(), 'a shipping strategy is a price adjustment too');
    }

    public function test_other_store_changes_leave_a_run_going(): void
    {
        $store = $this->makeStore(['tax_percent' => 23]);
        $price = $this->makeUrl($store)->prices()->create(['price' => 100, 'scraped_price' => 100, 'store_id' => $store->id]);
        Queue::fake();
        $store->queuePriceRecalculation();

        $store->fresh()->update(['name' => 'Renamed']);
        $this->runQueuedChain($store->fresh()->price_recalculation_run);

        $this->assertSame(123.0, (float) $price->fresh()->price);
    }

    public function test_the_estimate_counts_the_prices_and_gives_a_range(): void
    {
        $store = $this->makeStore(['tax_percent' => 23]);
        $url = $this->makeUrl($store);
        $this->assertSame('Recalculating 0 prices, usually a few seconds.', $store->priceRecalculationEstimate());

        $url->prices()->create(['price' => 100, 'store_id' => $store->id, 'created_at' => '2026-11-01 00:00:00']);
        $this->assertSame('Recalculating 1 price, usually a few seconds.', $store->priceRecalculationEstimate());

        DB::table('prices')->insert(array_fill(0, 6233, [
            'price' => 100, 'url_id' => $url->id, 'store_id' => $store->id, 'created_at' => '2026-10-01 00:00:00',
        ]));
        $this->assertSame('Recalculating about 6,200 prices, usually a few seconds.', $store->priceRecalculationEstimate());
        $this->assertSame('Recalculating 1 price, usually a few seconds.', $store->priceRecalculationEstimate(Carbon::parse('2026-11-01')));

        // Each product's caches take longer than its prices.
        $listings = fn (int $count) => DB::table('urls')->insert(array_map(fn () => [
            'url' => self::URL, 'store_id' => $store->id,
            'product_id' => DB::table('products')->insertGetId(['title' => 'Listed', 'user_id' => $this->user->id]),
        ], range(1, $count)));
        $listings(39);
        $this->assertSame('Recalculating about 6,200 prices, usually under a minute.', $store->priceRecalculationEstimate());
        $listings(150);
        $this->assertSame('Recalculating about 6,200 prices, usually a few minutes.', $store->priceRecalculationEstimate());
    }

    public function test_a_failed_run_ends(): void
    {
        $store = $this->makeStore(['tax_percent' => 23]);
        Queue::fake();
        $store->queuePriceRecalculation();
        $this->assertTrue($store->fresh()->isRecalculatingPrices());

        Queue::pushed(RecalculateStorePrices::class)->first()->failed(new \RuntimeException('boom'));

        $this->assertFalse($store->fresh()->isRecalculatingPrices());
    }

    public function test_a_new_price_uses_the_stores_settings_as_they_are_when_it_is_saved(): void
    {
        $store = $this->makeStore(['tax_percent' => 23]);
        $url = $this->makeUrl($store);
        $this->assertSame(23.0, $url->store->price_adjustments->taxPercent, 'loaded before the change');

        // The settings change while the scrape is under way.
        $changed = Store::find($store->id);
        $changed->update(['settings' => array_merge($changed->settings, ['price_adjustments' => ['tax_percent' => 10]])]);

        $this->assertSame(110.0, (float) $url->updatePrice(100)->price);
    }

    public function test_a_listing_recalculation_waits_for_the_stores_lock(): void
    {
        $store = $this->makeStore(['tax_percent' => 23]);
        $url = $this->makeUrl($store);
        $price = $url->prices()->create(['price' => 100, 'scraped_price' => 100, 'store_id' => $store->id]);

        $batch = Cache::lock(Store::priceLockKey($store->id), 120);
        $batch->get();
        Store::$priceLockWait = 0;

        try {
            $url->recalculatePrices();
            $this->fail('expected the listing to wait for the lock');
        } catch (LockTimeoutException) {
        }

        $this->assertSame(100.0, (float) $price->fresh()->price);

        $batch->release();
        $url->recalculatePrices();
        $this->assertSame(123.0, (float) $price->fresh()->price);
    }

    public function test_product_caches_resume_from_their_cursor(): void
    {
        $store = $this->makeStore(['tax_percent' => 23]);
        $first = $this->makeUrl($store);
        $second = $this->makeUrl($store);
        $first->prices()->create(['price' => 123, 'scraped_price' => 100, 'store_id' => $store->id]);
        $second->prices()->create(['price' => 123, 'scraped_price' => 100, 'store_id' => $store->id]);
        Product::query()->update(['current_price' => 1]);

        // A retried cache batch carries the cursor of the products already done.
        Store::query()->whereKey($store->id)->update(['price_recalculation_run' => 'run']);
        (new RecalculateStorePrices($store->id, 'run', null, 0, RecalculateStorePrices::STEP_CACHES, $first->product_id))->handle();

        $this->assertSame(1.0, (float) $first->product->fresh()->current_price, 'done before the failure, not redone');
        $this->assertSame(123.0, (float) $second->product->fresh()->current_price);
    }

    public function test_a_failed_cache_batch_is_retried_from_its_cursor(): void
    {
        RecalculateStorePrices::$productBatchSize = 1;
        $store = $this->makeStore(['tax_percent' => 23]);
        $urls = collect(range(1, 3))->map(function () use ($store) {
            $url = $this->makeUrl($store);
            $url->prices()->create(['price' => 100, 'scraped_price' => 100, 'store_id' => $store->id]);

            return $url;
        });

        // From here on, count each product's cache refreshes, and fail the second
        // product's first one.
        $refreshes = [];
        $failed = false;
        Product::updating(function (Product $product) use (&$refreshes, &$failed, $urls): void {
            if ($product->isDirty('price_cache')) {
                if ($product->id === $urls[1]->product_id && ! $failed) {
                    $failed = true;
                    throw new \RuntimeException('database went away');
                }
                $refreshes[$product->id] = ($refreshes[$product->id] ?? 0) + 1;
            }
        });

        Queue::fake();
        $store->queuePriceRecalculation();
        $jobs = fn () => Queue::pushed(RecalculateStorePrices::class)->values();

        $jobs()[0]->handle(); // prices
        $jobs()[1]->handle(); // caches: the first product
        $failing = $jobs()[2];
        $this->assertSame(RecalculateStorePrices::STEP_CACHES, $failing->step);

        try {
            $failing->handle();
            $this->fail('expected the batch to fail');
        } catch (\RuntimeException) {
        }

        $this->assertTrue(Cache::lock(Store::priceLockKey($store->id), 1)->get(), 'a failed batch frees the lock');
        Cache::lock(Store::priceLockKey($store->id))->forceRelease();

        // The queue retries the same job, which carries its cursor; then the chain goes on.
        // Bounded, so a cursor that does not move fails here instead of looping forever.
        $failing->handle();
        for ($i = 3; $i < 20 && ($job = $jobs()->get($i)); $i++) {
            $job->handle();
        }
        $this->assertLessThan(20, $i, 'the chain ends');

        $this->assertSame(1, $refreshes[$urls[0]->product_id], 'the product before the failure is not redone');
        $this->assertSame(1, $refreshes[$urls[1]->product_id]);
        $this->assertSame(1, $refreshes[$urls[2]->product_id]);
    }

    public function test_products_whose_url_moved_to_another_store_are_refreshed(): void
    {
        $old = $this->makeStore();
        $url = $this->makeUrl($old);
        $price = $url->prices()->create(['price' => 100, 'scraped_price' => 100, 'store_id' => $old->id]);
        $url->update(['store_id' => $this->makeStore()->id]);
        $url->product->updatePriceCache();

        $old->update(['settings' => array_merge($old->settings, ['price_adjustments' => ['tax_percent' => 23]])]);
        $old->fresh()->queuePriceRecalculation();

        $this->assertSame(123.0, (float) $price->fresh()->price);
        $this->assertSame(123.0, (float) $url->product->fresh()->current_price);
    }

    /**
     * Run the queued recalculation batches of a run, in order, the way a worker would,
     * skipping the first $done that the test already ran itself.
     */
    protected function runQueuedChain(string $runId, int $done = 0): void
    {
        for ($i = 0; $job = Queue::pushed(RecalculateStorePrices::class)->filter(fn ($job) => $job->runId === $runId)->skip($done)->first(); $i++, $done++) {
            $this->assertLessThan(100, $i, 'the chain ends');
            $job->handle();
        }
    }

    public function test_a_run_with_no_progress_is_reported_and_then_taken_as_abandoned(): void
    {
        $this->freezeSecond();
        RecalculateStorePrices::$batchSize = 1;
        $store = $this->makeStore(['tax_percent' => 23]);
        $url = $this->makeUrl($store);
        $url->prices()->create(['price' => 100, 'scraped_price' => 100, 'store_id' => $store->id]);
        $url->prices()->create(['price' => 100, 'scraped_price' => 100, 'store_id' => $store->id]);
        Queue::fake();
        $store->queuePriceRecalculation();

        // A batch that waited 25 minutes behind other jobs is progress.
        $this->travel(25)->minutes();
        Queue::pushed(RecalculateStorePrices::class)->first()->handle();
        $this->travel(12)->minutes();

        $this->assertTrue($store->fresh()->isRecalculatingPrices());
        $this->assertSame('Still recalculating, no progress for 12 min. Is the queue worker running?', $store->priceRecalculationStatus());

        $this->travel(19)->minutes();

        $this->assertFalse($store->fresh()->isRecalculatingPrices(), '31 minutes with no progress');
        $this->assertTrue($store->fresh()->queuePriceRecalculation(), 'a new run can start');
    }

    public function test_a_save_ends_an_abandoned_run_and_its_late_batches_do_nothing(): void
    {
        $store = $this->makeStore(['tax_percent' => 23]);
        $price = $this->makeUrl($store)->prices()->create(['price' => 100, 'scraped_price' => 100, 'store_id' => $store->id]);
        Queue::fake();
        $store->queuePriceRecalculation();
        $abandoned = Queue::pushed(RecalculateStorePrices::class)->first();

        // The queue worker stops. After 30 minutes, the settings can be saved again, here
        // with "New prices only".
        $this->travel(31)->minutes();
        $store->fresh()->update(['settings' => array_merge($store->settings, ['price_adjustments' => ['tax_percent' => 10]])]);
        $this->assertNull($store->fresh()->price_recalculation_run, 'the save ended the run');

        // The worker comes back and picks up the abandoned run's batch.
        $abandoned->handle();

        $this->assertSame(100.0, (float) $price->fresh()->price, 'past prices are left as they were');
        Queue::assertPushed(RecalculateStorePrices::class, 1);
    }

    public function test_the_check_holds_the_store_until_the_save_is_written(): void
    {
        $store = $this->makeStore(['tax_percent' => 23]);
        $outside = DB::transactionLevel();
        $levels = [];
        DB::listen(function ($query) use (&$levels): void {
            if (str_ends_with($query->sql, 'for update') || str_starts_with($query->sql, 'update `stores`')) {
                $levels[] = DB::transactionLevel();
            }
        });

        $store->update(['settings' => array_merge($store->settings, ['price_adjustments' => ['tax_percent' => 10]])]);

        $this->assertSame([$outside + 1, $outside + 1], $levels, 'the locked read and the save, in one transaction');
    }

    public function test_a_batch_waits_while_another_holds_the_stores_lock(): void
    {
        $store = $this->makeStore(['tax_percent' => 23]);
        $price = $this->makeUrl($store)->prices()->create(['price' => 100, 'scraped_price' => 100, 'store_id' => $store->id]);
        Queue::fake();
        $store->queuePriceRecalculation();
        $job = Queue::pushed(RecalculateStorePrices::class)->first();

        $other = Cache::lock(Store::priceLockKey($store->id), 120);
        $this->assertTrue($other->get());

        $job->handle();

        $this->assertSame(100.0, (float) $price->fresh()->price, 'nothing done while another batch holds the lock');
        $this->assertFalse(Cache::lock(Store::priceLockKey($store->id), 120)->get(), 'and the other owner still holds it');

        $other->release();
        $job->handle();

        $this->assertSame(123.0, (float) $price->fresh()->price);
    }

    public function test_recalculation_counts_page_shipping_only_while_the_store_reads_it(): void
    {
        $store = $this->makeStore(['shipping_cost' => 5], shippingStrategy: true);
        $url = $this->makeUrl($store);
        $this->mockPage('€100.00', '€7.65');
        $price = $url->updatePrice();

        $store->update(['settings' => array_merge($store->settings, ['price_adjustments' => ['shipping_cost' => 5, 'tax_percent' => 10]])]);
        $this->recalculate($store);

        // (100 × 1.10) + 7.65 read from the page.
        $this->assertSame(117.65, (float) $price->fresh()->price);

        // Without the shipping strategy, the flat fee applies, but the page's amount is kept.
        $strategy = $store->scrape_strategy->toArray();
        $shippingStrategy = $strategy['shipping'];
        unset($strategy['shipping']);
        $store->update(['scrape_strategy' => $strategy]);
        $this->recalculate($store);

        $this->assertSame(115.0, (float) $price->fresh()->price);
        $this->assertSame(7.65, $price->fresh()->adjusted_price->pageShipping);

        // Restoring the strategy counts it again.
        $store->update(['scrape_strategy' => $strategy + ['shipping' => $shippingStrategy]]);
        $this->recalculate($store);

        $this->assertSame(117.65, (float) $price->fresh()->price);
    }

    public function test_page_shipping_is_kept_when_the_store_drops_all_adjustments(): void
    {
        $store = $this->makeStore(shippingStrategy: true);
        $url = $this->makeUrl($store);
        $this->mockPage('€100.00', '€7.65');
        $price = $url->updatePrice();
        $this->assertSame(107.65, (float) $price->fresh()->price);

        $store->update(['scrape_strategy' => collect($store->scrape_strategy->toArray())->except('shipping')->all()]);
        $this->recalculate($store);

        // The page price, nothing to mark, but the amount is still there.
        $this->assertSame(100.0, (float) $price->fresh()->price);
        $this->assertFalse($price->fresh()->adjusted_price->isAdjusted());
        $this->assertSame(7.65, $price->fresh()->getPageShipping());
    }

    public function test_recalculation_only_touches_the_stores_prices(): void
    {
        $store = $this->makeStore(['tax_percent' => 23]);
        $other = Url::factory()->for(Product::factory()->create(['user_id' => $this->user->id]))->create();
        $otherPrice = $other->prices()->create(['price' => 100, 'store_id' => $other->store_id]);

        $this->recalculate($store);

        $this->assertSame(100.0, (float) $otherPrice->fresh()->price);
    }

    public function test_a_listing_that_ignores_shipping_keeps_the_page_shipping(): void
    {
        $store = $this->makeStore(['tax_included_percent' => 19, 'tax_percent' => 23], shippingStrategy: true);
        $url = $this->makeUrl($store);
        $url->update(['ignore_shipping' => true]);
        $this->mockPage('€324.80', '€7.65');

        $price = $url->updatePrice()->fresh();

        $this->assertSame(335.72, (float) $price->price);
        $this->assertSame(7.65, $price->getPageShipping());
        $this->assertSame(7.65, $price->adjusted_price->uncountedShipping);
    }

    public function test_a_listing_that_ignores_shipping_logs_nothing_when_the_page_shows_none(): void
    {
        $url = $this->makeUrl($this->makeStore(['shipping_cost' => 5], shippingStrategy: true));
        $url->update(['ignore_shipping' => true]);
        $this->mockPage('€100.00', null);

        $this->assertSame(100.0, (float) $url->updatePrice()->price);
        $this->assertDatabaseMissing('log_messages', [
            'message' => 'Shipping not found on the page, used the flat shipping fee',
        ]);
    }

    public function test_listing_recalculation_counts_ignored_shipping_again(): void
    {
        $url = $this->makeUrl($this->makeStore(['tax_included_percent' => 19, 'tax_percent' => 23], shippingStrategy: true));
        $this->mockPage('€324.80', '€7.65');
        $price = $url->updatePrice();

        $url->update(['ignore_shipping' => true]);
        $this->assertSame(1, $url->recalculatePrices());
        $this->assertSame(335.72, (float) $price->fresh()->price);
        $this->assertSame('€324.80 + €10.92 tax · shipping not counted', $url->product->fresh()->getPriceCache()->first()->getPriceBreakdownText());

        // The page's €7.65 was kept, so turning it off restores it.
        $url->update(['ignore_shipping' => false]);
        $url->recalculatePrices();
        $this->assertSame(343.37, (float) $price->fresh()->price);
    }

    public function test_listing_recalculation_from_a_date_leaves_older_prices(): void
    {
        $store = $this->makeStore(['shipping_cost' => 5]);
        $url = $this->makeUrl($store);
        $old = $url->prices()->create(['price' => 105, 'scraped_price' => 100, 'store_id' => $store->id, 'created_at' => '2026-11-09 23:59:59']);
        $new = $url->prices()->create(['price' => 105, 'scraped_price' => 100, 'store_id' => $store->id, 'created_at' => '2026-11-10 00:00:00']);

        $url->update(['ignore_shipping' => true]);
        $url->recalculatePrices(Carbon::parse('2026-11-10'));

        $this->assertSame(105.0, (float) $old->fresh()->price);
        $this->assertSame(100.0, (float) $new->fresh()->price);
    }

    public function test_listing_recalculation_uses_each_prices_own_store(): void
    {
        $old = $this->makeStore(['shipping_cost' => 5]);
        $url = $this->makeUrl($old);
        $before = $url->updatePrice(100);

        // The URL was changed to a page on another store: earlier prices stay the old store's.
        $new = $this->makeStore(['tax_percent' => 23]);
        $url->update(['store_id' => $new->id]);
        $after = $url->fresh()->updatePrice(100);

        $url->fresh()->recalculatePrices();

        $this->assertSame(105.0, (float) $before->fresh()->price);
        $this->assertSame(123.0, (float) $after->fresh()->price);
    }

    public function test_store_recalculation_respects_listings_that_ignore_shipping(): void
    {
        $store = $this->makeStore();
        $counted = $this->makeUrl($store);
        $ignored = Url::factory()->for($store)->for($counted->product)->create(['ignore_shipping' => true]);
        $a = $counted->updatePrice(100);
        $b = $ignored->updatePrice(100);

        $store->update(['settings' => array_merge($store->settings, ['price_adjustments' => ['shipping_cost' => 5]])]);
        $this->recalculate($store);

        $this->assertSame(105.0, (float) $a->fresh()->price);
        $this->assertSame(100.0, (float) $b->fresh()->price);
    }

    public function test_starting_to_ignore_shipping_is_not_a_price_change(): void
    {
        $url = $this->makeUrl($this->makeStore(self::AMAZON_DE), ['notify_on_change' => true]);
        $url->updatePrice(324.80);

        // "New prices only": the next check is the first price without shipping.
        $url->update(['ignore_shipping' => true]);
        $this->travel(1)->hours();
        $this->assertSame(335.72, (float) $url->updatePrice(324.80)->price);

        Notification::assertNothingSent();
    }

    public function test_free_shipping_threshold_applies_to_new_prices(): void
    {
        $url = $this->makeUrl($this->makeStore(self::AMAZON_DE + ['free_shipping_from' => 335]));

        $this->assertSame(335.72, (float) $url->updatePrice(324.80)->price);
        $this->travel(1)->hours();
        $this->assertSame(338.41, (float) $url->updatePrice(320)->price, '€330.76 after tax is under the threshold');
    }

    public function test_migration_copies_existing_prices_to_the_page_price(): void
    {
        $url = $this->makeUrl($this->makeStore(['tax_percent' => 23]));
        // A price saved before the migration, and one adjusted after it.
        DB::table('prices')->insert([
            'price' => 42.5, 'url_id' => $url->id, 'store_id' => $url->store_id,
            'created_at' => Carbon::now(), 'updated_at' => Carbon::now(),
        ]);
        $adjusted = $url->updatePrice(100);

        $migration = require database_path('migrations/2026_11_11_000000_add_price_adjustments_to_prices_table.php');
        $migration->backfill();

        $this->assertSame(42.5, (float) Price::query()->whereKeyNot($adjusted->id)->firstOrFail()->scraped_price);
        $this->assertSame(100.0, (float) $adjusted->fresh()->scraped_price);
        $this->assertSame(123.0, (float) $adjusted->fresh()->price);
    }
}

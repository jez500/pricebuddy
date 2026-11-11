<?php

namespace Tests\Feature\Models;

use App\Jobs\RecalculateStorePrices;
use App\Models\Price;
use App\Models\Product;
use App\Models\Store;
use App\Models\Url;
use App\Models\User;
use App\Notifications\PriceAlertNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
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

        $store->update(['settings' => array_merge($store->settings, ['price_adjustments' => self::AMAZON_DE])]);

        $this->assertSame(2, $store->fresh()->recalculatePrices());

        $this->assertSame(343.37, (float) $old->fresh()->price);
        $this->assertSame(324.80, (float) $old->fresh()->scraped_price);
        $this->assertSame(171.685, (float) $old->fresh()->unit_price);
        $this->assertSame(328.07, (float) $new->fresh()->price);

        $cache = $url->product->fresh()->getPriceCache()->first();
        // Price factor 2: the shown price is per unit, the breakdown is for the pack.
        $this->assertSame('€310.00 + €10.42 tax + €7.65 shipping = €328.07 for 2 units', $cache->getPriceBreakdownText());

        // Clearing the adjustments brings the page prices back.
        $store->update(['settings' => array_merge($store->settings, ['price_adjustments' => []])]);
        $store->fresh()->recalculatePrices();

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
        (new RecalculateStorePrices($store->id, '2026-11-10'))->handle();

        $this->assertSame(100.0, (float) $before->fresh()->price);
        $this->assertNull($before->fresh()->price_adjustments);
        $this->assertSame(123.0, (float) $midnight->fresh()->price);
        $this->assertSame(123.0, (float) $after->fresh()->price);
    }

    public function test_recalculation_keeps_page_shipping_only_while_the_store_reads_it(): void
    {
        $store = $this->makeStore(['shipping_cost' => 5], shippingStrategy: true);
        $url = $this->makeUrl($store);
        $this->mockPage('€100.00', '€7.65');
        $price = $url->updatePrice();

        $store->update(['settings' => array_merge($store->settings, ['price_adjustments' => ['shipping_cost' => 5, 'tax_percent' => 10]])]);
        $store->fresh()->recalculatePrices();

        // (100 × 1.10) + 7.65 read from the page.
        $this->assertSame(117.65, (float) $price->fresh()->price);

        // Without the shipping strategy, the flat fee applies.
        $strategy = $store->scrape_strategy->toArray();
        unset($strategy['shipping']);
        $store->update(['scrape_strategy' => $strategy]);
        $store->fresh()->recalculatePrices();

        $this->assertSame(115.0, (float) $price->fresh()->price);
        $this->assertNull($price->fresh()->adjusted_price->pageShipping);
    }

    public function test_recalculation_only_touches_the_stores_prices(): void
    {
        $store = $this->makeStore(['tax_percent' => 23]);
        $other = Url::factory()->for(Product::factory()->create(['user_id' => $this->user->id]))->create();
        $otherPrice = $other->prices()->create(['price' => 100, 'store_id' => $other->store_id]);

        $store->recalculatePrices();

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
        $store->fresh()->recalculatePrices();

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

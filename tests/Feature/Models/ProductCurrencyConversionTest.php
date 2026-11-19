<?php

namespace Tests\Feature\Models;

use App\Dto\PriceCacheDto;
use App\Enums\ExchangeRateProvider;
use App\Models\Price;
use App\Models\Product;
use App\Models\Store;
use App\Models\Url;
use App\Models\User;
use App\Notifications\PriceAlertNotification;
use App\Services\CurrencyConverter;
use App\Services\Insights\ProductInsights;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;
use Tests\Traits\CurrencyConversionTrait;

class ProductCurrencyConversionTest extends TestCase
{
    use CurrencyConversionTrait;
    use RefreshDatabase;

    protected User $user;

    protected Product $product;

    protected Store $usStore;

    protected Store $caStore;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->travelTo(Carbon::parse('2026-11-19 12:00:00'));

        $this->user = User::factory()->create();
        $this->product = Product::factory()->create([
            'title' => 'Faucet',
            'user_id' => $this->user->id,
            'notify_price' => null,
            'notify_percent' => null,
        ]);
        $this->usStore = $this->storeInCurrency('USD', 'Frank Webb');
        $this->caStore = $this->storeInCurrency('CAD', 'Amazon.ca');
    }

    protected function addPrice(Store $store, float $price, string $date): Price
    {
        $url = Url::query()->firstOrCreate(
            ['product_id' => $this->product->id, 'store_id' => $store->id],
            ['url' => 'https://'.str($store->name)->slug().'.example.com/faucet'],
        );

        $model = $url->prices()->create(['price' => $price, 'unit_price' => $price, 'store_id' => $store->id]);
        $model->forceFill(['created_at' => $date])->saveQuietly();

        return $model;
    }

    protected function cacheFor(Store $store): PriceCacheDto
    {
        $this->product->refresh()->updatePriceCache();

        return $this->product->getPriceCache()->first(fn (PriceCacheDto $cache): bool => $cache->getStoreId() === $store->id);
    }

    public function test_price_cache_is_unchanged_when_conversion_is_off(): void
    {
        $this->addExchangeRate('2026-11-18', 1.40);
        $this->addPrice($this->usStore, 100, '2026-11-18 10:00:00');

        $this->product->refresh()->updatePriceCache();
        $cache = $this->product->price_cache[0];

        $this->assertSame(100, (int) $cache['price']);
        $this->assertSame('USD', $cache['currency']);
        $this->assertArrayNotHasKey('original_currency', $cache);
        $this->assertFalse(PriceCacheDto::fromArray($cache)->isConverted());
    }

    public function test_price_cache_converts_foreign_prices_and_keeps_the_original(): void
    {
        $this->enableCurrencyConversion();
        $this->addExchangeRate('2026-11-17', 1.40);
        $this->addExchangeRate('2026-11-18', 1.50);
        $this->addPrice($this->usStore, 100, '2026-11-17 10:00:00');
        $this->addPrice($this->usStore, 110, '2026-11-18 10:00:00');

        $cache = $this->cacheFor($this->usStore);

        $this->assertTrue($cache->isConverted());
        $this->assertSame(165.0, $cache->getPrice());
        $this->assertSame(165.0, $cache->getUnitPrice());
        $this->assertSame('CA$165.00', $cache->getUnitPriceFormatted());
        $this->assertEquals(['2026-11-17' => 140.0, '2026-11-18' => 165.0], $cache->getHistory()->all());
        $this->assertSame('$110.00', $cache->getOriginalUnitPriceFormatted());
        $this->assertSame('USD', $cache->getOriginalCurrency());
        $this->assertSame(1.5, $cache->getExchangeRate());
        $this->assertSame('Converted from USD at 1 USD = 1.5 CAD', $cache->getConversionDescription());
    }

    public function test_prices_already_in_the_default_currency_are_not_marked_converted(): void
    {
        $this->enableCurrencyConversion();
        $this->addExchangeRate('2026-11-18', 1.50);
        $this->addPrice($this->caStore, 150, '2026-11-18 10:00:00');

        $cache = $this->cacheFor($this->caStore);

        $this->assertFalse($cache->isConverted());
        $this->assertSame('CA$150.00', $cache->getPriceFormatted());
    }

    public function test_the_cheapest_store_is_chosen_after_conversion(): void
    {
        $this->enableCurrencyConversion();
        $this->addExchangeRate('2026-11-18', 1.50);
        // 100 USD is 150 CAD, dearer than 140 CAD, though 100 < 140.
        $this->addPrice($this->usStore, 100, '2026-11-18 10:00:00');
        $this->addPrice($this->caStore, 140, '2026-11-18 11:00:00');

        $this->product->refresh()->updatePriceCache();

        $this->assertSame('Amazon.ca', $this->product->getPriceCache()->first()->getStoreName());
        $this->assertSame(140.0, (float) $this->product->current_price);
        $this->assertEquals(150, $this->product->getPriceCacheAggregate('max'));
    }

    public function test_turning_conversion_off_restores_the_original_prices(): void
    {
        $this->enableCurrencyConversion();
        $this->addExchangeRate('2026-11-18', 1.50);
        $this->addPrice($this->usStore, 100, '2026-11-18 10:00:00');
        $this->product->refresh()->updatePriceCache();
        $this->assertSame(150.0, (float) $this->product->current_price);

        $this->enableCurrencyConversion(enabled: false);
        $this->product->refresh()->updatePriceCache();

        $this->assertSame(100.0, (float) $this->product->current_price);
        $this->assertSame('USD', $this->product->price_cache[0]['currency']);
        $this->assertSame(100.0, (float) Price::query()->sole()->price);
    }

    public function test_a_currency_without_rates_is_left_in_its_own_currency(): void
    {
        $this->enableCurrencyConversion();
        $this->addPrice($this->usStore, 100, '2026-11-18 10:00:00');

        $cache = $this->cacheFor($this->usStore);

        $this->assertFalse($cache->isConverted());
        $this->assertSame('$100.00', $cache->getPriceFormatted());
    }

    public function test_insights_use_converted_prices(): void
    {
        $this->enableCurrencyConversion();
        $this->addExchangeRate('2026-11-18', 1.50);
        $this->addPrice($this->usStore, 100, '2026-11-18 10:00:00');
        $this->addPrice($this->caStore, 140, '2026-11-18 11:00:00');

        $insights = ProductInsights::build($this->product->refresh());

        $this->assertSame(140.0, $insights->bestPrice);
        $this->assertSame('Amazon.ca', $insights->bestStore);
    }

    public function test_unit_price_history_is_converted(): void
    {
        $this->enableCurrencyConversion();
        $this->addExchangeRate('2026-11-18', 1.50);
        $this->addPrice($this->usStore, 100, '2026-11-18 10:00:00');

        $history = $this->product->refresh()->getPriceHistory('unit_price')->first();

        $this->assertSame(150.0, $history->last());
        $this->assertSame(100.0, (float) $this->product->getPriceHistory('unit_price', convert: false)->first()->last());
    }

    public function test_a_missing_unit_price_converts_the_price(): void
    {
        $this->enableCurrencyConversion();
        $this->addExchangeRate('2026-11-18', 1.50);
        $price = $this->addPrice($this->usStore, 100, '2026-11-18 10:00:00');
        $price->forceFill(['unit_price' => null])->saveQuietly();

        $history = $this->product->refresh()->getPriceHistory('unit_price')->first();

        $this->assertSame(150.0, $history->last());
        $this->assertSame(150.0, CurrencyConverter::new()->convertPrice($price->refresh(), 'unit_price'));
    }

    public function test_target_price_alert_compares_the_converted_price(): void
    {
        $this->enableCurrencyConversion();
        $this->addExchangeRate('2026-11-18', 1.50);
        $this->product->update(['notify_price' => 140]);

        // 100 USD = 150 CAD, above the 140 CAD target.
        $price = $this->addPrice($this->usStore, 100, '2026-11-18 10:00:00');
        $this->assertFalse($this->product->shouldNotifyOnPrice($price));

        // 90 USD = 135 CAD, below the target.
        $price = $this->addPrice($this->usStore, 90, '2026-11-18 12:00:00');
        $this->assertTrue($this->product->shouldNotifyOnPrice($price));
    }

    public function test_percent_alert_compares_converted_prices(): void
    {
        $this->enableCurrencyConversion();
        $this->addExchangeRate('2026-11-17', 1.40);
        $this->addExchangeRate('2026-11-18', 1.50);
        $this->product->update(['notify_percent' => 10]);

        // First price 200 CAD, so the alert is at 180 CAD or less.
        $this->addPrice($this->caStore, 200, '2026-11-17 09:00:00');

        // 125 USD = 187.50 CAD: not 10% lower, though 125 < 180.
        $price = $this->addPrice($this->usStore, 125, '2026-11-18 10:00:00');
        $this->assertFalse($this->product->shouldNotifyOnPrice($price));

        // 119 USD = 178.50 CAD.
        $price = $this->addPrice($this->usStore, 119, '2026-11-18 11:00:00');
        $this->assertTrue($this->product->shouldNotifyOnPrice($price));
    }

    public function test_price_alert_shows_the_converted_and_original_price(): void
    {
        $this->enableCurrencyConversion();
        $this->addExchangeRate('2026-11-19', 1.50);
        $this->product->update(['notify_price' => 200]);
        $url = Url::factory()->for($this->usStore)->for($this->product)->create();

        $url->prices()->create(['price' => 100, 'store_id' => $this->usStore->id]);

        Notification::assertSentTo($this->user, PriceAlertNotification::class, function (PriceAlertNotification $notification): bool {
            $data = $notification->toArray($this->user);

            return $data['title'] === 'Price drop: Faucet (CA$150.00 ($100.00))';
        });
    }

    public function test_manual_rates_convert_every_price_with_the_same_rate(): void
    {
        $this->enableCurrencyConversion(ExchangeRateProvider::Manual, [['currency' => 'USD', 'rate' => 1.25]]);
        $this->addPrice($this->usStore, 100, '2026-10-01 10:00:00');
        $this->addPrice($this->usStore, 80, '2026-11-18 10:00:00');

        $cache = $this->cacheFor($this->usStore);

        $this->assertEquals(['2026-10-01' => 125.0, '2026-11-18' => 100.0], $cache->getHistory()->all());
    }

    public function test_history_of_a_url_that_moved_store_uses_each_prices_own_currency(): void
    {
        $this->enableCurrencyConversion();
        $this->addExchangeRate('2026-11-10', 1.50);
        $this->addExchangeRate('2026-11-10', 1.60, base: 'EUR');
        $euStore = $this->storeInCurrency('EUR', 'Euro store');

        $this->addPrice($this->usStore, 100, '2026-11-17 10:00:00');
        $url = Url::query()->sole();
        $url->update(['store_id' => $euStore->id]);
        $url->prices()->create(['price' => 100, 'store_id' => $euStore->id])
            ->forceFill(['created_at' => '2026-11-18 10:00:00'])->saveQuietly();

        $history = $this->product->refresh()->getPriceHistory()->sole();

        $this->assertEquals(['2026-11-17' => 150.0, '2026-11-18' => 160.0], $history->all());
    }

    public function test_price_change_alert_converts_the_previous_price_at_its_own_rate(): void
    {
        $this->enableCurrencyConversion();
        $this->addExchangeRate('2026-11-18', 1.50);
        $this->addExchangeRate('2026-11-19', 1.60);
        $this->product->update(['notify_on_change' => true]);
        $url = Url::factory()->for($this->usStore)->for($this->product)->create();

        $this->travelTo(Carbon::parse('2026-11-18 12:00:00'));
        $url->prices()->create(['price' => 100, 'store_id' => $this->usStore->id]);
        $this->travelTo(Carbon::parse('2026-11-19 12:00:00'));
        $url->prices()->create(['price' => 90, 'store_id' => $this->usStore->id]);

        Notification::assertSentTo($this->user, PriceAlertNotification::class, function (PriceAlertNotification $notification): bool {
            $summary = $notification->toArray($this->user)['summary'];

            return str_contains($summary, 'CA$144.00 ($90.00) (was CA$150.00 ($100.00))');
        });
    }

    public function test_lowest_price_in_days_counts_every_price_of_a_day(): void
    {
        $this->enableCurrencyConversion();
        // 120 yesterday morning and 80 yesterday evening: 100 today is still lower than 120.
        $this->addPrice($this->caStore, 120, '2026-11-18 09:00:00');
        $this->addPrice($this->caStore, 80, '2026-11-18 18:00:00');
        $this->addPrice($this->caStore, 100, '2026-11-19 10:00:00');
        $this->product->refresh()->updatePriceCache();

        $this->assertSame([$this->product->id], Product::query()->lowestPriceInDays(7)->pluck('id')->all());
    }

    public function test_lowest_price_in_days_ignores_the_day_added_to_a_single_price(): void
    {
        $this->enableCurrencyConversion();
        $this->addExchangeRate('2026-11-18', 1.60);
        $this->addExchangeRate('2026-11-19', 1.50);
        // Only one price, today. The chart repeats it yesterday, at yesterday's higher rate.
        $this->addPrice($this->usStore, 100, '2026-11-19 10:00:00');
        $this->product->refresh()->updatePriceCache();

        $this->assertSame([], Product::query()->lowestPriceInDays(7)->pluck('id')->all());
    }

    public function test_lowest_price_in_days_uses_converted_prices(): void
    {
        $this->enableCurrencyConversion();
        $this->addExchangeRate('2026-11-10', 1.50);
        // 100 USD (150 CAD) three days ago, now 140 CAD at Amazon.ca: a new low.
        $this->addPrice($this->usStore, 100, '2026-11-16 10:00:00');
        $this->addPrice($this->caStore, 140, '2026-11-19 10:00:00');
        $this->product->refresh()->updatePriceCache();

        $other = Product::factory()->create(['user_id' => $this->user->id]);
        $url = Url::factory()->for($this->caStore)->for($other)->create();
        $url->prices()->create(['price' => 120, 'store_id' => $this->caStore->id])
            ->forceFill(['created_at' => '2026-11-16 10:00:00'])->saveQuietly();
        $url->prices()->create(['price' => 130, 'store_id' => $this->caStore->id]);
        $other->refresh()->updatePriceCache();

        $this->assertSame([$this->product->id], Product::query()->lowestPriceInDays(7)->pluck('id')->all());
    }
}

<?php

namespace Tests\Feature\Services;

use App\Enums\ExchangeRateProvider;
use App\Models\ExchangeRate;
use App\Models\Price;
use App\Models\Product;
use App\Models\Url;
use App\Services\CurrencyConverter;
use App\Services\ExchangeRates\FrankfurterRateSource;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Tests\Traits\CurrencyConversionTrait;

class CurrencyConverterTest extends TestCase
{
    use CurrencyConversionTrait;
    use RefreshDatabase;

    protected CurrencyConverter $converter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->converter = CurrencyConverter::new();
    }

    public function test_it_is_disabled_by_default(): void
    {
        $this->assertFalse($this->converter->isEnabled());
        $this->assertSame(ExchangeRateProvider::Frankfurter, $this->converter->provider());
        $this->assertFalse($this->converter->shouldConvert('USD'));
    }

    public function test_the_default_currency_converts_at_one(): void
    {
        $this->enableCurrencyConversion();

        $this->assertSame(1.0, $this->converter->rate('CAD'));
        $this->assertFalse($this->converter->shouldConvert('CAD'));
    }

    public function test_it_uses_the_rate_for_the_date_of_the_price(): void
    {
        $this->enableCurrencyConversion();
        $this->addExchangeRate('2026-11-10', 1.40);
        $this->addExchangeRate('2026-11-11', 1.42);
        $this->addExchangeRate('2026-11-13', 1.45);

        $this->assertSame(1.40, $this->converter->rate('USD', '2026-11-10'));
        $this->assertSame(1.42, $this->converter->rate('usd', '2026-11-11 18:30:00'));
        $this->assertSame(142.0, $this->converter->convert(100, 'USD', '2026-11-11'));
    }

    public function test_a_day_without_a_rate_uses_the_closest_earlier_rate(): void
    {
        $this->enableCurrencyConversion();
        $this->addExchangeRate('2026-11-13', 1.45);
        $this->addExchangeRate('2026-11-16', 1.47);

        // Saturday and Sunday have no ECB rate.
        $this->assertSame(1.45, $this->converter->rate('USD', '2026-11-14'));
        $this->assertSame(1.45, $this->converter->rate('USD', '2026-11-15'));
        // After the newest rate, the newest rate is used.
        $this->assertSame(1.47, $this->converter->rate('USD', '2026-12-01'));
    }

    public function test_a_price_older_than_every_rate_uses_the_oldest_rate(): void
    {
        $this->enableCurrencyConversion();
        $this->addExchangeRate('2026-11-13', 1.45);
        $this->addExchangeRate('2026-11-16', 1.47);

        $this->assertSame(1.45, $this->converter->rate('USD', '2026-01-01'));
    }

    public function test_a_currency_without_rates_is_not_converted(): void
    {
        $this->enableCurrencyConversion();

        $this->assertNull($this->converter->rate('EUR'));
        $this->assertNull($this->converter->convert(10, 'EUR'));
        $this->assertFalse($this->converter->shouldConvert('EUR'));
    }

    public function test_rates_from_another_provider_are_not_used(): void
    {
        $this->enableCurrencyConversion(ExchangeRateProvider::Yahoo);
        $this->addExchangeRate('2026-11-13', 1.45, provider: ExchangeRateProvider::Frankfurter);

        $this->assertNull($this->converter->rate('USD', '2026-11-13'));
    }

    public function test_manual_rates_apply_to_every_date(): void
    {
        $this->enableCurrencyConversion(ExchangeRateProvider::Manual, [
            ['currency' => 'USD', 'rate' => '1.5'],
            ['currency' => 'EUR', 'rate' => 0],
        ]);

        $this->assertSame(1.5, $this->converter->rate('USD', '2020-01-01'));
        $this->assertSame(150.0, $this->converter->convert(100, 'USD', now()));
        // A zero rate is ignored rather than turning every price into 0.
        $this->assertNull($this->converter->rate('EUR'));
    }

    public function test_convert_history_uses_each_days_rate(): void
    {
        $this->enableCurrencyConversion();
        $this->addExchangeRate('2026-11-10', 1.40);
        $this->addExchangeRate('2026-11-11', 1.50);

        $history = $this->converter->convertHistory(collect(['2026-11-10' => 100, '2026-11-11' => 100]), 'USD');

        $this->assertSame(['2026-11-10' => 140.0, '2026-11-11' => 150.0], $history->all());
    }

    public function test_convert_history_is_unchanged_when_disabled(): void
    {
        $this->addExchangeRate('2026-11-10', 1.40);

        $history = collect(['2026-11-10' => 100]);

        $this->assertSame($history, $this->converter->convertHistory($history, 'USD'));
    }

    public function test_convert_price_uses_the_store_currency_and_price_date(): void
    {
        $this->enableCurrencyConversion();
        $this->addExchangeRate('2026-11-10', 1.40);
        $this->addExchangeRate('2026-11-11', 1.50);

        $price = new Price([
            'price' => 100,
            'store_id' => $this->storeInCurrency('USD')->getKey(),
        ]);
        $price->created_at = Carbon::parse('2026-11-10 12:00:00');

        $this->assertSame(140.0, $this->converter->convertPrice($price));
        // A price that is not tied to a store is already in the default currency.
        $this->assertSame(100.0, $this->converter->convertPrice(new Price(['price' => 100])));
    }

    public function test_format_with_original_shows_both_currencies(): void
    {
        $this->enableCurrencyConversion();
        $this->addExchangeRate('2026-11-10', 1.4258);

        $this->assertSame('CA$142.58 ($100.00)', $this->converter->formatWithOriginal(100, 'USD', 'en_US', '2026-11-10'));
        $this->assertSame('CA$100.00', $this->converter->formatWithOriginal(100, 'CAD', 'en_US'));
    }

    public function test_format_with_original_is_unchanged_when_disabled(): void
    {
        $this->assertSame('$100.00', $this->converter->formatWithOriginal(100, 'USD', 'en_US'));
    }

    public function test_needed_currencies_are_store_currencies_in_use_other_than_the_default(): void
    {
        $this->enableCurrencyConversion();
        $product = Product::factory()->create();

        Url::factory()->for($this->storeInCurrency('USD'))->for($product)->create();
        Url::factory()->for($this->storeInCurrency('EUR'))->for($product)->create();
        Url::factory()->for($this->storeInCurrency('CAD'))->for($product)->create();
        // A store without URLs needs no rates.
        $this->storeInCurrency('GBP');

        $this->assertSame(['EUR', 'USD'], $this->converter->neededCurrencies());
    }

    public function test_sync_rates_fetches_back_to_the_oldest_price_and_stores_them(): void
    {
        $this->travelTo(Carbon::parse('2026-11-19 17:00:00'));
        $this->enableCurrencyConversion();
        $url = Url::factory()->for($this->storeInCurrency('USD'))->create();
        $price = $url->prices()->create(['price' => 10, 'store_id' => $url->store_id]);
        $price->forceFill(['created_at' => '2026-11-12 08:00:00'])->saveQuietly();

        Http::fake([
            FrankfurterRateSource::BASE_URL.'/*' => Http::response([
                'base' => 'USD',
                'rates' => ['2026-11-12' => ['CAD' => 1.42], '2026-11-13' => ['CAD' => 1.43]],
            ]),
        ]);

        $results = $this->converter->syncRates();

        $this->assertSame(['USD' => ['added' => 2, 'error' => null]], $results);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/2026-11-12..2026-11-19')
            && $request['base'] === 'USD'
            && $request['symbols'] === 'CAD');
        $this->assertSame(1.43, $this->converter->rate('USD', '2026-11-19'));
        $this->assertSame(2, ExchangeRate::query()->count());
    }

    public function test_sync_rates_only_fetches_from_the_last_stored_rate(): void
    {
        $this->travelTo(Carbon::parse('2026-11-19 17:00:00'));
        $this->enableCurrencyConversion();
        $url = Url::factory()->for($this->storeInCurrency('USD'))->create();
        $price = $url->prices()->create(['price' => 10, 'store_id' => $url->store_id]);
        $price->forceFill(['created_at' => '2026-11-12 08:00:00'])->saveQuietly();
        $this->addExchangeRate('2026-11-12', 1.42);
        $this->addExchangeRate('2026-11-17', 1.44);

        Http::fake([
            FrankfurterRateSource::BASE_URL.'/*' => Http::response([
                'rates' => ['2026-11-17' => ['CAD' => 1.441], '2026-11-18' => ['CAD' => 1.45]],
            ]),
        ]);

        $results = $this->converter->syncRates();

        // The last stored day is fetched again (and updated), only the 18th is new.
        $this->assertSame(1, $results['USD']['added']);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/2026-11-17..2026-11-19'));
        $this->assertSame(1.441, $this->converter->rate('USD', '2026-11-17'));
        $this->assertSame(3, ExchangeRate::query()->count());
    }

    public function test_sync_rates_reports_a_currency_the_provider_does_not_know(): void
    {
        $this->enableCurrencyConversion();
        Url::factory()->for($this->storeInCurrency('XOF'))->create();

        Http::fake([FrankfurterRateSource::BASE_URL.'/*' => Http::response(['message' => 'not found'], 404)]);

        $results = $this->converter->syncRates();

        $this->assertSame(0, $results['XOF']['added']);
        $this->assertStringContainsString('does not publish rates for XOF/CAD', (string) $results['XOF']['error']);
    }

    public function test_sync_rates_does_nothing_for_manual_rates_or_when_disabled(): void
    {
        Http::fake();
        Url::factory()->for($this->storeInCurrency('USD'))->create();

        $this->assertSame([], $this->converter->syncRates());

        $this->enableCurrencyConversion(ExchangeRateProvider::Manual);
        $this->assertSame([], $this->converter->syncRates());

        Http::assertNothingSent();
    }

    public function test_status_lists_the_rate_in_use_per_currency(): void
    {
        $this->enableCurrencyConversion();
        Url::factory()->for($this->storeInCurrency('USD'))->create();
        Url::factory()->for($this->storeInCurrency('EUR'))->create();
        $this->addExchangeRate('2026-11-12', 1.42);
        $this->addExchangeRate('2026-11-13', 1.43);

        $this->assertSame([
            ['currency' => 'EUR', 'rate' => null, 'date' => null, 'days' => 0],
            ['currency' => 'USD', 'rate' => 1.43, 'date' => '2026-11-13', 'days' => 2],
        ], $this->converter->status());
    }
}

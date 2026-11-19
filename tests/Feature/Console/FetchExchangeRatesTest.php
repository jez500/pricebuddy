<?php

namespace Tests\Feature\Console;

use App\Console\Commands\FetchExchangeRates;
use App\Enums\ExchangeRateProvider;
use App\Models\ExchangeRate;
use App\Models\Product;
use App\Models\Url;
use App\Services\ExchangeRates\FrankfurterRateSource;
use App\Services\Helpers\ScheduleHelper;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Tests\Traits\CurrencyConversionTrait;

class FetchExchangeRatesTest extends TestCase
{
    use CurrencyConversionTrait;
    use RefreshDatabase;

    public function test_it_does_nothing_while_conversion_is_off(): void
    {
        Http::fake();

        $this->artisan(FetchExchangeRates::COMMAND)
            ->expectsOutputToContain('Currency conversion is disabled')
            ->assertExitCode(0);

        Http::assertNothingSent();
    }

    public function test_it_does_nothing_with_manual_rates(): void
    {
        Http::fake();
        $this->enableCurrencyConversion(ExchangeRateProvider::Manual);

        $this->artisan(FetchExchangeRates::COMMAND)
            ->expectsOutputToContain('manual rates')
            ->assertExitCode(0);

        Http::assertNothingSent();
    }

    public function test_it_fetches_rates_and_rebuilds_price_caches(): void
    {
        $this->enableCurrencyConversion();
        $url = Url::factory()->for($this->storeInCurrency('USD'))->for(Product::factory())->create();
        $url->prices()->create(['price' => 100, 'store_id' => $url->store_id]);
        $this->assertSame(100.0, (float) $url->product->refresh()->current_price);

        Http::fake([
            FrankfurterRateSource::BASE_URL.'/*' => Http::response(['rates' => [now()->toDateString() => ['CAD' => 1.5]]]),
        ]);

        $this->artisan(FetchExchangeRates::COMMAND)->assertExitCode(0);

        $this->assertSame(1, ExchangeRate::query()->count());
        $this->assertSame(150.0, (float) $url->product->refresh()->current_price);
    }

    public function test_it_rebuilds_price_caches_when_only_an_existing_rate_changed(): void
    {
        $this->enableCurrencyConversion();
        $url = Url::factory()->for($this->storeInCurrency('USD'))->for(Product::factory())->create();
        $this->addExchangeRate(now()->toDateString(), 1.4);
        $url->prices()->create(['price' => 100, 'store_id' => $url->store_id]);
        $this->assertSame(140.0, (float) $url->product->refresh()->current_price);

        Http::fake([
            FrankfurterRateSource::BASE_URL.'/*' => Http::response(['rates' => [now()->toDateString() => ['CAD' => 1.5]]]),
        ]);

        $this->artisan(FetchExchangeRates::COMMAND)->assertExitCode(0);

        $this->assertSame(1, ExchangeRate::query()->count());
        $this->assertSame(150.0, (float) $url->product->refresh()->current_price);
    }

    public function test_it_fails_when_every_currency_fails(): void
    {
        $this->enableCurrencyConversion();
        Url::factory()->for($this->storeInCurrency('USD'))->create();
        Http::fake([FrankfurterRateSource::BASE_URL.'/*' => Http::response('', 503)]);

        $this->artisan(FetchExchangeRates::COMMAND)->assertExitCode(1);
    }

    public function test_it_is_scheduled_daily(): void
    {
        $schedule = new Schedule;
        ScheduleHelper::registerSchedule($schedule);

        /** @var ?Event $event */
        $event = collect($schedule->events())
            ->first(fn (Event $event): bool => str_contains((string) $event->command, FetchExchangeRates::COMMAND));

        $this->assertNotNull($event);
        $this->assertSame('0 17 * * *', $event->expression);
    }
}

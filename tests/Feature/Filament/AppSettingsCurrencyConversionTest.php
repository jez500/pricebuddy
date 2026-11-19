<?php

namespace Tests\Feature\Filament;

use App\Enums\ExchangeRateProvider;
use App\Filament\Pages\AppSettingsPage;
use App\Jobs\RegeneratePriceCache;
use App\Jobs\UpdateCurrencyConversionJob;
use App\Models\ExchangeRate;
use App\Models\Url;
use App\Models\User;
use App\Services\CurrencyConverter;
use App\Services\ExchangeRates\FrankfurterRateSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;
use Tests\Traits\CurrencyConversionTrait;

class AppSettingsCurrencyConversionTest extends TestCase
{
    use CurrencyConversionTrait;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->admin()->create());
        Queue::fake();
    }

    public function test_the_currency_tab_renders_with_conversion_off(): void
    {
        Livewire::test(AppSettingsPage::class)
            ->assertSee('Currency conversion')
            ->assertFormSet(['currency_conversion.enabled' => false])
            ->assertFormFieldIsHidden('currency_conversion.provider');
    }

    public function test_enabling_conversion_queues_a_rebuild(): void
    {
        Livewire::test(AppSettingsPage::class)
            ->fillForm([
                'currency_conversion.enabled' => true,
                'currency_conversion.provider' => ExchangeRateProvider::Yahoo->value,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue(CurrencyConverter::new()->isEnabled());
        $this->assertSame(ExchangeRateProvider::Yahoo, CurrencyConverter::new()->provider());
        Queue::assertPushed(UpdateCurrencyConversionJob::class);
    }

    public function test_disabling_conversion_queues_a_rebuild(): void
    {
        $this->enableCurrencyConversion();

        Livewire::test(AppSettingsPage::class)
            ->fillForm(['currency_conversion.enabled' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertFalse(CurrencyConverter::new()->isEnabled());
        Queue::assertPushed(UpdateCurrencyConversionJob::class);
    }

    public function test_saving_unrelated_settings_does_not_queue_a_rebuild(): void
    {
        $this->enableCurrencyConversion();

        Livewire::test(AppSettingsPage::class)
            ->fillForm(['log_retention_days' => 90])
            ->call('save')
            ->assertHasNoFormErrors();

        Queue::assertNotPushed(UpdateCurrencyConversionJob::class);
    }

    public function test_changing_the_default_currency_queues_a_rebuild_when_converting(): void
    {
        $this->enableCurrencyConversion();

        Livewire::test(AppSettingsPage::class)
            ->fillForm(['default_locale_settings.currency' => 'EUR'])
            ->call('save')
            ->assertHasNoFormErrors();

        Queue::assertPushed(UpdateCurrencyConversionJob::class);
    }

    public function test_manual_rates_are_saved(): void
    {
        $this->enableCurrencyConversion();

        Livewire::test(AppSettingsPage::class)
            ->fillForm([
                'currency_conversion.provider' => ExchangeRateProvider::Manual->value,
                'currency_conversion.manual_rates' => [
                    ['currency' => 'USD', 'rate' => '1.42'],
                ],
            ])
            ->assertFormFieldIsVisible('currency_conversion.manual_rates')
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(['USD' => 1.42], CurrencyConverter::new()->manualRates());
    }

    public function test_manual_rates_stop_applying_when_the_default_currency_changes(): void
    {
        $this->enableCurrencyConversion();

        Livewire::test(AppSettingsPage::class)
            ->fillForm([
                'currency_conversion.provider' => ExchangeRateProvider::Manual->value,
                'currency_conversion.manual_rates' => [
                    ['currency' => 'USD', 'rate' => '1.42'],
                    ['currency' => 'GBP', 'rate' => '1.85'],
                ],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(['GBP' => 1.85, 'USD' => 1.42], CurrencyConverter::new()->manualRates());

        // The rates are CAD per unit. They must not be used as EUR per unit.
        Livewire::test(AppSettingsPage::class)
            ->fillForm(['default_locale_settings.currency' => 'EUR'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(['GBP' => 'CAD', 'USD' => 'CAD'], CurrencyConverter::new()->staleManualRates());
        $this->assertSame([], CurrencyConverter::new()->manualRates());

        // Entering only the USD rate again must not bring back the unchanged GBP rate.
        Livewire::test(AppSettingsPage::class)
            ->assertSee('The USD rate was entered for CAD. Enter it again in EUR.')
            ->assertSee('The GBP rate was entered for CAD. Enter it again in EUR.')
            // Replace the saved rows rather than adding to them.
            ->set('data.currency_conversion.manual_rates', [
                'usd' => ['currency' => 'USD', 'rate' => '0.93'],
                'gbp' => ['currency' => 'GBP', 'rate' => '1.85'],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(['USD' => 0.93], CurrencyConverter::new()->manualRates());
        $this->assertSame(['GBP' => 'CAD'], CurrencyConverter::new()->staleManualRates());

        Livewire::test(AppSettingsPage::class)
            ->set('data.currency_conversion.manual_rates', [
                'usd' => ['currency' => 'USD', 'rate' => '0.93'],
                'gbp' => ['currency' => 'GBP', 'rate' => '1.17'],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(['GBP' => 1.17, 'USD' => 0.93], CurrencyConverter::new()->manualRates());
        $this->assertSame([], CurrencyConverter::new()->staleManualRates());
    }

    public function test_a_manual_rate_must_be_positive(): void
    {
        $this->enableCurrencyConversion();

        $component = Livewire::test(AppSettingsPage::class)
            ->fillForm([
                'currency_conversion.provider' => ExchangeRateProvider::Manual->value,
                'currency_conversion.manual_rates' => [
                    ['currency' => 'USD', 'rate' => '0'],
                ],
            ])
            ->call('save');

        $this->assertNotEmpty(collect($component->errors()->keys())
            ->filter(fn (string $key): bool => str_ends_with($key, '.rate')));
        $this->assertSame([], CurrencyConverter::new()->manualRates());
    }

    public function test_the_rates_in_use_are_listed(): void
    {
        $this->enableCurrencyConversion();
        Url::factory()->for($this->storeInCurrency('USD'))->create();
        $this->addExchangeRate('2026-11-13', 1.4258);

        Livewire::test(AppSettingsPage::class)
            ->assertSee('1 USD = 1.4258 CAD')
            ->assertSee('2026-11-13');
    }

    public function test_fetch_rates_now_stores_rates_and_rebuilds_caches(): void
    {
        $this->enableCurrencyConversion();
        Url::factory()->for($this->storeInCurrency('USD'))->create();
        Http::fake([
            FrankfurterRateSource::BASE_URL.'/*' => Http::response(['rates' => [now()->toDateString() => ['CAD' => 1.43]]]),
        ]);

        Livewire::test(AppSettingsPage::class)
            ->call('fetchExchangeRates')
            ->assertNotified('Exchange rates updated');

        $this->assertSame(1, ExchangeRate::query()->count());
        Queue::assertPushed(RegeneratePriceCache::class);
    }
}

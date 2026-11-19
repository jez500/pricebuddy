<?php

namespace Tests\Traits;

use App\Enums\ExchangeRateProvider;
use App\Models\ExchangeRate;
use App\Models\Store;
use App\Services\CurrencyConverter;
use App\Services\Helpers\SettingsHelper;

trait CurrencyConversionTrait
{
    /**
     * Use CAD as the default currency and turn conversion on.
     *
     * @param  array<int, array{currency: string, rate: float|string}>  $manualRates
     */
    protected function enableCurrencyConversion(
        ExchangeRateProvider $provider = ExchangeRateProvider::Frankfurter,
        array $manualRates = [],
        string $currency = 'CAD',
        bool $enabled = true,
    ): void {
        SettingsHelper::setSetting('default_locale_settings', ['locale' => 'en_US', 'currency' => $currency]);
        SettingsHelper::setSetting(CurrencyConverter::SETTINGS_KEY, [
            'enabled' => $enabled,
            'provider' => $provider->value,
            'manual_rates' => $manualRates,
        ]);

        CurrencyConverter::new()->flush();
    }

    protected function storeInCurrency(string $currency, string $name = 'Store', string $locale = 'en_US'): Store
    {
        return Store::factory()->create([
            'name' => $name,
            'settings' => ['locale_settings' => ['locale' => $locale, 'currency' => $currency]],
        ]);
    }

    protected function addExchangeRate(string $date, float $rate, string $base = 'USD', string $quote = 'CAD', ExchangeRateProvider $provider = ExchangeRateProvider::Frankfurter): ExchangeRate
    {
        $exchangeRate = ExchangeRate::create([
            'source' => $provider->value,
            'base_currency' => $base,
            'quote_currency' => $quote,
            'date' => $date,
            'rate' => $rate,
        ]);

        CurrencyConverter::new()->flush();

        return $exchangeRate;
    }
}

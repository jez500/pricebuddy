<?php

namespace Tests\Unit\Services;

use App\Services\Helpers\CurrencyHelper;
use App\Services\Helpers\SettingsHelper;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class CurrencyHelperTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate');
        SettingsHelper::setSetting('default_locale_settings', ['locale' => 'en', 'currency' => 'USD']);
    }

    public function test_get_locale_returns_default_locale()
    {
        $this->assertEquals('en', CurrencyHelper::getLocale());
    }

    public function test_get_locale_returns_configured_locale()
    {
        SettingsHelper::setSetting('default_locale_settings.locale', 'fr_FR');
        $this->assertEquals('fr_FR', CurrencyHelper::getLocale());
    }

    public function test_get_all_currencies()
    {
        foreach (CurrencyHelper::getAllCurrencies() as $currency) {
            $this->assertArrayHasKey('country_territory', $currency);
            $this->assertArrayHasKey('currency', $currency);
            $this->assertArrayHasKey('iso', $currency);
            $this->assertArrayHasKey('locale', $currency);
            $this->assertArrayHasKey('separation', $currency);
            $this->assertArrayHasKey('position', $currency);
        }

        SettingsHelper::setSetting('default_locale_settings.currency', 'AUD');
        $this->assertSame('AUD', CurrencyHelper::getCurrency());
    }

    public function test_get_currency_iso()
    {
        SettingsHelper::setSetting('default_locale_settings.currency', 'AUD');
        $this->assertSame('AUD', CurrencyHelper::getCurrency());
    }

    public function test_get_symbol_returns_correct_symbol()
    {
        SettingsHelper::setSetting('default_locale_settings.currency', 'USD');
        $this->assertEquals('$', CurrencyHelper::getSymbol());
        SettingsHelper::setSetting('default_locale_settings.currency', 'EUR');
        $this->assertEquals('€', CurrencyHelper::getSymbol());
    }

    public function test_get_symbol_handles_different_locale()
    {
        $this->assertEquals('€', CurrencyHelper::getSymbol('EUR'));
    }

    public function test_to_float_converts_float_value()
    {
        $this->assertEquals(10.5, CurrencyHelper::toFloat(10.5));
    }

    public function test_to_float_converts_int_value()
    {
        $this->assertEquals(10.0, CurrencyHelper::toFloat(10));
    }

    public function test_dollar_converts_to_float()
    {
        $assertions = [
            ['val' => '45,319.90 $', 'expected' => 45319.90, 'locale' => 'en_US'],
            ['val' => '45.319,90 $', 'expected' => 45319.90, 'locale' => 'fr_FR'],
            ['val' => '$ 45.389,90 ', 'expected' => 45389.90, 'locale' => 'fr_FR'],
            ['val' => '$ 45389.90 ', 'expected' => 45389.90, 'locale' => 'en'],
            ['val' => '45389.90 $', 'expected' => 45389.90, 'locale' => 'en_US'],
            ['val' => '$450,389.90 ', 'expected' => 450389.90, 'locale' => 'en_AU', 'iso' => 'AUD'],
            ['val' => '$1.95 ', 'expected' => 1.95, 'locale' => 'en'],
            ['val' => 195.43, 'expected' => 195.43, 'locale' => 'en_US'],
            ['val' => 198, 'expected' => 198.00, 'locale' => 'en_US'],
            ['val' => 'invalid', 'expected' => 0.0, 'locale' => 'en_US'],
        ];

        $this->assertCurrencyToFloat($assertions, 'USD');
    }

    public function test_euro_converts_to_float()
    {
        $assertions = [
            ['val' => '45.319,90 €', 'expected' => 45319.90, 'locale' => 'fr_FR'],
            ['val' => '€ 45.389,90 ', 'expected' => 45389.90, 'locale' => 'fr_FR'],
            ['val' => 'EUR 45.319,97c', 'expected' => 45319.97, 'locale' => 'fr_FR'],
            ['val' => 'EUR 45.319,90 €', 'expected' => 45319.90, 'locale' => 'fr_FR'],
            ['val' => '45.319,€90', 'expected' => 45319.90, 'locale' => 'fr_FR'],
            ['val' => '€45.319,91', 'expected' => 45319.91, 'locale' => 'it_VA'],
            ['val' => '45.519,90', 'expected' => 45519.90, 'locale' => 'de_AT'],
            ['val' => 'invalid', 'expected' => 0.0, 'locale' => 'de_DE'],
            ['val' => 45.319, 'expected' => 45319.00, 'locale' => 'fr_FR'],
        ];

        $this->assertCurrencyToFloat($assertions, 'EUR');
    }

    public function test_pound_converts_to_float()
    {
        $assertions = [
            ['val' => '45,319.90 £', 'expected' => 45319.90, 'locale' => 'en_GB'],
            ['val' => '£ 45.389,90 ', 'expected' => 45389.90, 'locale' => 'fr_FR'],
            ['val' => '£ 45389.90 ', 'expected' => 45389.90, 'locale' => 'en'],
            ['val' => '45389.90 £', 'expected' => 45389.90, 'locale' => 'en_US'],
            ['val' => '£450,389.90 ', 'expected' => 450389.90, 'locale' => 'en_AU'],
            ['val' => '£1.95 ', 'expected' => 1.95, 'locale' => 'en'],
            ['val' => 195.43, 'expected' => 195.43, 'locale' => 'en_GB'],
            ['val' => 198, 'expected' => 198.00, 'locale' => 'en_GB'],
            ['val' => 'invalid', 'expected' => 0.0, 'locale' => 'en'],
        ];

        $this->assertCurrencyToFloat($assertions, 'GBP');
    }

    public function test_to_float_converts_string_value()
    {
        $this->assertEquals(10.5, CurrencyHelper::toFloat('10.5'));
    }

    public function test_to_float_handles_non_numeric_string()
    {
        $this->assertEquals(0.0, CurrencyHelper::toFloat('abc'));
    }

    public function test_to_string_formats_float_value()
    {
        $this->assertEquals('$10.50', CurrencyHelper::toString(10.5));
    }

    public function test_to_string_formats_int_value()
    {
        $this->assertEquals('$10.00', CurrencyHelper::toString(10));
    }

    public function test_to_string_formats_string_value()
    {
        $assertions = [
            ['val' => '5510.5', 'expected' => 'US$5,510.50', 'locale' => 'en_GB'],
            ['val' => '810.5', 'expected' => '$810.50', 'locale' => 'en_US'],
            ['val' => '9810.5', 'expected' => '$9,810.50', 'locale' => 'en_US'],
            ['val' => 9910.5, 'expected' => 'USD 9,910.50', 'locale' => 'en_AU'],
            ['val' => 9910.5, 'expected' => 'A$9,910.50', 'locale' => 'en_US', 'iso' => 'AUD'],
            ['val' => 9910.5, 'expected' => '$9,910.50', 'locale' => 'en_AU', 'iso' => 'AUD'],
            ['val' => 'invalid', 'expected' => '$0.00', 'locale' => 'en_US'],
            ['val' => 'invalid', 'expected' => 'USD 0.00', 'locale' => 'en_AU'],
            ['val' => 19977.50, 'expected' => '19 977,50 €', 'locale' => 'fr_FR', 'iso' => 'EUR'],
            ['val' => 'invalid', 'expected' => '0,00 €', 'locale' => 'de_DE', 'iso' => 'EUR'],
            ['val' => 19977.50, 'expected' => '£19,977.50', 'locale' => 'en_GB', 'iso' => 'GBP'],
            ['val' => 19977.50, 'expected' => 'GBP 19,977.50', 'locale' => 'en_AU', 'iso' => 'GBP'],
        ];

        foreach ($assertions as $assertion) {
            $iso = $assertion['iso'] ?? 'USD';

            $this->assertEquals(
                $assertion['expected'],
                CurrencyHelper::toString($assertion['val'], locale: $assertion['locale'], iso: $iso)
            );

            SettingsHelper::setSetting('default_locale_settings', [
                'locale' => $assertion['locale'],
                'currency' => $iso,
            ]);

            $this->assertEquals($assertion['expected'], CurrencyHelper::toString($assertion['val']));
        }
    }

    public function test_can_convert_to_and_from_string()
    {
        $assertions = [
            ['expected' => 'US$5,510.50', 'locale' => 'en_GB'],
            ['expected' => '$810.50', 'locale' => 'en_US'],
            ['expected' => '$9,810.50', 'locale' => 'en_US'],
            ['expected' => 'USD 9,910.50', 'locale' => 'en_AU'],
            ['expected' => 'A$9,910.50', 'locale' => 'en_US', 'iso' => 'AUD'],
            ['expected' => '$9,910.50', 'locale' => 'en_AU', 'iso' => 'AUD'],
            ['expected' => '19 977,50 €', 'locale' => 'fr_FR', 'iso' => 'EUR'],
            ['expected' => '0,00 €', 'locale' => 'de_DE', 'iso' => 'EUR'],
            ['expected' => '£19,977.50', 'locale' => 'en_GB', 'iso' => 'GBP'],
            ['expected' => 'GBP 19,977.50', 'locale' => 'en_AU', 'iso' => 'GBP'],
        ];

        foreach ($assertions as $assertion) {
            $iso = $assertion['iso'] ?? 'USD';

            $floatValue = CurrencyHelper::toFloat($assertion['expected'], locale: $assertion['locale'], iso: $iso);

            $this->assertEquals(
                $assertion['expected'],
                CurrencyHelper::toString($floatValue, locale: $assertion['locale'], iso: $iso)
            );

            SettingsHelper::setSetting('default_locale_settings', [
                'locale' => $assertion['locale'],
                'currency' => $iso,
            ]);

            $floatValue = CurrencyHelper::toFloat($assertion['expected'], locale: $assertion['locale'], iso: $iso);

            $this->assertEquals($assertion['expected'], CurrencyHelper::toString($floatValue));
        }
    }

    protected function assertCurrencyToFloat(array $assertions, string $iso): void
    {
        foreach ($assertions as $assertion) {
            $iso = $assertion['iso'] ?? $iso;

            SettingsHelper::setSetting('default_locale_settings', [
                'locale' => $assertion['locale'],
                'currency' => $iso,
            ]);

            $this->assertEquals($assertion['expected'], CurrencyHelper::toFloat($assertion['val']));
            $this->assertEquals($assertion['expected'], CurrencyHelper::toFloat($assertion['val'], $assertion['locale'], $iso));
        }
    }
}

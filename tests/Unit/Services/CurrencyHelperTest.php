<?php

namespace Tests\Unit\Services;

use App\Services\Helpers\CurrencyHelper;
use App\Services\Helpers\SettingsHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CurrencyHelperTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SettingsHelper::$settings = null;
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
            ['val' => 'invalid', 'expected' => null, 'locale' => 'en_US'],
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
            ['val' => 'invalid', 'expected' => null, 'locale' => 'de_DE'],
            ['val' => 45.319, 'expected' => 45.319, 'locale' => 'fr_FR'],
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
            ['val' => 'invalid', 'expected' => null, 'locale' => 'en'],
        ];

        $this->assertCurrencyToFloat($assertions, 'GBP');
    }

    public function test_to_float_converts_string_value()
    {
        $this->assertEquals(10.5, CurrencyHelper::toFloat('10.5'));
    }

    public function test_to_float_returns_null_for_non_numeric_string()
    {
        $this->assertNull(CurrencyHelper::toFloat('abc'));
        $this->assertNull(CurrencyHelper::toFloat(''));
        $this->assertNull(CurrencyHelper::toFloat('€'));
    }

    public function test_to_float_keeps_a_real_zero()
    {
        $this->assertSame(0.0, CurrencyHelper::toFloat('0'));
        $this->assertSame(0.0, CurrencyHelper::toFloat('0,00 €', 'fr_FR', 'EUR'));
    }

    public function test_to_float_does_not_reparse_php_numbers_with_the_locale()
    {
        $this->assertSame(16.4, CurrencyHelper::toFloat(16.4, 'fr_FR', 'EUR'));
        $this->assertSame(1234.5, CurrencyHelper::toFloat(1234.5, 'de_DE', 'EUR'));
        $this->assertSame(319.0, CurrencyHelper::toFloat(319, 'de_DE', 'EUR'));
    }

    /**
     * A store can return a dot decimal even when its locale uses a comma decimal,
     * for example Amazon.fr with "€319.00" (issue #219).
     */
    public function test_to_float_falls_back_when_the_format_does_not_match_the_locale()
    {
        $assertions = [
            ['val' => '16.4', 'expected' => 16.4, 'locale' => 'fr_FR'],
            ['val' => '€319.00', 'expected' => 319.0, 'locale' => 'fr_FR'],
            ['val' => '319.00', 'expected' => 319.0, 'locale' => 'fr_FR'],
            ['val' => '1234.5', 'expected' => 1234.5, 'locale' => 'de_DE'],
            ['val' => '€1,234.56', 'expected' => 1234.56, 'locale' => 'de_DE'],
            ['val' => '1,234.56 €', 'expected' => 1234.56, 'locale' => 'fr_FR'],
        ];

        $this->assertCurrencyToFloat($assertions, 'EUR');
    }

    public function test_to_float_still_reads_locale_formats()
    {
        $assertions = [
            ['val' => '16,40 €', 'expected' => 16.4, 'locale' => 'fr_FR'],
            ['val' => '1 234,56 €', 'expected' => 1234.56, 'locale' => 'fr_FR'],
            ['val' => '1.234,56', 'expected' => 1234.56, 'locale' => 'de_DE'],
            // Three digits after a single separator are a thousands group, not decimals.
            ['val' => '1.234', 'expected' => 1234.0, 'locale' => 'de_DE'],
        ];

        $this->assertCurrencyToFloat($assertions, 'EUR');
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

            $message = var_export($assertion['val'], true).' in '.$assertion['locale'];

            if ($assertion['expected'] === null) {
                $this->assertNull(CurrencyHelper::toFloat($assertion['val']), $message);
                $this->assertNull(CurrencyHelper::toFloat($assertion['val'], $assertion['locale'], $iso), $message);

                continue;
            }

            $this->assertEquals($assertion['expected'], CurrencyHelper::toFloat($assertion['val']), $message);
            $this->assertEquals($assertion['expected'], CurrencyHelper::toFloat($assertion['val'], $assertion['locale'], $iso), $message);
        }
    }
}

<?php

namespace Services;

use App\Services\Helpers\LocaleHelper;
use Tests\TestCase;

class LocaleHelperTest extends TestCase
{
    public function test_get_all_locales_grouped_as_options()
    {
        $locales = LocaleHelper::getAllLocalesAsOptions();

        $expected = [
            'en' => 'en (English)',
            'en_US' => 'en_US (English)',
            'es' => 'es (Spanish)',
            'fr' => 'fr (French)',
        ];

        foreach ($expected as $locale => $label) {
            $this->assertSame($label, $locales[$locale]);
        }
    }

    public function test_get_all_locale_currencies_grouped_as_options()
    {
        $locales = LocaleHelper::getAllCurrencyLocalesAsOptions();

        $expected = [
            'USD' => 'USD (US Dollar)',
            'AUD' => 'AUD (Australian Dollar)',
            'BRL' => 'BRL (Brazilian Real)',
            'CAD' => 'CAD (Canadian Dollar)',
            'CHF' => 'CHF (Swiss Franc)',
        ];

        foreach ($expected as $iso => $label) {
            $this->assertSame($label, $locales[$iso]);
        }
    }
}

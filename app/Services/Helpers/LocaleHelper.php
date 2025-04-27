<?php

namespace App\Services\Helpers;

use Locale;
use Symfony\Component\Intl\Currencies;
use Symfony\Component\Intl\Locales;

class LocaleHelper
{
    /**
     * This is only used to populate settings to the database, after
     * that it should be retrieved from the settings table.
     */
    public static function getAppLocaleSettings(): array
    {
        return [
            'locale' => config('app.locale', 'en'),
            'currency' => CurrencyHelper::getCurrencyFromLocale(
                config('app.currency_locale', 'en_US')
            )['iso'] ?? 'USD',
        ];
    }

    public static function getAllLocalesAsOptions(): array
    {
        return collect(Locales::getNames())
            ->mapWithKeys(fn ($name, $locale) => [
                $locale => $locale.' ('.Locale::getDisplayLanguage($locale).')',
            ])
            ->toArray();
    }

    public static function getAllCurrencyLocalesAsOptions(): array
    {
        return collect(Currencies::getNames())
            ->mapWithKeys(fn ($name, $iso) => [
                $iso => $iso.' ('.$name.')',
            ])
            ->sort()
            ->toArray();
    }
}

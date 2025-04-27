<?php

namespace App\Services\Helpers;

use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Number;
use Money\Currencies\ISOCurrencies;
use Money\Currency;
use Money\Exception\ParserException;
use Money\Formatter\DecimalMoneyFormatter;
use Money\Parser\IntlLocalizedDecimalParser;
use NumberFormatter;
use Symfony\Component\Intl\Currencies;

/**
 * Helpers to make dealing with currencies easier.
 */
class CurrencyHelper
{
    public static function getLocale(): string
    {
        return SettingsHelper::getSetting(
            'default_locale_settings.locale',
            config('app.locale', 'en')
        );
    }

    public static function getCurrency(): string
    {
        return SettingsHelper::getSetting('default_locale_settings.currency', 'USD');
    }

    public static function getCurrencyFromLocale(string $locale): ?array
    {
        return once(fn () => self::getAllCurrencies()
            ->firstWhere('locale', $locale)
        );
    }

    public static function getAllCurrencies(): Collection
    {
        return collect(json_decode(
            file_get_contents(base_path('/resources/datasets/currency.json')), true)
        )
            // Normalize the locale to use underscores instead of dashes and ensure not empty.
            ->map(fn ($currency) => array_merge($currency, [
                'locale' => empty($currency['locale'])
                    ? 'none'
                    : str_replace('-', '_', $currency['locale']),
            ]));
    }

    public static function getSymbol(?string $iso = null): string
    {
        return Currencies::getSymbol($iso ?? self::getCurrency());
    }

    public static function toFloat(mixed $value, ?string $locale = null, ?string $iso = null): float
    {
        $iso = $iso ?? self::getCurrency();
        $locale = $locale ?? self::getLocale();

        try {
            $value = (string) preg_replace('/[^\d\.\,]/', '', (string) $value);

            $currencies = new ISOCurrencies;
            $numberFormatter = new NumberFormatter($locale, NumberFormatter::DECIMAL);
            $moneyParser = new IntlLocalizedDecimalParser($numberFormatter, $currencies);
            $moneyFormatter = new DecimalMoneyFormatter($currencies);

            $money = $moneyParser->parse($value, new Currency($iso));

            return (float) $moneyFormatter->format($money);
        } catch (Exception|ParserException $e) {
            return 0.0;
        }
    }

    public static function toString(mixed $value, int $maxPrecision = 2, ?string $locale = null, ?string $iso = null): string
    {
        return Number::currency(
            number: round(floatval($value), $maxPrecision),
            in: ($iso ?? self::getCurrency()),
            locale: ($locale ?? self::getLocale())
        );
    }
}

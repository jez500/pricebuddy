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

    /**
     * Parse a price string into a float. Returns null when the value contains no
     * readable number, so a parse failure is never mistaken for a real zero price.
     */
    public static function toFloat(mixed $value, ?string $locale = null, ?string $iso = null): ?float
    {
        // A PHP number is already parsed. Re-reading it with the locale would turn
        // 45.319 into 45319 in a locale that uses "." as the thousands separator.
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        $iso = $iso ?? self::getCurrency();
        $locale = $locale ?? self::getLocale();
        $value = (string) preg_replace('/[^\d\.\,]/', '', (string) $value);

        if (! preg_match('/\d/', $value)) {
            return null;
        }

        // With both separators present the last one is always the decimal point. The
        // locale parser does not fail on the wrong order: de_DE reads "1,234.56" as 1.23.
        if (str_contains($value, '.') && str_contains($value, ',')) {
            return self::parseBySeparators($value);
        }

        // A zero or empty integer part is never a thousands group. The locale parser
        // does not fail on it: fr_FR reads "0.125" as 125.
        if (preg_match('/^0*[.,]\d+$/', $value)) {
            return self::parseBySeparators($value);
        }

        return self::parseWithLocale($value, $locale, $iso) ?? self::parseBySeparators($value);
    }

    protected static function parseWithLocale(string $value, string $locale, string $iso): ?float
    {
        try {
            $currencies = new ISOCurrencies;
            $currency = new Currency($iso);

            // Retailers can use thousands groups that differ from their locale:
            // en_ZA reads Takealot's "14,999" as 14.999. Preserve three-decimal
            // currencies, where a value such as "1.234" can be a real decimal.
            if ($currencies->subunitFor($currency) < 3
                && preg_match('/^[1-9]\d{0,2}([.,])\d{3}(?:\1\d{3})*$/', $value)) {
                return self::parseBySeparators($value);
            }

            $numberFormatter = new NumberFormatter($locale, NumberFormatter::DECIMAL);
            $moneyParser = new IntlLocalizedDecimalParser($numberFormatter, $currencies);
            $moneyFormatter = new DecimalMoneyFormatter($currencies);

            $money = $moneyParser->parse($value, $currency);

            return (float) $moneyFormatter->format($money);
        } catch (Exception|ParserException $e) {
            return null;
        }
    }

    /**
     * Parse a price that does not use the store locale's format, for example
     * "319.00" on a fr_FR store. The last separator is the decimal point, except that
     * a single kind of separator followed by exactly three digits is a thousands group.
     * That exception does not apply when the integer part is empty or zero ("0.125").
     */
    protected static function parseBySeparators(string $value): ?float
    {
        $lastSeparator = max((int) strrpos($value, '.'), (int) strrpos($value, ','));

        if (! str_contains($value, '.') && ! str_contains($value, ',')) {
            return (float) $value;
        }

        $integer = (string) preg_replace('/\D/', '', substr($value, 0, $lastSeparator));
        $fraction = (string) preg_replace('/\D/', '', substr($value, $lastSeparator + 1));
        $mixedSeparators = str_contains($value, '.') && str_contains($value, ',');

        if (! $mixedSeparators && strlen($fraction) === 3 && ltrim($integer, '0') !== '') {
            return (float) ($integer.$fraction);
        }

        return (float) (($integer === '' ? '0' : $integer).'.'.($fraction === '' ? '0' : $fraction));
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

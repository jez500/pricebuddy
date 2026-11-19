<?php

namespace App\Services;

use App\Enums\ExchangeRateProvider;
use App\Exceptions\ExchangeRateException;
use App\Models\ExchangeRate;
use App\Models\Price;
use App\Models\Store;
use App\Services\ExchangeRates\FrankfurterRateSource;
use App\Services\ExchangeRates\RateSource;
use App\Services\ExchangeRates\YahooFinanceRateSource;
use App\Services\Helpers\CurrencyHelper;
use App\Services\Helpers\SettingsHelper;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Converts store prices into the default currency.
 *
 * Stored prices are never changed: they stay in the store's own currency, and
 * conversion is applied when price history and caches are built. Turning the
 * feature off therefore restores the original behaviour after a cache rebuild.
 *
 * Remote providers store one rate per day in `exchange_rates`. A price is
 * converted with the rate for its own date, falling back to the closest earlier
 * rate (weekends and holidays have none), or the earliest rate for older prices.
 */
class CurrencyConverter
{
    public const string SETTINGS_KEY = 'currency_conversion';

    /**
     * Rate series loaded from the database, keyed "source:BASE:QUOTE".
     *
     * @var array<string, array{dates: array<int, string>, rates: array<int, float>}>
     */
    protected array $series = [];

    public static function new(): self
    {
        return app(self::class);
    }

    /***************************************************
     * Settings.
     **************************************************/

    public function isEnabled(): bool
    {
        return (bool) SettingsHelper::getSetting(self::SETTINGS_KEY.'.enabled', false);
    }

    public function provider(): ExchangeRateProvider
    {
        return ExchangeRateProvider::fromSetting(SettingsHelper::getSetting(self::SETTINGS_KEY.'.provider'));
    }

    /**
     * The currency every price is converted into: the app's default currency.
     */
    public function targetCurrency(): string
    {
        return strtoupper(CurrencyHelper::getCurrency());
    }

    public function targetLocale(): string
    {
        return CurrencyHelper::getLocale();
    }

    /**
     * Manual rates from settings, keyed by currency: units of the default currency per 1 unit.
     *
     * @return array<string, float>
     */
    public function manualRates(): array
    {
        return collect((array) SettingsHelper::getSetting(self::SETTINGS_KEY.'.manual_rates', []))
            ->filter(fn ($row): bool => is_array($row)
                && filled($row['currency'] ?? null)
                && is_numeric($row['rate'] ?? null)
                && (float) $row['rate'] > 0)
            ->mapWithKeys(fn (array $row): array => [strtoupper((string) $row['currency']) => (float) $row['rate']])
            ->all();
    }

    /**
     * Whether prices in this currency should be (and can be) converted.
     */
    public function shouldConvert(?string $currency): bool
    {
        return $this->isEnabled()
            && filled($currency)
            && strtoupper((string) $currency) !== $this->targetCurrency()
            && $this->rate((string) $currency) !== null;
    }

    /***************************************************
     * Conversion.
     **************************************************/

    /**
     * Units of the default currency one unit of $from buys on $date. Null when no rate is known.
     */
    public function rate(string $from, CarbonInterface|string|null $date = null): ?float
    {
        $from = strtoupper($from);
        $to = $this->targetCurrency();

        if ($from === $to) {
            return 1.0;
        }

        $provider = $this->provider();

        if (! $provider->isRemote()) {
            return $this->manualRates()[$from] ?? null;
        }

        $series = $this->loadSeries($provider, $from, $to);

        if ($series['dates'] === []) {
            return null;
        }

        $day = $date === null ? now()->toDateString() : Carbon::parse($date)->toDateString();

        return $series['rates'][$this->closestIndex($series['dates'], $day)];
    }

    /**
     * Convert an amount into the default currency. Null when no rate is known.
     */
    public function convert(float $amount, string $from, CarbonInterface|string|null $date = null): ?float
    {
        $rate = $this->rate($from, $date);

        return $rate === null ? null : round($amount * $rate, 2);
    }

    /**
     * Convert a history keyed Y-m-d, using each day's own rate. Returned unchanged
     * when conversion is off, not needed, or no rate is known.
     *
     * @param  Collection<string, float|int|string>  $history
     * @return Collection<string, float|int|string>
     */
    public function convertHistory(Collection $history, ?string $from): Collection
    {
        if (! $this->shouldConvert($from)) {
            return $history;
        }

        return $history->map(fn ($value, $date) => $this->convert((float) $value, (string) $from, (string) $date) ?? $value);
    }

    /**
     * A price's value in the default currency, or its own value when it is not converted.
     */
    public function convertPrice(Price $price): float
    {
        $value = (float) $price->price;

        if (! $this->isEnabled() || ! $price->store_id) {
            return $value;
        }

        $currency = $price->store?->currency;

        if (! $this->shouldConvert($currency)) {
            return $value;
        }

        return $this->convert($value, (string) $currency, $price->created_at) ?? $value;
    }

    /**
     * Format an amount in the default currency with the original amount after it,
     * e.g. "CA$142.58 (US$100.00)". Just the original when it is not converted.
     */
    public function formatWithOriginal(float $amount, ?string $currency, ?string $locale = null, CarbonInterface|string|null $date = null): string
    {
        $original = CurrencyHelper::toString($amount, locale: $locale, iso: $currency);

        if (! $this->shouldConvert($currency)) {
            return $original;
        }

        $converted = $this->convert($amount, (string) $currency, $date);

        return CurrencyHelper::toString($converted, locale: $this->targetLocale(), iso: $this->targetCurrency()).' ('.$original.')';
    }

    /**
     * Forget rates loaded from the database, e.g. after new ones were stored.
     */
    public function flush(): void
    {
        $this->series = [];
    }

    /***************************************************
     * Fetching rates.
     **************************************************/

    /**
     * Currencies used by stores that have URLs, other than the default currency.
     *
     * @return array<int, string>
     */
    public function neededCurrencies(): array
    {
        $target = $this->targetCurrency();

        return Store::query()
            ->whereHas('urls')
            ->get()
            ->map(fn (Store $store): string => strtoupper((string) $store->currency))
            ->filter(fn (string $currency): bool => $currency !== '' && $currency !== $target)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Download and store any missing daily rates for every needed currency, back to
     * the oldest price in the price history window (one year).
     *
     * @return array<string, array{added: int, error: ?string}> keyed by currency
     */
    public function syncRates(?CarbonInterface $today = null): array
    {
        $provider = $this->provider();

        if (! $this->isEnabled() || ! $provider->isRemote()) {
            return [];
        }

        $today = ($today ?? now())->copy()->startOfDay();
        $target = $this->targetCurrency();
        $earliest = $this->earliestPriceDate($today);
        $source = $this->rateSource($provider);
        $results = [];

        foreach ($this->neededCurrencies() as $currency) {
            $stored = ExchangeRate::query()
                ->where('source', $provider->value)
                ->where('base_currency', $currency)
                ->where('quote_currency', $target);

            $first = $stored->clone()->min('date');
            $last = $stored->clone()->max('date');

            // Refetch the last stored day as well: a rate for today can still change.
            $start = $first !== null && Carbon::parse($first)->lte($earliest->copy()->addWeek()) && $last !== null
                ? Carbon::parse($last)
                : $earliest->copy();

            try {
                $rates = $source->fetch($currency, $target, $start, $today);
                $results[$currency] = ['added' => $this->storeRates($provider, $currency, $target, $rates), 'error' => null];
            } catch (ExchangeRateException $e) {
                Log::warning('Could not fetch exchange rates', ['currency' => $currency, 'error' => $e->getMessage()]);
                $results[$currency] = ['added' => 0, 'error' => $e->getMessage()];
            }
        }

        $this->flush();

        return $results;
    }

    /**
     * Per needed currency: the rate in use today and where it comes from, for the settings page.
     *
     * @return array<int, array{currency: string, rate: ?float, date: ?string, days: int}>
     */
    public function status(): array
    {
        $provider = $this->provider();
        $target = $this->targetCurrency();

        return collect($this->neededCurrencies())
            ->map(function (string $currency) use ($provider, $target): array {
                if (! $provider->isRemote()) {
                    return ['currency' => $currency, 'rate' => $this->manualRates()[$currency] ?? null, 'date' => null, 'days' => 0];
                }

                $series = $this->loadSeries($provider, $currency, $target);
                $count = count($series['dates']);

                return [
                    'currency' => $currency,
                    'rate' => $count > 0 ? $series['rates'][$count - 1] : null,
                    'date' => $count > 0 ? $series['dates'][$count - 1] : null,
                    'days' => $count,
                ];
            })
            ->all();
    }

    protected function rateSource(ExchangeRateProvider $provider): RateSource
    {
        return match ($provider) {
            ExchangeRateProvider::Yahoo => app(YahooFinanceRateSource::class),
            default => app(FrankfurterRateSource::class),
        };
    }

    /**
     * Oldest date in the price history window that has a price, at most a year back.
     */
    protected function earliestPriceDate(CarbonInterface $today): Carbon
    {
        $windowStart = Carbon::instance($today)->subYear();
        $oldest = Price::query()->where('created_at', '>=', $windowStart)->min('created_at');

        return $oldest === null ? Carbon::instance($today) : Carbon::parse($oldest)->startOfDay();
    }

    /**
     * @param  array<string, float>  $rates
     */
    protected function storeRates(ExchangeRateProvider $provider, string $base, string $quote, array $rates): int
    {
        if ($rates === []) {
            return 0;
        }

        $existing = ExchangeRate::query()
            ->where('source', $provider->value)
            ->where('base_currency', $base)
            ->where('quote_currency', $quote)
            ->whereIn('date', array_keys($rates))
            ->count();

        $now = now();

        ExchangeRate::query()->upsert(
            collect($rates)->map(fn (float $rate, string $date): array => [
                'source' => $provider->value,
                'base_currency' => $base,
                'quote_currency' => $quote,
                'date' => $date,
                'rate' => $rate,
                'created_at' => $now,
                'updated_at' => $now,
            ])->values()->all(),
            ['source', 'base_currency', 'quote_currency', 'date'],
            ['rate', 'updated_at'],
        );

        return count($rates) - $existing;
    }

    /**
     * @return array{dates: array<int, string>, rates: array<int, float>}
     */
    protected function loadSeries(ExchangeRateProvider $provider, string $base, string $quote): array
    {
        $key = "{$provider->value}:{$base}:{$quote}";

        if (! isset($this->series[$key])) {
            $rows = ExchangeRate::query()
                ->where('source', $provider->value)
                ->where('base_currency', $base)
                ->where('quote_currency', $quote)
                ->orderBy('date')
                ->toBase()
                ->get(['date', 'rate']);

            $this->series[$key] = [
                'dates' => $rows->map(fn ($row): string => Carbon::parse($row->date)->toDateString())->all(),
                'rates' => $rows->map(fn ($row): float => (float) $row->rate)->all(),
            ];
        }

        return $this->series[$key];
    }

    /**
     * Index of the last date on or before $day, or 0 when $day is before every date.
     *
     * @param  array<int, string>  $dates  sorted Y-m-d
     */
    protected function closestIndex(array $dates, string $day): int
    {
        $low = 0;
        $high = count($dates) - 1;
        $found = 0;

        while ($low <= $high) {
            $mid = intdiv($low + $high, 2);

            if ($dates[$mid] <= $day) {
                $found = $mid;
                $low = $mid + 1;
            } else {
                $high = $mid - 1;
            }
        }

        return $found;
    }
}

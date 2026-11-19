<?php

namespace App\Services\ExchangeRates;

use App\Exceptions\ExchangeRateException;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * European Central Bank reference rates via the free Frankfurter API.
 *
 * @see https://frankfurter.dev
 */
class FrankfurterRateSource implements RateSource
{
    public const string BASE_URL = 'https://api.frankfurter.dev/v1';

    public function fetch(string $base, string $quote, CarbonInterface $start, CarbonInterface $end): array
    {
        $url = self::BASE_URL.'/'.$start->toDateString().'..'.$end->toDateString();

        try {
            $response = Http::timeout(20)
                ->acceptJson()
                ->get($url, ['base' => $base, 'symbols' => $quote]);
        } catch (Throwable $e) {
            throw new ExchangeRateException("Frankfurter request failed: {$e->getMessage()}", previous: $e);
        }

        if ($response->failed()) {
            // Frankfurter answers 404 for a currency it does not publish.
            throw new ExchangeRateException($response->status() === 404
                ? "Frankfurter does not publish rates for {$base}/{$quote}"
                : "Frankfurter returned HTTP {$response->status()}");
        }

        $rates = [];

        foreach ((array) $response->json('rates', []) as $date => $values) {
            $rate = data_get($values, $quote);

            if (is_numeric($rate) && $rate > 0) {
                $rates[(string) $date] = (float) $rate;
            }
        }

        ksort($rates);

        return $rates;
    }
}

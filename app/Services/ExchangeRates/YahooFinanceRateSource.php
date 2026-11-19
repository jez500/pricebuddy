<?php

namespace App\Services\ExchangeRates;

use App\Exceptions\ExchangeRateException;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Daily closing rates from Yahoo Finance's public chart endpoint (no API key).
 * The pair's symbol is BASEQUOTE=X, for example USDCAD=X.
 */
class YahooFinanceRateSource implements RateSource
{
    public const string BASE_URL = 'https://query1.finance.yahoo.com/v8/finance/chart/';

    public function fetch(string $base, string $quote, CarbonInterface $start, CarbonInterface $end): array
    {
        $symbol = strtoupper($base.$quote).'=X';

        try {
            $response = Http::timeout(20)
                // Yahoo rejects requests without a browser-like user agent.
                ->withUserAgent('Mozilla/5.0 (compatible; PriceBuddy)')
                ->acceptJson()
                ->get(self::BASE_URL.$symbol, [
                    'period1' => $start->copy()->startOfDay()->getTimestamp(),
                    'period2' => $end->copy()->endOfDay()->getTimestamp(),
                    'interval' => '1d',
                ]);
        } catch (Throwable $e) {
            throw new ExchangeRateException("Yahoo Finance request failed: {$e->getMessage()}", previous: $e);
        }

        if ($response->failed()) {
            throw new ExchangeRateException($response->status() === 404
                ? "Yahoo Finance has no rates for {$base}/{$quote}"
                : "Yahoo Finance returned HTTP {$response->status()}");
        }

        $result = $response->json('chart.result.0');

        if (! is_array($result)) {
            $error = $response->json('chart.error.description');

            throw new ExchangeRateException('Yahoo Finance returned no data'.($error ? ": {$error}" : ''));
        }

        $timestamps = (array) data_get($result, 'timestamp', []);
        $closes = (array) data_get($result, 'indicators.quote.0.close', []);
        $timezone = (string) data_get($result, 'meta.exchangeTimezoneName', 'UTC');

        $rates = [];

        foreach ($timestamps as $index => $timestamp) {
            $rate = $closes[$index] ?? null;

            if (! is_numeric($timestamp) || ! is_numeric($rate) || $rate <= 0) {
                continue;
            }

            $date = Carbon::createFromTimestamp((int) $timestamp, $timezone)->toDateString();
            $rates[$date] = round((float) $rate, 10);
        }

        ksort($rates);

        return $rates;
    }
}

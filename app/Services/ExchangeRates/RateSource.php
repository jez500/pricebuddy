<?php

namespace App\Services\ExchangeRates;

use App\Exceptions\ExchangeRateException;
use Carbon\CarbonInterface;

interface RateSource
{
    /**
     * Daily rates for a currency pair: how many units of $quote one unit of $base buys.
     * Days the source does not publish (weekends, holidays) are simply missing.
     *
     * @return array<string, float> keyed Y-m-d, chronological
     *
     * @throws ExchangeRateException when the source fails or does not know the pair
     */
    public function fetch(string $base, string $quote, CarbonInterface $start, CarbonInterface $end): array;
}

<?php

namespace App\Services\Insights;

use App\Dto\Insights\ListingHistory;
use App\Dto\Insights\PriceStatsData;
use Illuminate\Support\Collection;

class PriceStatsCalculator
{
    /**
     * Tolerance for treating two prices as equal, to absorb float rounding when matching a
     * value back to its date/store.
     */
    private const PRICE_EPSILON = 0.005;

    /**
     * @param  Collection<string, float>  $series
     * @param  Collection<int, ListingHistory>  $listings
     */
    public function calculate(Collection $series, Collection $listings): PriceStatsData
    {
        if ($series->isEmpty()) {
            return new PriceStatsData(0, null, null, 0, null, 0, 0, 0);
        }

        $lowest = (float) $series->min();
        $highest = (float) $series->max();
        $average = round((float) $series->avg(), 2);
        $current = (float) $series->last();

        $lowestDate = $series->filter(fn ($v): bool => abs((float) $v - $lowest) < self::PRICE_EPSILON)->keys()->first();
        $highestDate = $series->filter(fn ($v): bool => abs((float) $v - $highest) < self::PRICE_EPSILON)->keys()->first();

        $percentVsAverage = $average > 0 ? round((($current - $average) / $average) * 100, 0) : 0;

        return new PriceStatsData(
            lowest: $lowest,
            lowestDate: $lowestDate,
            lowestStore: $this->resolveStore($listings, $lowestDate, $lowest),
            highest: $highest,
            highestDate: $highestDate,
            average: $average,
            current: $current,
            percentVsAverage: (float) $percentVsAverage,
        );
    }

    /**
     * @param  Collection<int, ListingHistory>  $listings
     */
    protected function resolveStore(Collection $listings, ?string $date, float $price): ?string
    {
        if ($date === null) {
            return null;
        }

        foreach ($listings as $listing) {
            $value = $listing->history->get($date);
            if ($value !== null && abs((float) $value - $price) < self::PRICE_EPSILON) {
                return $listing->storeName;
            }
        }

        return null;
    }
}

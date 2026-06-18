<?php

namespace App\Services\Insights;

use App\Dto\Insights\SeasonalityData;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class SeasonalityCalculator
{
    private const ENOUGH_DAYS = 300;

    /**
     * @param  Collection<string, float>  $series
     */
    public function calculate(Collection $series): SeasonalityData
    {
        $byMonth = array_fill(1, 12, []);

        foreach ($series as $date => $price) {
            $month = (int) Carbon::parse($date)->month;
            $byMonth[$month][] = (float) $price;
        }

        $averages = [];
        foreach ($byMonth as $month => $values) {
            $averages[$month] = count($values) > 0 ? round(array_sum($values) / count($values), 2) : null;
        }

        $present = array_filter($averages, fn ($v): bool => $v !== null);
        $min = count($present) > 0 ? min($present) : null;
        $cheapest = $min === null
            ? []
            : array_keys(array_filter(
                $averages,
                fn ($v): bool => $v !== null && abs($v - $min) < 0.005,
            ));

        $spanDays = $series->isEmpty()
            ? 0
            : Carbon::parse($series->keys()->first())->diffInDays(Carbon::parse($series->keys()->last()));

        return new SeasonalityData($averages, $cheapest, $spanDays >= self::ENOUGH_DAYS);
    }
}

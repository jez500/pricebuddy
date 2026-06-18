<?php

namespace App\Services\Insights;

use App\Dto\Insights\ListingHistory;
use App\Dto\Insights\StoreShowdownData;
use Illuminate\Support\Collection;

class StoreShowdownCalculator
{
    /**
     * @param  Collection<int, ListingHistory>  $listings
     * @return Collection<int, StoreShowdownData>
     */
    public function calculate(Collection $listings): Collection
    {
        if ($listings->isEmpty()) {
            return collect();
        }

        $dates = collect();
        foreach ($listings as $listing) {
            $dates = $dates->merge($listing->history->keys());
        }
        $dates = $dates->unique()->values();
        $total = $dates->count();

        $wins = array_fill(0, $listings->count(), 0);

        foreach ($dates as $date) {
            $best = null;
            $bestIndex = null;
            foreach ($listings as $i => $listing) {
                $price = $listing->history->get($date);
                if ($price === null) {
                    continue;
                }
                $price = (float) $price;
                if ($best === null || $price < $best) {
                    $best = $price;
                    $bestIndex = $i;
                }
            }
            if ($bestIndex !== null) {
                $wins[$bestIndex]++;
            }
        }

        $currents = $listings->map(fn (ListingHistory $l): float => $l->history->isEmpty() ? INF : (float) $l->history->last());
        $minCurrent = $currents->min();

        return $listings
            ->map(function (ListingHistory $listing, int $i) use ($wins, $total, $minCurrent): StoreShowdownData {
                $current = $listing->history->isEmpty() ? 0.0 : (float) $listing->history->last();

                return new StoreShowdownData(
                    storeName: $listing->storeName,
                    currentPrice: $current,
                    isAvailable: ! $listing->availability->isUnavailable(),
                    winRate: $total > 0 ? round($wins[$i] / $total, 4) : 0.0,
                    isCheapestToday: $current > 0 && abs($current - $minCurrent) < 0.005,
                );
            })
            ->sortBy('currentPrice')
            ->values();
    }
}

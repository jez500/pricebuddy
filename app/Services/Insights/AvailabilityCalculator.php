<?php

namespace App\Services\Insights;

use App\Dto\Insights\AvailabilityData;
use App\Dto\Insights\ListingHistory;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class AvailabilityCalculator
{
    /**
     * @param  Collection<int, ListingHistory>  $listings
     * @return Collection<int, AvailabilityData>
     */
    public function calculate(Collection $listings): Collection
    {
        return $listings
            ->map(function (ListingHistory $listing): AvailabilityData {
                $history = $listing->history;

                if ($history->isEmpty()) {
                    return new AvailabilityData($listing->storeName, 0.0, $listing->availability, []);
                }

                $start = Carbon::parse($history->keys()->first());
                $end = Carbon::parse($history->keys()->last());

                $segments = [];
                $available = 0;
                $total = 0;
                $current = -1;

                for ($day = $start->copy(); $day->lte($end); $day->addDay()) {
                    $key = $day->toDateString();
                    $inStock = $history->has($key) && (float) $history->get($key) > 0;
                    $total++;
                    if ($inStock) {
                        $available++;
                    }

                    if ($current < 0 || $segments[$current]['available'] !== $inStock) {
                        $segments[] = ['available' => $inStock, 'days' => 1];
                        $current++;
                    } else {
                        $segments[$current]['days']++;
                    }
                }

                return new AvailabilityData(
                    storeName: $listing->storeName,
                    inStockPercent: $total > 0 ? round($available / $total * 100, 0) : 0.0,
                    currentStatus: $listing->availability,
                    segments: $segments,
                );
            })
            ->values();
    }
}

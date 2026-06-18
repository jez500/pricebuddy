<?php

namespace App\Services\Insights;

use App\Dto\Insights\DropEventData;
use App\Dto\Insights\ListingHistory;
use Illuminate\Support\Collection;

class DropEventCalculator
{
    /**
     * @param  Collection<int, ListingHistory>  $listings
     * @return Collection<int, DropEventData>
     */
    public function calculate(Collection $listings, float $thresholdPercent = 3, int $limit = 6): Collection
    {
        $events = collect();

        foreach ($listings as $listing) {
            $previous = null;

            foreach ($listing->history as $date => $price) {
                $price = (float) $price;

                if ($previous !== null && $previous > 0) {
                    $percent = (($price - $previous) / $previous) * 100;

                    if (abs($percent) >= $thresholdPercent) {
                        $events->push(new DropEventData(
                            storeName: $listing->storeName,
                            date: $date,
                            change: round($price - $previous, 2),
                            changePercent: round($percent, 1),
                            isDrop: $price < $previous,
                        ));

                        // Intentionally advance the baseline only on a notable move, so each
                        // event's change is measured from the last significant price, not raw
                        // day-over-day noise.
                        $previous = $price;
                    }
                } else {
                    $previous = $price;
                }
            }
        }

        return $events->sortByDesc('date')->take($limit)->values();
    }
}

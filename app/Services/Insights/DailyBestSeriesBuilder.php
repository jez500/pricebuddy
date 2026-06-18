<?php

namespace App\Services\Insights;

use App\Dto\Insights\ListingHistory;
use Illuminate\Support\Collection;

class DailyBestSeriesBuilder
{
    /**
     * @param  Collection<int, ListingHistory>  $listings
     * @return Collection<string, float>
     */
    public function fromListings(Collection $listings): Collection
    {
        $daily = [];

        foreach ($listings as $listing) {
            foreach ($listing->history as $date => $price) {
                $price = (float) $price;
                if ($price <= 0) {
                    continue;
                }
                $daily[$date] = isset($daily[$date]) ? min($daily[$date], $price) : $price;
            }
        }

        ksort($daily);

        return collect($daily);
    }
}

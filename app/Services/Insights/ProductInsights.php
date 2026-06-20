<?php

namespace App\Services\Insights;

use App\Dto\Insights\ListingHistory;
use App\Dto\Insights\ProductInsightsData;
use App\Models\Product;
use Illuminate\Support\Collection;

class ProductInsights
{
    /**
     * Minimum number of daily price points before the insights are treated as meaningful.
     */
    private const MIN_DATA_POINTS = 2;

    public static function for(Product $product): ProductInsightsData
    {
        return is_array($product->insights_cache) && $product->insights_cache !== []
            ? ProductInsightsData::fromArray($product->insights_cache)
            : self::build($product);
    }

    public static function build(Product $product): ProductInsightsData
    {
        return (new self)->compute($product);
    }

    protected function compute(Product $product): ProductInsightsData
    {
        $listings = (new ListingHistoriesBuilder)->build($product);
        $series = (new DailyBestSeriesBuilder)->fromListings($listings);
        $current = $series->isEmpty() ? 0.0 : (float) $series->last();

        $bestListing = $this->selectBestListing($listings);

        $bestStore = $bestListing?->storeName;

        $stats = (new PriceStatsCalculator)->calculate($series, $listings);
        $percentile = (new PercentileCalculator)->calculate($series, $current);
        $dealScore = (new DealScoreCalculator)->calculate($percentile->beatFraction, $current, $stats->lowest, $series->count());
        $distribution = (new DistributionCalculator)->calculate($series, $current);
        $dropEvents = (new DropEventCalculator)->calculate($listings);
        $storeShowdown = (new StoreShowdownCalculator)->calculate($listings);
        $seasonality = (new SeasonalityCalculator)->calculate($series);
        $availability = (new AvailabilityCalculator)->calculate($listings);
        $targetTracker = (new TargetTrackerCalculator)->calculate(
            $series,
            $current,
            $product->notify_price !== null ? (float) $product->notify_price : null,
        );

        return new ProductInsightsData(
            dailyBest: $series,
            bestPrice: $current,
            bestStore: $bestStore,
            stats: $stats,
            percentile: $percentile,
            dealScore: $dealScore,
            distribution: $distribution,
            dropEvents: $dropEvents,
            storeShowdown: $storeShowdown,
            seasonality: $seasonality,
            availability: $availability,
            targetTracker: $targetTracker,
            hasEnoughData: $series->count() >= self::MIN_DATA_POINTS,
        );
    }

    /**
     * The cheapest currently-available listing, falling back to the first listing when none
     * are available. Listings with no history sort last via an INF sentinel.
     *
     * @param  Collection<int, ListingHistory>  $listings
     */
    protected function selectBestListing(Collection $listings): ?ListingHistory
    {
        return $listings
            ->sortBy(fn (ListingHistory $l): float => $l->history->isEmpty() ? INF : (float) $l->history->last())
            ->first(fn (ListingHistory $l): bool => ! $l->availability->isUnavailable())
            ?? $listings->first();
    }
}

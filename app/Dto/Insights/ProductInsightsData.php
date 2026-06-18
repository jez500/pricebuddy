<?php

namespace App\Dto\Insights;

use Illuminate\Support\Collection;

final class ProductInsightsData
{
    /**
     * @param  Collection<string, float>  $dailyBest
     * @param  Collection<int, DropEventData>  $dropEvents
     * @param  Collection<int, StoreShowdownData>  $storeShowdown
     * @param  Collection<int, AvailabilityData>  $availability
     */
    public function __construct(
        public readonly Collection $dailyBest,
        public readonly float $bestPrice,
        public readonly ?string $bestStore,
        public readonly PriceStatsData $stats,
        public readonly PercentileData $percentile,
        public readonly DealScoreData $dealScore,
        public readonly DistributionData $distribution,
        public readonly Collection $dropEvents,
        public readonly Collection $storeShowdown,
        public readonly SeasonalityData $seasonality,
        public readonly Collection $availability,
        public readonly ?TargetTrackerData $targetTracker,
        public readonly bool $hasEnoughData,
    ) {}
}

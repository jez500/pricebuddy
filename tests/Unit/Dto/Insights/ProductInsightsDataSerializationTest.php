<?php

namespace Tests\Unit\Dto\Insights;

use App\Dto\Insights\AvailabilityData;
use App\Dto\Insights\DealScoreData;
use App\Dto\Insights\DistributionData;
use App\Dto\Insights\DropEventData;
use App\Dto\Insights\PercentileData;
use App\Dto\Insights\PriceStatsData;
use App\Dto\Insights\ProductInsightsData;
use App\Dto\Insights\SeasonalityData;
use App\Dto\Insights\StoreShowdownData;
use App\Dto\Insights\TargetTrackerData;
use App\Enums\StockStatus;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

class ProductInsightsDataSerializationTest extends TestCase
{
    public function test_full_tree_round_trips(): void
    {
        $dto = $this->sampleTree();

        $restored = ProductInsightsData::fromArray($dto->toArray());

        $this->assertEquals($dto, $restored);
    }

    public function test_null_target_tracker_round_trips(): void
    {
        $dto = $this->sampleTree(targetTracker: null);

        $restored = ProductInsightsData::fromArray($dto->toArray());

        $this->assertNull($restored->targetTracker);
        $this->assertEquals($dto, $restored);
    }

    private function sampleTree(?TargetTrackerData $targetTracker = new TargetTrackerData(90.0, 95.0, 5.0, 40, 1, 10, '2026-05-05')): ProductInsightsData
    {
        return new ProductInsightsData(
            dailyBest: new Collection(['2026-06-01' => 100.0, '2026-06-02' => 95.0]),
            bestPrice: 95.0,
            bestStore: 'Acme',
            stats: new PriceStatsData(80.0, '2026-01-02', 'Acme', 120.0, '2026-06-01', 100.0, 95.0, -5.0),
            percentile: new PercentileData(0.75, 75),
            dealScore: new DealScoreData(8.5, 'great', 'Great time to buy', false, false),
            distribution: new DistributionData([['min' => 80.0, 'max' => 90.0, 'count' => 2, 'isCurrent' => true]], 50),
            dropEvents: new Collection([new DropEventData('Acme', '2026-03-04', -5.5, -6.1, true)]),
            storeShowdown: new Collection([new StoreShowdownData('Acme', 95.0, true, 0.6, true)]),
            seasonality: new SeasonalityData([1 => 100.0, 2 => null], [1], false),
            availability: new Collection([new AvailabilityData('Acme', 82.5, StockStatus::InStock, [['available' => true, 'days' => 10]])]),
            targetTracker: $targetTracker,
            hasEnoughData: true,
        );
    }
}

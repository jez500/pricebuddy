<?php

namespace Tests\Unit\Dto\Insights;

use App\Dto\Insights\AvailabilityData;
use App\Dto\Insights\DealScoreData;
use App\Dto\Insights\DistributionData;
use App\Dto\Insights\DropEventData;
use App\Dto\Insights\PercentileData;
use App\Dto\Insights\PriceStatsData;
use App\Dto\Insights\SeasonalityData;
use App\Dto\Insights\StoreShowdownData;
use App\Dto\Insights\TargetTrackerData;
use App\Enums\StockStatus;
use PHPUnit\Framework\TestCase;

class LeafInsightsDtoSerializationTest extends TestCase
{
    public function test_price_stats_round_trips(): void
    {
        $dto = new PriceStatsData(80.0, '2026-01-02', 'Acme', 120.0, '2026-06-01', 100.0, 90.0, -10.0);
        $this->assertEquals($dto, PriceStatsData::fromArray($dto->toArray()));
    }

    public function test_percentile_round_trips(): void
    {
        $dto = new PercentileData(0.75, 75);
        $this->assertEquals($dto, PercentileData::fromArray($dto->toArray()));
    }

    public function test_deal_score_round_trips(): void
    {
        $dto = new DealScoreData(8.5, 'great', 'Great time to buy', true, false);
        $this->assertEquals($dto, DealScoreData::fromArray($dto->toArray()));
    }

    public function test_distribution_round_trips(): void
    {
        $dto = new DistributionData([
            ['min' => 80.0, 'max' => 90.0, 'count' => 3, 'isCurrent' => true],
            ['min' => 90.0, 'max' => 100.0, 'count' => 1, 'isCurrent' => false],
        ], 40);
        $this->assertEquals($dto, DistributionData::fromArray($dto->toArray()));
    }

    public function test_drop_event_round_trips(): void
    {
        $dto = new DropEventData('Acme', '2026-03-04', -5.5, -6.1, true);
        $this->assertEquals($dto, DropEventData::fromArray($dto->toArray()));
    }

    public function test_store_showdown_round_trips(): void
    {
        $dto = new StoreShowdownData('Acme', 99.99, true, 0.6, false);
        $this->assertEquals($dto, StoreShowdownData::fromArray($dto->toArray()));
    }

    public function test_availability_round_trips(): void
    {
        $dto = new AvailabilityData('Acme', 82.5, StockStatus::OutOfStock, [
            ['available' => true, 'days' => 10],
            ['available' => false, 'days' => 3],
        ]);
        $this->assertEquals($dto, AvailabilityData::fromArray($dto->toArray()));
    }

    public function test_seasonality_round_trips(): void
    {
        $dto = new SeasonalityData([1 => 100.0, 2 => null, 3 => 95.0], [3, 1], true);
        $this->assertEquals($dto, SeasonalityData::fromArray($dto->toArray()));
    }

    public function test_target_tracker_round_trips(): void
    {
        $dto = new TargetTrackerData(90.0, 95.0, 5.0, 40, 2, 10, '2026-05-05');
        $this->assertEquals($dto, TargetTrackerData::fromArray($dto->toArray()));
    }
}

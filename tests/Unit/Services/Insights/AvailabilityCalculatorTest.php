<?php

namespace Tests\Unit\Services\Insights;

use App\Dto\Insights\ListingHistory;
use App\Enums\StockStatus;
use App\Services\Insights\AvailabilityCalculator;
use Tests\TestCase;

class AvailabilityCalculatorTest extends TestCase
{
    public function test_it_builds_segments_and_in_stock_percent_from_gaps(): void
    {
        $listings = collect([
            new ListingHistory('Big W', collect([
                '2026-01-01' => 50.0,
                '2026-01-02' => 49.0,
                '2026-01-04' => 48.0,
            ]), StockStatus::InStock),
        ]);

        $result = (new AvailabilityCalculator)->calculate($listings);

        $first = $result->first();
        $this->assertSame(75.0, $first->inStockPercent);
        $this->assertSame(
            [
                ['available' => true, 'days' => 2],
                ['available' => false, 'days' => 1],
                ['available' => true, 'days' => 1],
            ],
            $first->segments,
        );
        $this->assertSame(StockStatus::InStock, $first->currentStatus);
    }
}

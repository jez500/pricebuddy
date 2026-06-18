<?php

namespace Tests\Unit\Services\Insights;

use App\Dto\Insights\ListingHistory;
use App\Enums\StockStatus;
use App\Services\Insights\StoreShowdownCalculator;
use Tests\TestCase;

class StoreShowdownCalculatorTest extends TestCase
{
    public function test_it_ranks_by_current_price_and_computes_win_rate(): void
    {
        $listings = collect([
            new ListingHistory('Big W', collect([
                '2026-01-01' => 50.0, '2026-01-02' => 42.0,
            ]), StockStatus::InStock),
            new ListingHistory('Amazon', collect([
                '2026-01-01' => 48.0, '2026-01-02' => 47.0,
            ]), StockStatus::InStock),
        ]);

        $result = (new StoreShowdownCalculator)->calculate($listings);

        $this->assertSame('Big W', $result->first()->storeName);
        $this->assertTrue($result->first()->isCheapestToday);
        $this->assertEqualsWithDelta(0.5, $result->first()->winRate, 0.001);
    }

    public function test_out_of_stock_listing_marked_unavailable(): void
    {
        $listings = collect([
            new ListingHistory('Amazon', collect(['2026-01-01' => 47.0]), StockStatus::OutOfStock),
        ]);

        $result = (new StoreShowdownCalculator)->calculate($listings);

        $this->assertFalse($result->first()->isAvailable);
    }
}

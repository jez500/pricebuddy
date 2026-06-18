<?php

namespace Tests\Unit\Services\Insights;

use App\Dto\Insights\ListingHistory;
use App\Services\Insights\PriceStatsCalculator;
use Tests\TestCase;

class PriceStatsCalculatorTest extends TestCase
{
    public function test_it_computes_low_high_average_current_and_store(): void
    {
        $series = collect([
            '2026-01-01' => 60.0,
            '2026-01-02' => 40.0,
            '2026-01-03' => 50.0,
        ]);
        $listings = collect([
            new ListingHistory('Big W', collect(['2026-01-02' => 40.0])),
        ]);

        $stats = (new PriceStatsCalculator)->calculate($series, $listings);

        $this->assertSame(40.0, $stats->lowest);
        $this->assertSame('2026-01-02', $stats->lowestDate);
        $this->assertSame('Big W', $stats->lowestStore);
        $this->assertSame(60.0, $stats->highest);
        $this->assertSame(50.0, $stats->average);
        $this->assertSame(50.0, $stats->current);
        $this->assertSame(0.0, $stats->percentVsAverage);
    }

    public function test_empty_series_returns_zeroed_stats(): void
    {
        $stats = (new PriceStatsCalculator)->calculate(collect(), collect());

        $this->assertSame(0.0, $stats->lowest);
        $this->assertNull($stats->lowestDate);
    }
}

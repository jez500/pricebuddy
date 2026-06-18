<?php

namespace Tests\Unit\Services\Insights;

use App\Services\Insights\SeasonalityCalculator;
use Tests\TestCase;

class SeasonalityCalculatorTest extends TestCase
{
    public function test_it_averages_by_month_and_picks_cheapest(): void
    {
        $series = collect([
            '2025-06-01' => 30.0,
            '2025-06-15' => 40.0,
            '2025-08-01' => 80.0,
        ]);

        $result = (new SeasonalityCalculator)->calculate($series);

        $this->assertSame(35.0, $result->monthlyAverages[6]);
        $this->assertSame(80.0, $result->monthlyAverages[8]);
        $this->assertNull($result->monthlyAverages[1]);
        $this->assertSame([6], $result->cheapestMonths);
    }

    public function test_short_history_has_not_enough_data(): void
    {
        $result = (new SeasonalityCalculator)->calculate(collect([
            '2026-01-01' => 30.0, '2026-01-10' => 31.0,
        ]));

        $this->assertFalse($result->hasEnoughData);
    }
}

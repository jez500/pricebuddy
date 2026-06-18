<?php

namespace Tests\Unit\Services\Insights;

use App\Services\Insights\PercentileCalculator;
use Tests\TestCase;

class PercentileCalculatorTest extends TestCase
{
    public function test_it_reports_fraction_of_days_more_expensive_than_current(): void
    {
        $series = collect([
            'a' => 60, 'b' => 58, 'c' => 55, 'd' => 50, 'e' => 48,
            'f' => 47, 'g' => 46, 'h' => 44, 'i' => 42, 'j' => 41,
        ]);

        $result = (new PercentileCalculator)->calculate($series, 42.0);

        $this->assertSame(80, $result->percentCheaperThan);
        $this->assertEqualsWithDelta(0.8, $result->beatFraction, 0.001);
    }

    public function test_empty_series_is_zero(): void
    {
        $result = (new PercentileCalculator)->calculate(collect(), 10.0);
        $this->assertSame(0, $result->percentCheaperThan);
    }
}

<?php

namespace Tests\Unit\Services\Insights;

use App\Services\Insights\DistributionCalculator;
use Tests\TestCase;

class DistributionCalculatorTest extends TestCase
{
    public function test_it_bins_prices_and_flags_current_band(): void
    {
        $series = collect([
            'a' => 40, 'b' => 41, 'c' => 42, 'd' => 50, 'e' => 60,
            'f' => 70, 'g' => 75, 'h' => 80,
        ]);

        $result = (new DistributionCalculator)->calculate($series, 41.0, 4);

        $this->assertCount(4, $result->bins);
        $this->assertSame(8, array_sum(array_column($result->bins, 'count')));
        $this->assertTrue($result->bins[0]['isCurrent']);
        $this->assertSame(38, $result->currentBinPercent);
    }

    public function test_flat_series_returns_single_full_bin(): void
    {
        $result = (new DistributionCalculator)->calculate(collect(['a' => 50, 'b' => 50]), 50.0, 8);

        $this->assertCount(1, $result->bins);
        $this->assertSame(100, $result->currentBinPercent);
    }
}

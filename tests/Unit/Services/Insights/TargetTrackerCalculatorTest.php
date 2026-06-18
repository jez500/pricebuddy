<?php

namespace Tests\Unit\Services\Insights;

use App\Services\Insights\TargetTrackerCalculator;
use Tests\TestCase;

class TargetTrackerCalculatorTest extends TestCase
{
    public function test_it_tracks_progress_and_hits_against_target(): void
    {
        $series = collect([
            '2026-01-01' => 60.0,
            '2026-01-02' => 38.0,
            '2026-01-03' => 42.0,
        ]);

        $result = (new TargetTrackerCalculator)->calculate($series, 42.0, 38.0);

        $this->assertNotNull($result);
        $this->assertSame(4.0, $result->gap);
        $this->assertSame(1, $result->hitCount);
        $this->assertSame('2026-01-02', $result->lastHitDate);
        $this->assertSame(82, $result->progressPercent);
    }

    public function test_no_target_returns_null(): void
    {
        $this->assertNull((new TargetTrackerCalculator)->calculate(collect(['a' => 10.0]), 10.0, null));
    }

    public function test_empty_series_returns_null(): void
    {
        $this->assertNull((new TargetTrackerCalculator)->calculate(collect(), 10.0, 8.0));
    }

    public function test_invalid_target_returns_null(): void
    {
        $series = collect(['2026-01-01' => 10.0]);

        $this->assertNull((new TargetTrackerCalculator)->calculate($series, 10.0, 0.0));
        $this->assertNull((new TargetTrackerCalculator)->calculate($series, 10.0, -5.0));
    }

    public function test_high_less_than_or_equal_to_target(): void
    {
        // Every observed price is already at/below the target, so the range is non-positive
        // and progress falls back to the current-vs-target check (100 when current <= target).
        $series = collect([
            '2026-01-01' => 30.0,
            '2026-01-02' => 28.0,
        ]);

        $result = (new TargetTrackerCalculator)->calculate($series, 30.0, 50.0);

        $this->assertNotNull($result);
        $this->assertSame(100, $result->progressPercent);
    }

    public function test_no_hits_returns_null_for_last_hit_date(): void
    {
        // All prices stay above the target -> no hits.
        $series = collect([
            '2026-01-01' => 60.0,
            '2026-01-02' => 70.0,
        ]);

        $result = (new TargetTrackerCalculator)->calculate($series, 60.0, 50.0);

        $this->assertNotNull($result);
        $this->assertSame(0, $result->hitCount);
        $this->assertNull($result->lastHitDate);
    }
}

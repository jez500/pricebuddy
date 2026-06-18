<?php

namespace Tests\Unit\Services\Insights;

use App\Services\Insights\DealScoreCalculator;
use Tests\TestCase;

class DealScoreCalculatorTest extends TestCase
{
    public function test_high_beat_fraction_is_a_great_buy(): void
    {
        $result = (new DealScoreCalculator)->calculate(0.85, 42.0, 39.0, 200);

        $this->assertSame(8.5, $result->score);
        $this->assertSame('great', $result->verdictKey);
        $this->assertSame('Great time to buy', $result->verdict);
        $this->assertFalse($result->isAllTimeLow);
        $this->assertFalse($result->lowConfidence);
    }

    public function test_all_time_low_floors_score_and_flags_it(): void
    {
        $result = (new DealScoreCalculator)->calculate(0.50, 39.0, 39.0, 200);

        $this->assertSame(9.5, $result->score);
        $this->assertTrue($result->isAllTimeLow);
    }

    public function test_low_history_flags_low_confidence(): void
    {
        $result = (new DealScoreCalculator)->calculate(0.10, 60.0, 55.0, 5);

        $this->assertSame('wait', $result->verdictKey);
        $this->assertTrue($result->lowConfidence);
    }
}

<?php

namespace Tests\Unit\Services\Insights;

use App\Services\Insights\DealScoreCalculator;
use Tests\TestCase;

class DealScoreCalculatorTest extends TestCase
{
    public function test_high_beat_fraction_is_a_great_buy(): void
    {
        $result = (new DealScoreCalculator)->calculate(0.85, 42.0, 39.0, 200, true, false);

        $this->assertSame(8.5, $result->score);
        $this->assertSame('great', $result->verdictKey);
        $this->assertSame('Great time to buy', $result->verdict);
        $this->assertFalse($result->isAllTimeLow);
        $this->assertFalse($result->lowConfidence);
    }

    public function test_all_time_low_floors_score_and_flags_it(): void
    {
        $result = (new DealScoreCalculator)->calculate(0.50, 39.0, 39.0, 200, true, false);

        $this->assertSame(9.5, $result->score);
        $this->assertTrue($result->isAllTimeLow);
    }

    public function test_low_history_flags_low_confidence(): void
    {
        $result = (new DealScoreCalculator)->calculate(0.10, 60.0, 55.0, 5, true, false);

        $this->assertSame('wait', $result->verdictKey);
        $this->assertTrue($result->lowConfidence);
    }

    public function test_a_price_that_has_never_moved_gets_no_verdict(): void
    {
        // A brand new product: current is trivially the lowest ever seen, which would
        // otherwise floor the score at 9.5 and claim an all-time low on day one.
        $result = (new DealScoreCalculator)->calculate(0.0, 39.0, 39.0, 1, false, false);

        $this->assertSame('unknown', $result->verdictKey);
        $this->assertSame('Not enough data yet', $result->verdict);
        $this->assertSame(0.0, $result->score);
        $this->assertFalse($result->isAllTimeLow);
        $this->assertTrue($result->lowConfidence);
    }

    public function test_a_long_but_perfectly_flat_history_still_gets_no_verdict(): void
    {
        // Plenty of data points, but every one of them is the same price, so there is
        // still nothing to compare today against.
        $result = (new DealScoreCalculator)->calculate(0.0, 39.0, 39.0, 200, false, false);

        $this->assertSame('unknown', $result->verdictKey);
        $this->assertFalse($result->isAllTimeLow);
    }

    public function test_beating_the_other_listings_is_a_great_buy_even_without_history(): void
    {
        // No history to go on, but it is cheaper than the product's other URLs right now,
        // which is a real reason to buy here.
        $result = (new DealScoreCalculator)->calculate(0.0, 39.0, 39.0, 1, false, true);

        $this->assertSame('great', $result->verdictKey);
        $this->assertSame('Great time to buy', $result->verdict);
        $this->assertFalse($result->isAllTimeLow, 'A flat history cannot be an all-time low.');
        $this->assertTrue($result->lowConfidence);
    }

    public function test_variation_restores_the_normal_scoring(): void
    {
        $result = (new DealScoreCalculator)->calculate(0.9, 39.0, 39.0, 200, true, true);

        $this->assertSame(9.5, $result->score);
        $this->assertTrue($result->isAllTimeLow);
    }
}

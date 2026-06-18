<?php

namespace Tests\Unit\Services\Insights;

use App\Dto\Insights\ListingHistory;
use App\Services\Insights\DropEventCalculator;
use Tests\TestCase;

class DropEventCalculatorTest extends TestCase
{
    public function test_it_detects_moves_over_the_threshold_newest_first(): void
    {
        $listings = collect([
            new ListingHistory('Big W', collect([
                '2026-01-01' => 50.0,
                '2026-01-02' => 50.5,
                '2026-01-03' => 42.0,
                '2026-01-04' => 46.0,
            ])),
        ]);

        $events = (new DropEventCalculator)->calculate($listings, 3, 6);

        $this->assertCount(2, $events);
        $this->assertSame('2026-01-04', $events->first()->date);
        $this->assertFalse($events->first()->isDrop);
        $this->assertTrue($events->last()->isDrop);
        $this->assertSame(-8.0, $events->last()->change);
    }

    public function test_limit_caps_results(): void
    {
        $listings = collect([
            new ListingHistory('A', collect([
                '2026-01-01' => 100.0, '2026-01-02' => 80.0, '2026-01-03' => 60.0,
                '2026-01-04' => 40.0, '2026-01-05' => 20.0,
            ])),
        ]);

        $events = (new DropEventCalculator)->calculate($listings, 3, 2);

        $this->assertCount(2, $events);
    }
}

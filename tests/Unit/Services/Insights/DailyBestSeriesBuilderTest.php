<?php

namespace Tests\Unit\Services\Insights;

use App\Dto\Insights\ListingHistory;
use App\Services\Insights\DailyBestSeriesBuilder;
use Tests\TestCase;

class DailyBestSeriesBuilderTest extends TestCase
{
    public function test_it_takes_the_lowest_price_per_day_across_listings(): void
    {
        $listings = collect([
            new ListingHistory('A', collect(['2026-01-01' => 50.0, '2026-01-02' => 45.0])),
            new ListingHistory('B', collect(['2026-01-01' => 48.0, '2026-01-02' => 49.0])),
        ]);

        $series = (new DailyBestSeriesBuilder)->fromListings($listings);

        $this->assertSame([48.0, 45.0], $series->values()->all());
        $this->assertSame(['2026-01-01', '2026-01-02'], $series->keys()->all());
    }

    public function test_it_ignores_zero_prices_and_sorts_by_date(): void
    {
        $listings = collect([
            new ListingHistory('A', collect(['2026-02-02' => 0.0, '2026-01-01' => 30.0])),
        ]);

        $series = (new DailyBestSeriesBuilder)->fromListings($listings);

        $this->assertSame(['2026-01-01'], $series->keys()->all());
    }
}

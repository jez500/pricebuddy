<?php

namespace Tests\Feature\Services\Dashboard;

use App\Enums\StockStatus;
use App\Models\Product;
use App\Models\Url;
use App\Models\User;
use App\Services\Dashboard\DashboardSections;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardSectionsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    /**
     * Build a product whose materialized deal score equals the given value.
     */
    private function productWithDealScore(float $score, bool $isAllTimeLow = false): Product
    {
        $product = Product::factory()->create([
            'user_id' => $this->user->id,
            'status' => 'p',
            'current_price' => 100.0,
            'price_cache' => [['price' => 100.0, 'date' => now()->toDateString()]],
        ]);

        $insights = $product->insights_cache ?? [];
        $insights['dealScore'] = [
            'score' => $score,
            'verdictKey' => $score >= 8 ? 'great' : ($score >= 6 ? 'good' : 'average'),
            'verdict' => 'test',
            'isAllTimeLow' => $isAllTimeLow,
            'lowConfidence' => false,
        ];
        $product->forceFill(['insights_cache' => $insights])->saveQuietly();

        return $product->fresh();
    }

    public function test_buy_now_includes_only_score_six_and_above(): void
    {
        $good = $this->productWithDealScore(7.0);
        $this->productWithDealScore(4.0); // excluded

        $ids = (new DashboardSections($this->user))->buyNow()->pluck('id');

        $this->assertTrue($ids->contains($good->id));
        $this->assertCount(1, $ids);
    }

    public function test_buy_now_pins_all_time_low_first(): void
    {
        $highScore = $this->productWithDealScore(9.0, false);
        $allTimeLow = $this->productWithDealScore(8.0, true);

        $first = (new DashboardSections($this->user))->buyNow()->first();

        $this->assertEquals($allTimeLow->id, $first->id);
    }

    public function test_needs_attention_relaxes_favourite_filter(): void
    {
        // A non-favourite product with a failed last scrape must still surface.
        // `Product::is_last_scrape_successful` is driven by
        // `PriceCacheDto::isLastScrapeSuccessful()`, which checks the `last_scrape`
        // timestamp recency (must be < 24h old) -- there is no boolean
        // "last_scrape_success" key in the cached array. We force staleness by
        // backdating `last_scrape` on the cached row instead.
        $product = Product::factory()
            ->addUrlWithPrices('https://example.com/x', [100.0])
            ->create([
                'user_id' => $this->user->id,
                'status' => 'p',
                'favourite' => false,
                'paused' => false,
            ]);

        $cache = $product->price_cache;
        $cache[0]['last_scrape'] = now()->subDays(2)->toDateTimeString();
        $product->forceFill(['price_cache' => $cache])->saveQuietly();

        $this->assertFalse($product->fresh()->is_last_scrape_successful);

        $ids = (new DashboardSections($this->user))->needsAttention()->pluck('id');

        $this->assertTrue($ids->contains($product->id));
    }

    /**
     * Build a product with a single URL and a real, dated price history via
     * `addUrlWithPrices()` (backdates each price by day index, so the last
     * price in `$prices` becomes `current_price`).
     */
    private function productWithPrices(array $prices, array $attrs = []): Product
    {
        return Product::factory()
            ->addUrlWithPrices('https://example.com/'.uniqid(), $prices)
            ->create(array_merge([
                'user_id' => $this->user->id,
                'status' => 'p',
            ], $attrs));
    }

    public function test_stat_bar_tracked_counts_only_products_with_price_and_cache(): void
    {
        // Real, trackable product: current_price > 0 and non-empty price_cache.
        $tracked = $this->productWithPrices([60, 60, 30]);

        // current_price is 0 -- excluded from "tracked".
        $zeroPrice = Product::factory()->create([
            'user_id' => $this->user->id,
            'status' => 'p',
            'current_price' => 0,
            'price_cache' => [['price' => 0, 'history' => []]],
        ]);

        // price_cache is empty -- excluded from "tracked".
        $emptyCache = Product::factory()->create([
            'user_id' => $this->user->id,
            'status' => 'p',
            'current_price' => 50,
            'price_cache' => [],
        ]);

        $this->assertSame(0.0, (float) $zeroPrice->fresh()->current_price);
        $this->assertSame([], $emptyCache->fresh()->price_cache);

        $stats = (new DashboardSections($this->user))->statBar();

        $this->assertSame(1, $stats['tracked']);
    }

    public function test_stat_bar_potential_savings_sums_positive_drops_and_clamps_at_zero(): void
    {
        // avg = (60+50+30+40)/4 = 45, current (last price) = 40 -> savings = 5.
        $this->productWithPrices([60, 50, 30, 40]);

        // avg = (60+60+70)/3 = 63.33, current (last price) = 70 -> above average,
        // savings clamps to 0 rather than going negative.
        $this->productWithPrices([60, 60, 70]);

        $stats = (new DashboardSections($this->user))->statBar();

        $this->assertSame(5.0, $stats['potentialSavings']);
    }

    public function test_stat_bar_at_lowest_and_below_average_counts(): void
    {
        // current (30) <= min (30) -> Trend::Lowest.
        $lowest = $this->productWithPrices([60, 60, 30]);

        // current (40) < avg (45), > min (30) -> Trend::Down.
        $down = $this->productWithPrices([60, 50, 30, 40]);

        // current (70) > avg (63.33) -> Trend::Up, excluded from both counts.
        $this->productWithPrices([60, 60, 70]);

        // current (60) == avg (60) -> Trend::None, excluded from both counts.
        $this->productWithPrices([60, 59, 61, 60]);

        $this->assertSame('lowest', $lowest->fresh()->trend);
        $this->assertSame('down', $down->fresh()->trend);

        $stats = (new DashboardSections($this->user))->statBar();

        $this->assertSame(1, $stats['atLowest']);
        $this->assertSame(2, $stats['belowAverage']);
    }

    public function test_stat_bar_out_of_stock_counts_products_whose_price_cache_has_an_out_of_stock_row(): void
    {
        // One in-stock URL (keeps current_price > 0 / product tracked) plus one
        // out-of-stock URL, so price_cache carries an out-of-stock row without
        // zeroing current_price (mirrors ProductTest's multi-url convention).
        $product = $this->productWithPrices([60, 60, 70]);

        Url::factory()->createOne([
            'product_id' => $product->getKey(),
            'url' => 'https://example.com/oos',
            'availability' => StockStatus::OutOfStock,
        ]);

        $product->updatePriceCache();

        $this->assertGreaterThan(0, $product->fresh()->current_price);
        $this->assertTrue(collect($product->fresh()->price_cache)->contains(
            fn (array $row): bool => ($row['availability'] ?? null) === StockStatus::OutOfStock->value
        ));

        $stats = (new DashboardSections($this->user))->statBar();

        $this->assertSame(1, $stats['outOfStock']);
    }

    public function test_recently_dropped_includes_products_with_a_recent_drop_and_excludes_others(): void
    {
        // Strictly decreasing: every earlier (backdated) price is greater than
        // the current (latest) price -- matches scopeLowestPriceInDays(7).
        $dropped = $this->productWithPrices([50, 40, 30]);

        // Strictly increasing: no earlier price exceeds the current price --
        // no drop recorded in the window.
        $notDropped = $this->productWithPrices([30, 40, 50]);

        $ids = (new DashboardSections($this->user))->recentlyDropped()->pluck('id');

        $this->assertTrue($ids->contains($dropped->id));
        $this->assertFalse($ids->contains($notDropped->id));
    }

    public function test_recently_dropped_sorts_by_drop_magnitude_descending(): void
    {
        // avg = 70, current = 40 -> drop magnitude 30.
        $biggerDrop = $this->productWithPrices([100, 40]);

        // avg = 55, current = 50 -> drop magnitude 5.
        $smallerDrop = $this->productWithPrices([60, 50]);

        $ids = (new DashboardSections($this->user))->recentlyDropped()->pluck('id')->values();

        $this->assertSame([$biggerDrop->id, $smallerDrop->id], $ids->all());
    }

    public function test_recently_dropped_excludes_zero_price_products_despite_a_recorded_drop(): void
    {
        // Real price history that satisfies scopeLowestPriceInDays(7) (0 < every
        // historical row once current_price is forced to 0 below), but the
        // product is excluded via the shared `isTracked()` guard.
        $product = $this->productWithPrices([50, 40, 30]);
        $product->forceFill(['current_price' => 0])->saveQuietly();

        $this->assertSame(0.0, (float) $product->fresh()->current_price);

        $ids = (new DashboardSections($this->user))->recentlyDropped()->pluck('id');

        $this->assertFalse($ids->contains($product->id));
    }
}

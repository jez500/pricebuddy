<?php

namespace Tests\Feature\Services\Dashboard;

use App\Models\Product;
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
}

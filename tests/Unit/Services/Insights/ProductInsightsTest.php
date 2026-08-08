<?php

namespace Tests\Unit\Services\Insights;

use App\Dto\Insights\ProductInsightsData;
use App\Models\Product;
use App\Services\Insights\ProductInsights;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductInsightsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_assembles_all_modules_for_a_product(): void
    {
        $product = Product::factory()
            ->addUrlWithPrices('https://example-a.com/p', [60, 55, 50, 45, 42])
            ->addUrlWithPrices('https://example-b.com/p', [62, 58, 54])
            ->create(['notify_price' => 40]);

        $insights = ProductInsights::for($product);

        $this->assertInstanceOf(ProductInsightsData::class, $insights);
        $this->assertSame(42.0, $insights->bestPrice);
        $this->assertSame(42.0, $insights->stats->current);
        $this->assertSame(42.0, $insights->stats->lowest);
        $this->assertTrue($insights->dealScore->isAllTimeLow);
        $this->assertCount(2, $insights->storeShowdown);
        $this->assertNotNull($insights->targetTracker);
        $this->assertTrue($insights->hasEnoughData);
    }

    public function test_for_returns_distinct_insights_per_product_in_one_request(): void
    {
        $cheaper = Product::factory()
            ->addUrlWithPrices('https://example-a.com/p', [60, 55, 50, 45, 42])
            ->create();

        $pricier = Product::factory()
            ->addUrlWithPrices('https://example-b.com/p', [120, 118, 115])
            ->create();

        // Both resolved within the same request: each must reflect its own data,
        // not a value memoized from the first call.
        $this->assertSame(42.0, ProductInsights::for($cheaper)->bestPrice);
        $this->assertSame(115.0, ProductInsights::for($pricier)->bestPrice);
    }

    public function test_a_newly_added_product_does_not_claim_to_be_a_great_buy(): void
    {
        $product = Product::factory()
            ->addUrlWithPrices('https://example-a.com/p', [50])
            ->create();

        $insights = ProductInsights::for($product);

        $this->assertSame('unknown', $insights->dealScore->verdictKey);
        $this->assertSame('Not enough data yet', $insights->dealScore->verdict);
        $this->assertSame(0.0, $insights->dealScore->score);
        $this->assertFalse($insights->dealScore->isAllTimeLow);
    }

    public function test_a_price_that_has_never_changed_does_not_claim_to_be_a_great_buy(): void
    {
        $product = Product::factory()
            ->addUrlWithPrices('https://example-a.com/p', [50, 50, 50, 50, 50])
            ->create();

        $insights = ProductInsights::for($product);

        $this->assertSame('unknown', $insights->dealScore->verdictKey);
        $this->assertFalse($insights->dealScore->isAllTimeLow);
    }

    public function test_a_flat_price_that_beats_the_other_listings_is_a_great_buy(): void
    {
        $product = Product::factory()
            ->addUrlWithPrices('https://example-a.com/p', [50, 50, 50])
            ->addUrlWithPrices('https://example-b.com/p', [60, 60, 60])
            ->create();

        $insights = ProductInsights::for($product);

        $this->assertSame('great', $insights->dealScore->verdictKey);
        $this->assertFalse($insights->dealScore->isAllTimeLow);
    }

    public function test_flat_listings_at_the_same_price_have_nothing_to_go_on(): void
    {
        $product = Product::factory()
            ->addUrlWithPrices('https://example-a.com/p', [50, 50, 50])
            ->addUrlWithPrices('https://example-b.com/p', [50, 50, 50])
            ->create();

        $this->assertSame('unknown', ProductInsights::for($product)->dealScore->verdictKey);
    }

    public function test_a_moving_price_is_still_scored_normally(): void
    {
        $product = Product::factory()
            ->addUrlWithPrices('https://example-a.com/p', [60, 55, 50, 45, 42])
            ->create();

        $insights = ProductInsights::for($product);

        $this->assertSame('great', $insights->dealScore->verdictKey);
        $this->assertTrue($insights->dealScore->isAllTimeLow);
    }

    public function test_product_without_prices_reports_no_data(): void
    {
        $product = Product::factory()->create();

        $insights = ProductInsights::for($product);

        $this->assertFalse($insights->hasEnoughData);
        $this->assertSame(0.0, $insights->bestPrice);
        $this->assertNull($insights->targetTracker);
    }
}

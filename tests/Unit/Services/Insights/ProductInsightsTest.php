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

    public function test_product_without_prices_reports_no_data(): void
    {
        $product = Product::factory()->create();

        $insights = ProductInsights::for($product);

        $this->assertFalse($insights->hasEnoughData);
        $this->assertSame(0.0, $insights->bestPrice);
        $this->assertNull($insights->targetTracker);
    }
}

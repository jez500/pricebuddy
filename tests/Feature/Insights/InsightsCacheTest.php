<?php

namespace Tests\Feature\Insights;

use App\Dto\Insights\ProductInsightsData;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Services\Insights\ProductInsights;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\ScraperTrait;

class InsightsCacheTest extends TestCase
{
    use RefreshDatabase;
    use ScraperTrait;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        Store::query()->delete();
        Store::factory()->create(['domains' => [['domain' => 'example.com']]]);
    }

    private function productWithPrices(): Product
    {
        return Product::factory()
            ->addUrlWithPrices('https://example.com/p1', [120, 110, 100, 95])
            ->create(['user_id' => $this->user->id]);
    }

    public function test_update_price_cache_populates_insights_cache(): void
    {
        $product = $this->productWithPrices()->fresh();

        $this->assertIsArray($product->insights_cache);
        $this->assertArrayHasKey('dealScore', $product->insights_cache);
        $this->assertArrayHasKey('stats', $product->insights_cache);
    }

    public function test_for_reads_from_cache_column(): void
    {
        $product = $this->productWithPrices()->fresh();

        $cache = $product->insights_cache;
        $cache['bestStore'] = 'SENTINEL STORE';
        $product->update(['insights_cache' => $cache]);

        $insights = ProductInsights::for($product->fresh());

        $this->assertSame('SENTINEL STORE', $insights->bestStore);
    }

    public function test_for_computes_live_when_cache_null(): void
    {
        $product = $this->productWithPrices();
        $product->update(['insights_cache' => null]);

        $insights = ProductInsights::for($product->fresh());

        $this->assertInstanceOf(ProductInsightsData::class, $insights);
        $this->assertTrue($insights->hasEnoughData);
    }

    public function test_build_matches_rehydrated_cache(): void
    {
        $product = $this->productWithPrices()->fresh();

        $live = ProductInsights::build($product)->toArray();
        $cached = ProductInsights::for($product)->toArray();

        $this->assertEquals($live, $cached);
    }
}

<?php

namespace Tests\Unit\Services\Insights;

use App\Models\Product;
use App\Services\Insights\ListingHistoriesBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListingHistoriesBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_builds_one_listing_per_url_with_store_name_and_history(): void
    {
        $product = Product::factory()
            ->addUrlWithPrices('https://example-a.com/p', [50, 45, 42])
            ->addUrlWithPrices('https://example-b.com/p', [60, 55])
            ->create();

        $listings = (new ListingHistoriesBuilder)->build($product);

        $this->assertCount(2, $listings);
        $this->assertSame(3, $listings->firstWhere('storeName', $product->urls[0]->store->name)->history->count());
        $this->assertSame(42.0, (float) $listings->firstWhere('storeName', $product->urls[0]->store->name)->history->last());
    }
}

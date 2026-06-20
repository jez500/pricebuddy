<?php

namespace Tests\Feature\Models;

use App\Models\Price;
use App\Models\Product;
use App\Models\Url;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ProductUpdatePricesTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_empty_collection_when_all_urls_succeed(): void
    {
        $url = Mockery::mock(Url::class)->makePartial();
        $url->shouldReceive('updatePrice')->once()->andReturn(new Price);

        $product = Mockery::mock(Product::class)->makePartial();
        $product->shouldReceive('updatePriceCache')->once();
        $product->shouldReceive('updateInsightsCache')->once();
        $product->setRelation('urls', new EloquentCollection([$url]));

        $failed = $product->updatePrices();

        $this->assertInstanceOf(EloquentCollection::class, $failed);
        $this->assertTrue($failed->isEmpty());
    }

    public function test_returns_only_the_failed_urls(): void
    {
        $okUrl = Mockery::mock(Url::class)->makePartial();
        $okUrl->shouldReceive('updatePrice')->once()->andReturn(new Price);

        $failUrl = Mockery::mock(Url::class)->makePartial();
        $failUrl->shouldReceive('updatePrice')->once()->andReturnNull();

        $product = Mockery::mock(Product::class)->makePartial();
        $product->shouldReceive('updatePriceCache')->once();
        $product->shouldReceive('updateInsightsCache')->once();
        $product->setRelation('urls', new EloquentCollection([$okUrl, $failUrl]));

        $failed = $product->updatePrices();

        $this->assertCount(1, $failed);
        $this->assertSame($failUrl, $failed->first());
    }
}

<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\ProductResource;
use App\Models\Product;
use App\Models\Url;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ViewProductPriceSummaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_view_shows_price_min_avg_max(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $product = Product::factory()->create(['user_id' => $user->id]);
        Url::factory()->withPrices([30, 20, 25])->createOne(['product_id' => $product->id]);

        $aggregates = $product->refresh()->price_aggregates;
        $this->assertNotEmpty($aggregates);

        $response = $this->get(ProductResource::getUrl('view', ['record' => $product]));
        $response->assertOk();

        // The summary block is rendered.
        $response->assertSee('product-price-summary');

        // And it shows each aggregate value.
        foreach (['min', 'avg', 'max'] as $method) {
            $response->assertSee($aggregates[$method]);
        }
    }

    public function test_view_hides_summary_when_no_price_history(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $product = Product::factory()->create(['user_id' => $user->id]);

        $this->get(ProductResource::getUrl('view', ['record' => $product]))
            ->assertOk()
            ->assertDontSee('product-price-summary');
    }
}

<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\ProductResource;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductInsightsTabTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    public function test_insights_tab_renders_modules_for_a_product_with_history(): void
    {
        $product = Product::factory()
            ->addUrlWithPrices('https://example-a.com/p', [60, 55, 50, 45, 42])
            ->create(['notify_price' => 40, 'user_id' => $this->user->getKey()]);

        $this->get(ProductResource::getUrl('view', ['record' => $product]))
            ->assertOk()
            ->assertSee('Insights')
            ->assertSee('Should I buy right now?')
            ->assertSee('Price distribution')
            ->assertSee('Store showdown')
            ->assertSee('All-time low');
    }

    public function test_insights_tab_shows_empty_state_without_history(): void
    {
        $product = Product::factory()->create(['user_id' => $this->user->getKey()]);

        $this->get(ProductResource::getUrl('view', ['record' => $product]))
            ->assertOk()
            ->assertSee('Not enough price history yet');
    }
}

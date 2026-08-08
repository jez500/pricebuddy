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

    public function test_the_hero_is_tinted_by_the_verdict_not_always_green(): void
    {
        // Rising to its highest ever price: the answer is "wait", so the card must not
        // read as the good news the primary palette implies.
        $product = Product::factory()
            ->addUrlWithPrices('https://example-a.com/p', [40, 45, 50, 55, 60])
            ->create(['user_id' => $this->user->getKey()]);

        $response = $this->get(ProductResource::getUrl('view', ['record' => $product]))->assertOk();

        $response->assertSee('rgba(var(--danger-500),.08)', escape: false)
            ->assertDontSee('rgba(var(--primary-500),.08)', escape: false)
            ->assertSee("Wait — it's expensive right now");
    }

    public function test_a_good_deal_keeps_the_primary_tint(): void
    {
        $product = Product::factory()
            ->addUrlWithPrices('https://example-a.com/p', [60, 55, 50, 45, 42])
            ->create(['user_id' => $this->user->getKey()]);

        $this->get(ProductResource::getUrl('view', ['record' => $product]))
            ->assertOk()
            ->assertSee('Great time to buy')
            ->assertSee('rgba(var(--primary-500),.08)', escape: false);
    }

    public function test_a_product_with_no_verdict_yet_is_tinted_neutrally(): void
    {
        $product = Product::factory()
            ->addUrlWithPrices('https://example-a.com/p', [50, 50, 50])
            ->create(['user_id' => $this->user->getKey()]);

        $this->get(ProductResource::getUrl('view', ['record' => $product]))
            ->assertOk()
            ->assertSee('Not enough data yet')
            ->assertSee('rgba(var(--gray-500),.08)', escape: false)
            ->assertDontSee('rgba(var(--primary-500),.08)', escape: false);
    }

    public function test_insights_tab_shows_empty_state_without_history(): void
    {
        $product = Product::factory()->create(['user_id' => $this->user->getKey()]);

        $this->get(ProductResource::getUrl('view', ['record' => $product]))
            ->assertOk()
            ->assertSee('Not enough price history yet');
    }
}

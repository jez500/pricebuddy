<?php

namespace Tests\Feature\Livewire;

use App\Livewire\ProductCardDetail;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProductCardCountdownTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_card_renders_countdown_for_active_product(): void
    {
        $product = Product::factory()->create(['refresh_interval' => 3600, 'paused' => false]);
        $product->forceFill(['next_check_at' => now()->addMinutes(30)])->saveQuietly();

        Livewire::test(ProductCardDetail::class, ['product' => $product, 'showChart' => true])
            ->assertSee('Next check')
            ->assertSeeHtml('width: ${progress}%')
            ->assertDontSee('Paused');
    }

    public function test_card_shows_paused_state(): void
    {
        $product = Product::factory()->create(['refresh_interval' => 3600, 'paused' => true]);

        Livewire::test(ProductCardDetail::class, ['product' => $product, 'showChart' => true])
            ->assertSee('Next check')
            ->assertSee('Paused');
    }
}

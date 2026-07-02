<?php

namespace Tests\Feature\Livewire;

use App\Livewire\ProductCardDetail;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProductCardDetailTest extends TestCase
{
    use RefreshDatabase;

    private function pausedProduct(): Product
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        // `paused` is the sole trigger for the countdown's "Next check: Paused"
        // branch; the price_cache row just lets the card body render.
        return Product::factory()->create([
            'user_id' => $user->id,
            'paused' => true,
            'price_cache' => [['price' => 100.0, 'date' => now()->toDateString(), 'history' => []]],
        ]);
    }

    public function test_next_check_shown_by_default(): void
    {
        $product = $this->pausedProduct();

        Livewire::test(ProductCardDetail::class, ['product' => $product])
            ->assertSee('Next check');
    }

    public function test_next_check_hidden_when_disabled(): void
    {
        $product = $this->pausedProduct();

        Livewire::test(ProductCardDetail::class, ['product' => $product, 'showNextCheck' => false])
            ->assertDontSee('Next check');
    }
}

<?php

namespace Tests\Feature\Http;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductFetchAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_fetch_product_prices(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create(['user_id' => $user->id]);

        $this->post(route('filament.admin.resources.products.fetch', $product))
            ->assertRedirect(route('filament.admin.auth.login'));
    }

    public function test_user_cannot_fetch_another_users_product(): void
    {
        $owner = User::factory()->create();
        $attacker = User::factory()->create();
        $product = Product::factory()->create(['user_id' => $owner->id]);

        $this->actingAs($attacker)
            ->post(route('filament.admin.resources.products.fetch', $product))
            ->assertForbidden();
    }
}

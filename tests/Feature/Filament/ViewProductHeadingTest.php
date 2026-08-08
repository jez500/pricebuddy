<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\ProductResource;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ViewProductHeadingTest extends TestCase
{
    use RefreshDatabase;

    const LONG_TITLE = 'DAREU A950GM Wireless Gaming Mouse (Black), 60g ultra-lightweight ergonomic design with USB-C, 2.4GHz and Bluetooth tri-mode connectivity, PAW3395 sensor and CX52850 MCU';

    public function test_long_heading_is_clamped_but_keeps_the_full_title_on_hover(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $product = Product::factory()->create([
            'user_id' => $user->getKey(),
            'title' => self::LONG_TITLE,
        ]);

        $response = $this->get(ProductResource::getUrl('view', ['record' => $product]));

        $response->assertOk();
        $response->assertSee('line-clamp-2', false);
        $response->assertSee('title="'.e(self::LONG_TITLE).'"', false);
    }
}

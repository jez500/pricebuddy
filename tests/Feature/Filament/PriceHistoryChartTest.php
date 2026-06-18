<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\ProductResource\Widgets\PriceHistoryChart;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PriceHistoryChartTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_chart_includes_a_best_price_dataset(): void
    {
        $product = Product::factory()
            ->addUrlWithPrices('https://example-a.com/p', [60, 55, 50])
            ->create();

        Livewire::test(PriceHistoryChart::class, ['record' => $product])
            ->assertSee('Best price');
    }

    public function test_chart_includes_target_line_when_notify_price_is_set(): void
    {
        $product = Product::factory()
            ->addUrlWithPrices('https://example-a.com/p', [60, 55, 50])
            ->create(['notify_price' => 45.00]);

        Livewire::test(PriceHistoryChart::class, ['record' => $product])
            ->assertSee('Your target');
    }
}

<?php

namespace Tests\Feature\Widgets;

use App\Filament\Widgets\ProductStats;
use App\Models\Product;
use App\Models\User;
use App\Services\Dashboard\DashboardLayoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProductStatsInteractionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    public function test_reorder_products_persists_weights(): void
    {
        $a = Product::factory()->create(['user_id' => $this->user->id, 'weight' => 0]);
        $b = Product::factory()->create(['user_id' => $this->user->id, 'weight' => 0]);
        $c = Product::factory()->create(['user_id' => $this->user->id, 'weight' => 0]);

        Livewire::test(ProductStats::class)->call('reorderProducts', [$c->id, $a->id, $b->id]);

        $this->assertSame(0, $c->fresh()->weight);
        $this->assertSame(1, $a->fresh()->weight);
        $this->assertSame(2, $b->fresh()->weight);
    }

    public function test_reorder_products_ignores_foreign_products(): void
    {
        $mine = Product::factory()->create(['user_id' => $this->user->id, 'weight' => 5]);
        $theirs = Product::factory()->create(['user_id' => User::factory()->create()->id, 'weight' => 9]);

        Livewire::test(ProductStats::class)->call('reorderProducts', [$theirs->id, $mine->id]);

        $this->assertSame(9, $theirs->fresh()->weight); // untouched
        $this->assertSame(0, $mine->fresh()->weight);   // theirs skipped without increment, mine is first owned id
    }

    public function test_reorder_categories_persists_order(): void
    {
        Livewire::test(ProductStats::class)->call('reorderCategories', ['3-7', '12', 'uncategorized']);

        $this->assertSame(['3-7', '12', 'uncategorized'], (new DashboardLayoutService($this->user->fresh()))->categoryOrder());
    }

    public function test_toggle_section_persists(): void
    {
        Livewire::test(ProductStats::class)->call('toggleSection', 'buy_now');

        $this->assertFalse((new DashboardLayoutService($this->user->fresh()))->isSectionVisible('buy_now'));
    }

    public function test_toggle_collapse_persists(): void
    {
        Livewire::test(ProductStats::class)->call('toggleCategoryCollapse', '3-7');

        $this->assertTrue((new DashboardLayoutService($this->user->fresh()))->isCategoryCollapsed('3-7'));
    }
}

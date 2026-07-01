<?php

namespace Tests\Feature\Widgets;

use App\Filament\Widgets\ProductStats;
use App\Models\Product;
use App\Models\Tag;
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

    public function test_buy_now_section_renders_when_visible(): void
    {
        $product = Product::factory()->create([
            'user_id' => $this->user->id,
            'status' => 'p',
            'current_price' => 100.0,
            'price_cache' => [['price' => 100.0, 'date' => now()->toDateString(), 'history' => []]],
        ]);
        $insights = $product->insights_cache ?? [];
        $insights['dealScore'] = ['score' => 9.0, 'verdictKey' => 'great', 'verdict' => 'Great time to buy', 'isAllTimeLow' => true, 'lowConfidence' => false];
        $product->forceFill(['insights_cache' => $insights])->saveQuietly();

        Livewire::test(ProductStats::class)->assertSee('Great time to buy');
    }

    public function test_hidden_section_not_rendered(): void
    {
        (new DashboardLayoutService($this->user))->toggleSection('stat_bar');

        Livewire::test(ProductStats::class)->assertDontSee('Potential savings');
    }

    public function test_needs_attention_section_colors_negative_verdict_correctly(): void
    {
        // needs_attention doesn't filter on deal score, so a product with a
        // cached negative verdict (e.g. "wait") must not be styled with the
        // same positive/primary color used for a genuine great deal.
        $product = Product::factory()->create([
            'user_id' => $this->user->id,
            'status' => 'p',
            'paused' => false,
            'current_price' => 100.0,
            'price_cache' => [[
                'price' => 100.0,
                'date' => now()->toDateString(),
                'history' => [],
                'last_scrape' => now()->subDays(2)->toDateTimeString(),
            ]],
        ]);
        $insights = $product->insights_cache ?? [];
        $insights['dealScore'] = [
            'score' => 3.0,
            'verdictKey' => 'wait',
            'verdict' => "Wait — it's expensive right now",
            'isAllTimeLow' => false,
            'lowConfidence' => false,
        ];
        $product->forceFill(['insights_cache' => $insights])->saveQuietly();

        Livewire::test(ProductStats::class)
            ->assertSee("Wait — it's expensive right now")
            ->assertSee('text-danger-600')
            ->assertDontSee('text-primary-600');
    }

    public function test_category_group_exposes_collapse_control(): void
    {
        $tag = Tag::factory()->create(['name' => 'Alpha', 'weight' => 10, 'user_id' => $this->user->id]);
        $p = Product::factory()->create(['user_id' => $this->user->id, 'favourite' => true, 'status' => 'p', 'price_cache' => [['price' => 1, 'date' => now()->toDateString(), 'history' => []]]]);
        $p->tags()->attach($tag);

        Livewire::test(ProductStats::class)
            ->assertSeeHtml('wire:click="toggleCategoryCollapse(\''.$tag->id.'\')"');
    }

    public function test_category_group_exposes_sortable_containers(): void
    {
        $tag = Tag::factory()->create(['name' => 'Alpha', 'weight' => 10, 'user_id' => $this->user->id]);
        $p = Product::factory()->create(['user_id' => $this->user->id, 'favourite' => true, 'status' => 'p', 'price_cache' => [['price' => 1, 'date' => now()->toDateString(), 'history' => []]]]);
        $p->tags()->attach($tag);

        Livewire::test(ProductStats::class)
            ->assertSeeHtml('x-sortable')
            ->assertSeeHtml('x-on:end.stop="$wire.reorderCategories($event.target.sortable.toArray())"')
            ->assertSeeHtml('x-on:end.stop="$wire.reorderProducts($event.target.sortable.toArray())"')
            ->assertSeeHtml('data-category-signature="'.$tag->id.'"')
            ->assertSeeHtml('data-product-id="'.$p->id.'"')
            ->assertSeeHtml('x-sortable-item="'.$tag->id.'"')
            ->assertSeeHtml('x-sortable-item="'.$p->id.'"');
    }

    public function test_section_exposes_hide_control(): void
    {
        Product::factory()->create([
            'user_id' => $this->user->id,
            'status' => 'p',
            'current_price' => 100.0,
            'price_cache' => [['price' => 100.0, 'date' => now()->toDateString(), 'history' => []]],
        ])->forceFill(['insights_cache' => ['dealScore' => ['score' => 9.0, 'verdictKey' => 'great', 'verdict' => 'Great time to buy', 'isAllTimeLow' => true, 'lowConfidence' => false]]])->saveQuietly();

        Livewire::test(ProductStats::class)
            ->assertSeeHtml('wire:click="toggleSection(\'buy_now\')"');
    }

    public function test_all_sections_listed_in_customize_control(): void
    {
        // Even a hidden section must appear in the customise control so it can be re-enabled.
        (new DashboardLayoutService($this->user))->toggleSection('recently_dropped');

        Livewire::test(ProductStats::class)
            ->assertSeeHtml('wire:click="toggleSection(\'recently_dropped\')"');
    }
}

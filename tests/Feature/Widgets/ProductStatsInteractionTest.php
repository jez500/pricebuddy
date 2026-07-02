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

    public function test_move_product_to_category_replaces_tags(): void
    {
        $from = Tag::factory()->create(['user_id' => $this->user->id]);
        $to = Tag::factory()->create(['user_id' => $this->user->id]);
        $product = Product::factory()->create(['user_id' => $this->user->id]);
        $product->tags()->attach($from);

        Livewire::test(ProductStats::class)
            ->call('moveProductToCategory', $product->id, (string) $to->id, [$product->id]);

        $this->assertSame([$to->id], $product->fresh()->tags->pluck('id')->all());
    }

    public function test_move_product_to_multi_tag_category_syncs_full_set(): void
    {
        $product = Product::factory()->create(['user_id' => $this->user->id]);
        $t1 = Tag::factory()->create(['user_id' => $this->user->id]);
        $t2 = Tag::factory()->create(['user_id' => $this->user->id]);
        $signature = collect([$t1->id, $t2->id])->sort()->values()->implode('-');

        Livewire::test(ProductStats::class)
            ->call('moveProductToCategory', $product->id, $signature, [$product->id]);

        $this->assertEqualsCanonicalizing([$t1->id, $t2->id], $product->fresh()->tags->pluck('id')->all());
    }

    public function test_move_product_to_uncategorized_clears_tags(): void
    {
        $tag = Tag::factory()->create(['user_id' => $this->user->id]);
        $product = Product::factory()->create(['user_id' => $this->user->id]);
        $product->tags()->attach($tag);

        Livewire::test(ProductStats::class)
            ->call('moveProductToCategory', $product->id, 'uncategorized', [$product->id]);

        $this->assertCount(0, $product->fresh()->tags);
    }

    public function test_move_product_reweights_destination_order(): void
    {
        $to = Tag::factory()->create(['user_id' => $this->user->id]);
        $a = Product::factory()->create(['user_id' => $this->user->id, 'weight' => 0]);
        $moved = Product::factory()->create(['user_id' => $this->user->id, 'weight' => 0]);
        $b = Product::factory()->create(['user_id' => $this->user->id, 'weight' => 0]);

        Livewire::test(ProductStats::class)
            ->call('moveProductToCategory', $moved->id, (string) $to->id, [$a->id, $moved->id, $b->id]);

        $this->assertSame(0, $a->fresh()->weight);
        $this->assertSame(1, $moved->fresh()->weight);
        $this->assertSame(2, $b->fresh()->weight);
        $this->assertSame([$to->id], $moved->fresh()->tags->pluck('id')->all());
    }

    public function test_move_product_ignores_foreign_product(): void
    {
        $theirs = Product::factory()->create(['user_id' => User::factory()->create()->id]);
        $myTag = Tag::factory()->create(['user_id' => $this->user->id]);

        Livewire::test(ProductStats::class)
            ->call('moveProductToCategory', $theirs->id, (string) $myTag->id, [$theirs->id]);

        $this->assertCount(0, $theirs->fresh()->tags);
    }

    public function test_move_product_ignores_foreign_tag_in_signature(): void
    {
        $foreignTag = Tag::factory()->create(['user_id' => User::factory()->create()->id]);
        $product = Product::factory()->create(['user_id' => $this->user->id]);

        Livewire::test(ProductStats::class)
            ->call('moveProductToCategory', $product->id, (string) $foreignTag->id, [$product->id]);

        // Foreign tag is filtered out, so the product ends up uncategorised
        // rather than attached to another user's tag.
        $this->assertCount(0, $product->fresh()->tags);
    }

    public function test_product_lists_share_sortable_group_for_cross_category_drops(): void
    {
        $tag = Tag::factory()->create(['name' => 'Alpha', 'weight' => 10, 'user_id' => $this->user->id]);
        $p = Product::factory()->create(['user_id' => $this->user->id, 'favourite' => true, 'status' => 'p', 'price_cache' => [['price' => 1, 'date' => now()->toDateString(), 'history' => []]]]);
        $p->tags()->attach($tag);

        Livewire::test(ProductStats::class)
            ->assertSeeHtml('x-sortable-group="products"')
            ->assertSeeHtml('$wire.moveProductToCategory(');
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
        // stat_bar is hidden by default, so its stats must not render.
        Livewire::test(ProductStats::class)->assertDontSee('Potential savings');
    }

    public function test_needs_attention_section_shows_negative_verdict_as_non_positive_badge(): void
    {
        // needs_attention doesn't filter on deal score, so a product with a
        // cached negative verdict ("wait") must render a danger-coloured badge,
        // never the success colour used for a genuine great deal.
        // needs_attention is hidden by default; enable it so the product renders.
        (new DashboardLayoutService($this->user))->toggleSection('needs_attention');

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
            ->assertSeeHtml('data-verdict-color="danger"')
            ->assertDontSeeHtml('data-verdict-color="success"');
    }

    public function test_category_group_exposes_collapse_control(): void
    {
        $tag = Tag::factory()->create(['name' => 'Alpha', 'weight' => 10, 'user_id' => $this->user->id]);
        $p = Product::factory()->create(['user_id' => $this->user->id, 'favourite' => true, 'status' => 'p', 'price_cache' => [['price' => 1, 'date' => now()->toDateString(), 'history' => []]]]);
        $p->tags()->attach($tag);

        Livewire::test(ProductStats::class)
            ->assertSeeHtml('wire:click="toggleCategoryCollapse(\''.$tag->id.'\')"')
            // Collapse control is announced to assistive tech: labelled, with state + target.
            ->assertSeeHtml('aria-expanded="true"')
            ->assertSeeHtml('aria-controls="cat-grid-'.$tag->id.'"')
            ->assertSeeHtml('aria-label="Collapse Alpha"');
    }

    public function test_collapsed_category_grid_is_hidden(): void
    {
        $tag = Tag::factory()->create(['name' => 'Alpha', 'weight' => 10, 'user_id' => $this->user->id]);
        $p = Product::factory()->create(['user_id' => $this->user->id, 'favourite' => true, 'status' => 'p', 'price_cache' => [['price' => 1, 'date' => now()->toDateString(), 'history' => []]]]);
        $p->tags()->attach($tag);

        (new DashboardLayoutService($this->user))->toggleCategoryCollapse((string) $tag->id);

        Livewire::test(ProductStats::class)
            ->assertSeeHtml('data-category-signature="'.$tag->id.'"')
            ->assertSeeHtml('class="fi-wi-stats-overview-stats-ctn grid gap-6 md:grid-cols-2 2xl:grid-cols-3 hidden"');
    }

    public function test_category_group_exposes_sortable_containers(): void
    {
        $tag = Tag::factory()->create(['name' => 'Alpha', 'weight' => 10, 'user_id' => $this->user->id]);
        $p = Product::factory()->create(['user_id' => $this->user->id, 'favourite' => true, 'status' => 'p', 'price_cache' => [['price' => 1, 'date' => now()->toDateString(), 'history' => []]]]);
        $p->tags()->attach($tag);

        Livewire::test(ProductStats::class)
            ->assertSeeHtml('x-sortable')
            ->assertSeeHtml('x-on:end.stop="$wire.reorderCategories($event.target.sortable.toArray())"')
            ->assertSeeHtml('$wire.reorderProducts($event.to.sortable.toArray())')
            ->assertSeeHtml('data-category-signature="'.$tag->id.'"')
            ->assertSeeHtml('data-product-id="'.$p->id.'"')
            ->assertSeeHtml('x-sortable-item="'.$tag->id.'"')
            ->assertSeeHtml('x-sortable-item="'.$p->id.'"');
    }

    public function test_buy_now_verdict_renders_as_success_badge(): void
    {
        Product::factory()->create([
            'user_id' => $this->user->id,
            'status' => 'p',
            'current_price' => 100.0,
            'price_cache' => [['price' => 100.0, 'date' => now()->toDateString(), 'history' => []]],
        ])->forceFill(['insights_cache' => ['dealScore' => [
            'score' => 9.0, 'verdictKey' => 'great', 'verdict' => 'Great time to buy',
            'isAllTimeLow' => true, 'lowConfidence' => false,
        ]]])->saveQuietly();

        Livewire::test(ProductStats::class)
            ->assertSee('Great time to buy')
            ->assertSeeHtml('data-verdict-color="success"');
    }

    public function test_section_header_has_no_hide_button(): void
    {
        Product::factory()->create([
            'user_id' => $this->user->id,
            'status' => 'p',
            'current_price' => 100.0,
            'price_cache' => [['price' => 100.0, 'date' => now()->toDateString(), 'history' => []]],
        ])->forceFill(['insights_cache' => ['dealScore' => [
            'score' => 9.0, 'verdictKey' => 'great', 'verdict' => 'Great time to buy',
            'isAllTimeLow' => true, 'lowConfidence' => false,
        ]]])->saveQuietly();

        Livewire::test(ProductStats::class)
            ->assertDontSeeHtml('class="ml-auto text-xs text-gray-400 hover:text-gray-600"');
    }

    public function test_product_image_is_the_drag_handle(): void
    {
        $tag = Tag::factory()->create(['name' => 'Alpha', 'weight' => 10, 'user_id' => $this->user->id]);
        $p = Product::factory()->create(['user_id' => $this->user->id, 'favourite' => true, 'status' => 'p', 'price_cache' => [['price' => 1, 'date' => now()->toDateString(), 'history' => []]]]);
        $p->tags()->attach($tag);

        Livewire::test(ProductStats::class)
            ->assertSeeHtml('w-20 h-20 min-w-20 m-2 rounded-md overflow-hidden p-1 flex items-center cursor-grab');
    }

    public function test_smart_section_card_is_not_draggable(): void
    {
        // A non-favourite product surfaces in buy_now (a smart section) but not
        // in a draggable category group, so its card must omit the drag handle.
        Product::factory()->create([
            'user_id' => $this->user->id,
            'favourite' => false,
            'status' => 'p',
            'current_price' => 100.0,
            'price_cache' => [['price' => 100.0, 'date' => now()->toDateString(), 'history' => []]],
        ])->forceFill(['insights_cache' => ['dealScore' => [
            'score' => 9.0, 'verdictKey' => 'great', 'verdict' => 'Great time to buy',
            'isAllTimeLow' => true, 'lowConfidence' => false,
        ]]])->saveQuietly();

        Livewire::test(ProductStats::class)
            ->assertSee('Great time to buy')    // card rendered in the smart section
            ->assertDontSeeHtml('cursor-grab'); // but without a drag handle
    }

    public function test_category_drag_handle_is_heading_icon_and_text_only(): void
    {
        $tag = Tag::factory()->create(['name' => 'Alpha', 'weight' => 10, 'user_id' => $this->user->id]);
        $p = Product::factory()->create(['user_id' => $this->user->id, 'favourite' => true, 'status' => 'p', 'price_cache' => [['price' => 1, 'date' => now()->toDateString(), 'history' => []]]]);
        $p->tags()->attach($tag);

        Livewire::test(ProductStats::class)
            // The drag handle is the inner span (icon + text) carrying the resize cursor.
            ->assertSeeHtml('x-sortable-handle')
            ->assertSeeHtml('class="flex gap-2 items-center cursor-ns-resize"')
            // The <h3> heading row itself no longer carries the resize cursor / handle.
            ->assertDontSeeHtml('dark:text-white cursor-ns-resize')
            // Collapse chevron stays outside the handle, pushed to the right.
            ->assertSeeHtml('wire:click="toggleCategoryCollapse(\''.$tag->id.'\')"')
            ->assertSeeHtml('ml-auto text-gray-400')
            ->assertDontSeeHtml('Drag to reorder');
    }
}

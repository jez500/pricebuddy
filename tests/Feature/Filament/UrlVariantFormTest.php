<?php

namespace Tests\Feature\Filament;

use App\Enums\StockStatus;
use App\Filament\Forms\VariantFilterFields;
use App\Filament\Resources\ProductResource\Pages\CreateProduct;
use App\Filament\Resources\ProductResource\Pages\ViewProduct;
use App\Filament\Resources\ProductResource\Widgets\ProductUrlStats;
use App\Filament\Resources\ProductResource\Widgets\UrlsTableWidget;
use App\Filament\Resources\StoreResource\Pages\EditStore;
use App\Models\Product;
use App\Models\Store;
use App\Models\Url;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\TestCase;
use Tests\Traits\VariantTrait;

class UrlVariantFormTest extends TestCase
{
    use RefreshDatabase;
    use VariantTrait;

    const string URL = 'https://example.com/trail-shorts';

    protected User $user;

    protected Store $store;

    protected Product $product;

    protected Url $url;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        $this->store = $this->createVariantStore();
        $this->mockVariantPage();

        $this->product = Product::factory()->for($this->user)->addUrlWithPrices(self::URL, [30, 30])->createOne();

        /** @var Url $url */
        $url = $this->product->urls()->first();
        $this->url = $url;
    }

    public function test_edit_page_widget_sets_the_variant()
    {
        Livewire::test(UrlsTableWidget::class, ['record' => $this->product])
            ->callTableAction('edit', $this->url, data: [
                'url' => self::URL,
                'price_factor' => 1,
                'variant' => ['values' => ['Small']],
            ])
            ->assertHasNoTableActionErrors();

        $this->url->refresh();

        $this->assertSame(['values' => ['Small']], $this->url->variant);
        $this->assertSame(StockStatus::OutOfStock, $this->url->availability);
    }

    public function test_edit_page_widget_shows_the_variant()
    {
        $this->url->update(['variant' => ['values' => ['Small', 'Black']]]);

        Livewire::test(UrlsTableWidget::class, ['record' => $this->product])
            ->assertSee('Small / Black');
    }

    public function test_show_page_widget_sets_and_clears_the_variant()
    {
        Livewire::test(ProductUrlStats::class, ['record' => $this->product])
            ->callAction('edit', data: [
                'url' => self::URL,
                'price_factor' => 1,
                'variant' => ['values' => ['Large'], 'id' => 'TEST100_Large'],
            ], arguments: ['url' => $this->url->getKey()])
            ->assertHasNoActionErrors();

        $this->assertSame(['values' => ['Large'], 'id' => 'TEST100_Large'], $this->url->fresh()->getVariantFilter()->toArray());

        Livewire::test(ProductUrlStats::class, ['record' => $this->product->fresh()])
            ->assertSee('Large')
            ->callAction('edit', data: [
                'url' => self::URL,
                'price_factor' => 1,
                'variant' => ['values' => []],
            ], arguments: ['url' => $this->url->getKey()])
            ->assertHasNoActionErrors();

        $this->assertNull($this->url->fresh()->variant);
    }

    public function test_view_page_add_url_action_sets_the_variant()
    {
        $this->url->delete();

        Livewire::test(ViewProduct::class, ['record' => $this->product->getRouteKey()])
            ->callAction('add_url', data: [
                'url' => self::URL,
                'price_factor' => 1,
                'variant' => ['values' => ['Medium']],
            ])
            ->assertHasNoActionErrors();

        /** @var Url $url */
        $url = $this->product->urls()->first();
        $this->assertSame(['values' => ['Medium']], $url->variant);
        $this->assertSame(StockStatus::OutOfStock, $url->availability);
    }

    public function test_create_page_sets_the_variant()
    {
        Livewire::test(CreateProduct::class)
            ->fillForm([
                'url' => 'https://example.com/other-shorts',
                'create_store' => false,
                'price_factor' => 1,
                'variant' => ['values' => ['X Small']],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        /** @var Url $url */
        $url = Url::query()->where('url', 'https://example.com/other-shorts')->firstOrFail();
        $this->assertSame(['values' => ['X Small']], $url->variant);
        $this->assertNull($url->availability);
    }

    public function test_detect_lists_the_page_variants_for_picking()
    {
        $options = VariantFilterFields::detect(self::URL);

        $this->assertSame(['X Small', 'Small', 'Medium', 'Large'], array_column($options, 'label'));
        $this->assertSame(['Small'], $options[1]['options']);
        $this->assertSame('TEST100_Small', $options[1]['id']);
        $this->assertSame('Small · Out of Stock · 30', $options[1]['display']);
    }

    public function test_detect_returns_null_without_a_store()
    {
        $this->assertNull(VariantFilterFields::detect('https://unknown-store.test/product'));
    }

    public function test_store_test_modal_lists_variants()
    {
        $component = Livewire::test(EditStore::class, ['record' => $this->store->getKey()])
            ->mountAction('test')
            ->call('runScrape', self::URL)
            ->assertSee('Variants (4)')
            ->assertSee('TEST100_Medium');

        $this->assertSame(
            ['X Small', 'Small', 'Medium', 'Large'],
            collect(data_get($component->get('testScrapeResult'), 'variants'))->pluck('label')->all(),
        );
    }
}

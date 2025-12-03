<?php

namespace Tests\Feature\Filament;

use App\Enums\ProductSourceStatus;
use App\Enums\ProductSourceType;
use App\Filament\Resources\ProductSourceResource;
use App\Models\ProductSource;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProductSourceResourceTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    public function test_can_render_product_source_index_page()
    {
        $this->actingAs($this->user);
        $this->get(ProductSourceResource::getUrl('index'))->assertSuccessful();
    }

    public function test_can_render_create_product_source_page()
    {
        $this->actingAs($this->user);
        $this->get(ProductSourceResource::getUrl('create'))->assertSuccessful();
    }

    public function test_can_create_deals_site_product_source()
    {
        $this->actingAs($this->user);

        $newData = [
            'name' => 'Test Deals Site',
            'type' => ProductSourceType::DealsSite->value,
            'status' => ProductSourceStatus::Active->value,
            'search_url' => 'https://example.com/search?q=:search_term',
            'extraction_strategy' => [
                'list_container' => [
                    'type' => 'selector',
                    'value' => '.item',
                ],
                'product_title' => [
                    'type' => 'selector',
                    'value' => 'h2',
                ],
                'product_url' => [
                    'type' => 'selector',
                    'value' => 'a|href',
                ],
            ],
        ];

        Livewire::test(ProductSourceResource\Pages\CreateProductSource::class)
            ->fillForm($newData)
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('product_sources', [
            'name' => 'Test Deals Site',
            'type' => ProductSourceType::DealsSite->value,
        ]);
    }

    public function test_can_create_online_store_product_source_with_store()
    {
        $this->actingAs($this->user);
        $store = Store::factory()->create();

        $newData = [
            'name' => 'Test Online Store',
            'type' => ProductSourceType::OnlineStore->value,
            'store_id' => $store->id,
            'status' => ProductSourceStatus::Active->value,
            'search_url' => 'https://example.com/search?q=:search_term',
            'extraction_strategy' => [
                'list_container' => [
                    'type' => 'selector',
                    'value' => '.item',
                ],
                'product_title' => [
                    'type' => 'selector',
                    'value' => 'h2',
                ],
                'product_url' => [
                    'type' => 'selector',
                    'value' => 'a|href',
                ],
            ],
        ];

        Livewire::test(ProductSourceResource\Pages\CreateProductSource::class)
            ->fillForm($newData)
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('product_sources', [
            'name' => 'Test Online Store',
            'type' => ProductSourceType::OnlineStore->value,
            'store_id' => $store->id,
        ]);
    }

    public function test_validates_search_url_contains_placeholder()
    {
        $this->actingAs($this->user);

        $newData = [
            'name' => 'Test Source',
            'type' => ProductSourceType::DealsSite->value,
            'status' => ProductSourceStatus::Active->value,
            'search_url' => 'https://example.com/search?q=test',
            'extraction_strategy' => [
                'list_container' => [
                    'type' => 'selector',
                    'value' => '.item',
                ],
                'product_title' => [
                    'type' => 'selector',
                    'value' => 'h2',
                ],
                'product_url' => [
                    'type' => 'selector',
                    'value' => 'a|href',
                ],
            ],
        ];

        Livewire::test(ProductSourceResource\Pages\CreateProductSource::class)
            ->fillForm($newData)
            ->call('create')
            ->assertHasFormErrors(['search_url']);
    }

    public function test_can_edit_product_source()
    {
        $this->actingAs($this->user);
        $source = ProductSource::factory()->create();

        $params = ['record' => $source->getRouteKey()];

        Livewire::test(ProductSourceResource\Pages\EditProductSource::class, $params)
            ->fillForm([
                'name' => 'Updated Name',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Updated Name', $source->refresh()->name);
    }

    public function test_can_delete_product_source()
    {
        $this->actingAs($this->user);
        $source = ProductSource::factory()->create();

        $params = ['record' => $source->getRouteKey()];

        Livewire::test(ProductSourceResource\Pages\EditProductSource::class, $params)
            ->callAction('delete');

        $this->assertDatabaseMissing('product_sources', ['id' => $source->id]);
    }

    public function test_can_filter_by_status()
    {
        $this->actingAs($this->user);
        $activeSource = ProductSource::factory()->create([
            'status' => ProductSourceStatus::Active,
        ]);
        $inactiveSource = ProductSource::factory()->create([
            'status' => ProductSourceStatus::Inactive,
        ]);

        Livewire::test(ProductSourceResource\Pages\ListProductSources::class)
            ->assertCanSeeTableRecords([$activeSource, $inactiveSource])
            ->filterTable('status', ProductSourceStatus::Active->value)
            ->assertCanSeeTableRecords([$activeSource])
            ->assertCanNotSeeTableRecords([$inactiveSource]);
    }

    public function test_can_filter_by_type()
    {
        $this->actingAs($this->user);
        $dealsSource = ProductSource::factory()->dealsSite()->create();
        $storeSource = ProductSource::factory()->onlineStore()->create();

        Livewire::test(ProductSourceResource\Pages\ListProductSources::class)
            ->assertCanSeeTableRecords([$dealsSource, $storeSource])
            ->filterTable('type', ProductSourceType::DealsSite->value)
            ->assertCanSeeTableRecords([$dealsSource])
            ->assertCanNotSeeTableRecords([$storeSource]);
    }

    public function test_can_search_by_name()
    {
        $this->actingAs($this->user);
        $source1 = ProductSource::factory()->create([
            'name' => 'Amazon Store',
        ]);
        $source2 = ProductSource::factory()->create([
            'name' => 'eBay Deals',
        ]);

        Livewire::test(ProductSourceResource\Pages\ListProductSources::class)
            ->searchTable('Amazon')
            ->assertCanSeeTableRecords([$source1])
            ->assertCanNotSeeTableRecords([$source2]);
    }

    public function test_can_search_by_slug()
    {
        $this->actingAs($this->user);
        $source1 = ProductSource::factory()->create([
            'name' => 'Test Store',
        ]);
        $source2 = ProductSource::factory()->create([
            'name' => 'Another Store',
        ]);

        Livewire::test(ProductSourceResource\Pages\ListProductSources::class)
            ->searchTable('test-store')
            ->assertCanSeeTableRecords([$source1])
            ->assertCanNotSeeTableRecords([$source2]);
    }

    public function test_can_sort_by_name()
    {
        $this->actingAs($this->user);
        $sourceA = ProductSource::factory()->create([
            'name' => 'A Store',
        ]);
        $sourceZ = ProductSource::factory()->create([
            'name' => 'Z Store',
        ]);

        Livewire::test(ProductSourceResource\Pages\ListProductSources::class)
            ->sortTable('name')
            ->assertCanSeeTableRecords([$sourceA, $sourceZ], inOrder: true)
            ->sortTable('name', 'desc')
            ->assertCanSeeTableRecords([$sourceZ, $sourceA], inOrder: true);
    }

    public function test_can_bulk_delete_product_sources()
    {
        $this->actingAs($this->user);
        $sources = ProductSource::factory()->count(3)->create();

        Livewire::test(ProductSourceResource\Pages\ListProductSources::class)
            ->callTableBulkAction('delete', $sources);

        foreach ($sources as $source) {
            $this->assertDatabaseMissing('product_sources', ['id' => $source->id]);
        }
    }

    public function test_displays_table_columns_correctly()
    {
        $this->actingAs($this->user);
        $store = Store::factory()->create(['name' => 'Test Store']);
        $source = ProductSource::factory()->create([
            'name' => 'Test Source',
            'slug' => 'test-source',
            'type' => ProductSourceType::OnlineStore,
            'store_id' => $store->id,
            'status' => ProductSourceStatus::Active,
        ]);

        Livewire::test(ProductSourceResource\Pages\ListProductSources::class)
            ->assertCanSeeTableRecords([$source])
            ->assertTableColumnExists('name')
            ->assertTableColumnExists('slug')
            ->assertTableColumnExists('type')
            ->assertTableColumnExists('store.name')
            ->assertTableColumnExists('status');
    }
}

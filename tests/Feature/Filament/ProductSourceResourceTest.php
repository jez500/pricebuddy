<?php

namespace Tests\Feature\Filament;

use App\Enums\ProductSourceStatus;
use App\Enums\ProductSourceType;
use App\Filament\Resources\ProductSourceResource;
use App\Models\ProductSource;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\View;
use Livewire\Livewire;
use Tests\TestCase;

class ProductSourceResourceTest extends TestCase
{
    use RefreshDatabase;

    protected ?User $user;

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
        $source = ProductSource::factory()->user($this->user)->create();

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
        $source = ProductSource::factory()->user($this->user)->create();

        $params = ['record' => $source->getRouteKey()];

        Livewire::test(ProductSourceResource\Pages\EditProductSource::class, $params)
            ->callAction('delete');

        $this->assertDatabaseMissing('product_sources', ['id' => $source->id]);
    }

    public function test_can_filter_by_status()
    {
        $this->actingAs($this->user);
        $activeSource = ProductSource::factory()->user($this->user)->create([
            'status' => ProductSourceStatus::Active,
        ]);
        $inactiveSource = ProductSource::factory()->user($this->user)->create([
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
        $dealsSource = ProductSource::factory()->dealsSite()->user($this->user)->create();
        $storeSource = ProductSource::factory()->onlineStore()->user($this->user)->create();

        Livewire::test(ProductSourceResource\Pages\ListProductSources::class)
            ->assertCanSeeTableRecords([$dealsSource, $storeSource])
            ->filterTable('type', ProductSourceType::DealsSite->value)
            ->assertCanSeeTableRecords([$dealsSource])
            ->assertCanNotSeeTableRecords([$storeSource]);
    }

    public function test_can_search_by_name()
    {
        $this->actingAs($this->user);
        $source1 = ProductSource::factory()->user($this->user)->create([
            'name' => 'Amazon Store',
        ]);
        $source2 = ProductSource::factory()->user($this->user)->create([
            'name' => 'eBay Deals',
        ]);

        Livewire::test(ProductSourceResource\Pages\ListProductSources::class)
            ->searchTable('Amazon')
            ->assertCanSeeTableRecords([$source1])
            ->assertCanNotSeeTableRecords([$source2]);
    }

    public function test_can_sort_by_name()
    {
        $this->actingAs($this->user);
        $sourceA = ProductSource::factory()->user($this->user)->create([
            'name' => 'A Store',
        ]);
        $sourceZ = ProductSource::factory()->user($this->user)->create([
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
        $sources = ProductSource::factory()->count(3)->user($this->user)->create();

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
        $source = ProductSource::factory()->user($this->user)->create([
            'name' => 'Test Source',
            'slug' => 'test-source',
            'type' => ProductSourceType::OnlineStore,
            'store_id' => $store->id,
            'status' => ProductSourceStatus::Active,
        ]);

        Livewire::test(ProductSourceResource\Pages\ListProductSources::class)
            ->assertCanSeeTableRecords([$source])
            ->assertTableColumnExists('name')
            ->assertTableColumnExists('store.name')
            ->assertTableColumnExists('status')
            ->assertTableColumnExists('user.name');
    }

    public function test_can_render_search_page()
    {
        $this->actingAs($this->user);
        $source = ProductSource::factory()->user($this->user)->create();

        $this->get(ProductSourceResource::getUrl('search', ['record' => $source]))
            ->assertSuccessful();
    }

    public function test_can_submit_search_query()
    {
        $this->actingAs($this->user);
        $source = $this->createProductSource();

        Livewire::test(ProductSourceResource\Pages\SearchProductSource::class, ['record' => $source->getRouteKey()])
            ->fillForm([
                'search_query' => 'laptop',
            ])
            ->call('save')
            ->assertHasNoFormErrors();
    }

    public function test_table_has_search_action()
    {
        $this->actingAs($this->user);
        $this->createProductSource();

        Livewire::test(ProductSourceResource\Pages\ListProductSources::class)
            ->assertTableActionExists('search');
    }

    public function test_search_query_is_set_from_route_parameter()
    {
        $this->actingAs($this->user);
        $source = $this->createProductSource();

        $response = $this->get(ProductSourceResource::getUrl('search', ['record' => $source, 'search' => 'laptop']));

        $response->assertSuccessful()
            ->assertSeeLivewire(ProductSourceResource\Pages\SearchProductSource::class);
    }

    public function test_show_log_is_false_when_no_search_query()
    {
        $this->actingAs($this->user);
        $source = ProductSource::factory()->user($this->user)->create();

        Livewire::test(ProductSourceResource\Pages\SearchProductSource::class, ['record' => $source->getRouteKey()])
            ->assertSet('showLog', false)
            ->assertSet('searchQuery', null);
    }

    public function test_cannot_view_other_users_product_source()
    {
        $this->actingAs($this->user);
        $otherUser = User::factory()->create();
        $source = ProductSource::factory()->user($otherUser)->create();

        $this->get(ProductSourceResource::getUrl('edit', ['record' => $source]))
            ->assertForbidden();
    }

    public function test_cannot_edit_other_users_product_source()
    {
        $this->actingAs($this->user);
        $otherUser = User::factory()->create();
        $source = ProductSource::factory()->user($otherUser)->create();

        $params = ['record' => $source->getRouteKey()];

        Livewire::test(ProductSourceResource\Pages\EditProductSource::class, $params)
            ->assertForbidden();
    }

    public function test_cannot_delete_other_users_product_source()
    {
        $this->actingAs($this->user);
        $otherUser = User::factory()->create();
        $source = ProductSource::factory()->user($otherUser)->create();

        $params = ['record' => $source->getRouteKey()];

        Livewire::test(ProductSourceResource\Pages\EditProductSource::class, $params)
            ->assertForbidden();

        $this->assertDatabaseHas('product_sources', ['id' => $source->id]);
    }

    public function test_cannot_access_other_users_search_page()
    {
        $this->actingAs($this->user);
        $otherUser = User::factory()->create();
        $source = ProductSource::factory()->user($otherUser)->create();

        $this->get(ProductSourceResource::getUrl('search', ['record' => $source]))
            ->assertForbidden();
    }

    public function test_list_page_only_shows_own_product_sources()
    {
        $this->actingAs($this->user);
        $otherUser = User::factory()->create();

        $ownSource = ProductSource::factory()->user($this->user)->create();
        $otherSource = ProductSource::factory()->user($otherUser)->create();

        Livewire::test(ProductSourceResource\Pages\ListProductSources::class)
            ->assertCanSeeTableRecords([$ownSource])
            ->assertCanNotSeeTableRecords([$otherSource]);
    }

    protected function createProductSource(string $domain = 'example.com', array $attributes = []): ProductSource
    {
        Http::fake([
            $domain.'/*' => Http::response(
                View::make('tests.product-source-search-page', [
                    'products' => [
                        ['title' => 'Laptop1', 'url' => 'https://'.$domain.'/laptop1'],
                        ['title' => 'Laptop2', 'url' => 'https://'.$domain.'/laptop2'],
                    ],
                ])->render()
            ),
        ]);

        if (empty($attributes['user_id']) && $this->user) {
            $attributes['user_id'] = $this->user->getKey();
        }

        return ProductSource::factory()->create(array_merge([
            'search_url' => 'https://'.$domain.'/search?q=:search_term',
        ], $attributes));
    }
}

<?php

use App\Enums\ProductSourceStatus;
use App\Enums\ProductSourceType;
use App\Filament\Resources\ProductSourceResource;
use App\Models\ProductSource;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Livewire\livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('can render product source index page', function () {
    $this->get(ProductSourceResource::getUrl('index'))->assertSuccessful();
});

it('can render create product source page', function () {
    $this->get(ProductSourceResource::getUrl('create'))->assertSuccessful();
});

it('can create deals_site product source', function () {
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

    livewire(ProductSourceResource\Pages\CreateProductSource::class)
        ->fillForm($newData)
        ->call('create')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas(ProductSource::class, [
        'name' => 'Test Deals Site',
        'type' => ProductSourceType::DealsSite->value,
    ]);
});

it('can create online_store product source with store', function () {
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

    livewire(ProductSourceResource\Pages\CreateProductSource::class)
        ->fillForm($newData)
        ->call('create')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas(ProductSource::class, [
        'name' => 'Test Online Store',
        'type' => ProductSourceType::OnlineStore->value,
        'store_id' => $store->id,
    ]);
});

it('validates search_url contains placeholder', function () {
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

    livewire(ProductSourceResource\Pages\CreateProductSource::class)
        ->fillForm($newData)
        ->call('create')
        ->assertHasFormErrors(['search_url']);
});

it('can edit product source', function () {
    $source = ProductSource::factory()->create();

    livewire(ProductSourceResource\Pages\EditProductSource::class, [
        'record' => $source->getRouteKey(),
    ])
        ->fillForm([
            'name' => 'Updated Name',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($source->refresh()->name)->toBe('Updated Name');
});

it('can delete product source', function () {
    $source = ProductSource::factory()->create();

    livewire(ProductSourceResource\Pages\EditProductSource::class, [
        'record' => $source->getRouteKey(),
    ])
        ->callAction('delete');

    $this->assertModelMissing($source);
});

it('can filter by status', function () {
    $activeSource = ProductSource::factory()->create(['status' => ProductSourceStatus::Active]);
    $inactiveSource = ProductSource::factory()->create(['status' => ProductSourceStatus::Inactive]);

    livewire(ProductSourceResource\Pages\ListProductSources::class)
        ->assertCanSeeTableRecords([$activeSource, $inactiveSource])
        ->filterTable('status', ProductSourceStatus::Active->value)
        ->assertCanSeeTableRecords([$activeSource])
        ->assertCanNotSeeTableRecords([$inactiveSource]);
});

it('can filter by type', function () {
    $dealsSource = ProductSource::factory()->dealsSite()->create();
    $storeSource = ProductSource::factory()->onlineStore()->create();

    livewire(ProductSourceResource\Pages\ListProductSources::class)
        ->assertCanSeeTableRecords([$dealsSource, $storeSource])
        ->filterTable('type', ProductSourceType::DealsSite->value)
        ->assertCanSeeTableRecords([$dealsSource])
        ->assertCanNotSeeTableRecords([$storeSource]);
});

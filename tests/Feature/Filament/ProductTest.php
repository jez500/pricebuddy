<?php

namespace Tests\Feature\Filament;

use App\Enums\Statuses;
use App\Filament\Resources\ProductResource;
use App\Filament\Resources\ProductResource\Pages\CreateProduct;
use App\Models\Product;
use App\Models\Store;
use App\Models\Url;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;
use Tests\Traits\ScraperTrait;

class ProductTest extends TestCase
{
    use RefreshDatabase;
    use ScraperTrait;

    protected function setUp(): void
    {
        parent::setUp();

        User::query()->delete();

        $this->user = User::factory()->create([
            'name' => 'Tester',
            'email' => 'tester@test.com',
            'password' => Hash::make('password'),
        ]);
    }

    public function test_product_index()
    {
        $this->actingAs($this->user);

        $this->get(ProductResource::getUrl('index'))->assertOk();
    }

    public function test_global_search_result_links_to_the_view_page(): void
    {
        $this->actingAs($this->user);

        $product = Product::factory()->create(['user_id' => $this->user->id]);

        $this->assertSame(
            ProductResource::getUrl('view', ['record' => $product]),
            ProductResource::getGlobalSearchResultUrl($product),
        );
    }

    public function test_edit_product()
    {
        $product = Product::factory()->create([
            'title' => 'My product',
            'image' => 'https://example.com/image.jpg',
            'status' => Statuses::Archived->value,
            'notify_price' => 100,
            'notify_percent' => 10,
            'user_id' => $this->user->getKey(),
        ]);

        $this->actingAs($this->user);
        $params = ['record' => $product->getKey()];

        $this->get(ProductResource::getUrl('edit', $params))->assertOk();

        Livewire::test(ProductResource\Pages\EditProduct::class, $params)
            ->fillForm([
                'title' => 'My new product',
                'image' => 'https://example.com/image-1.jpg',
                'status' => Statuses::Published->value,
                'notify_price' => 80,
                'notify_percent' => 5,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $product->refresh();

        $this->assertSame('My new product', $product->title);
        $this->assertSame('https://example.com/image-1.jpg', $product->image);
        $this->assertSame(Statuses::Published, $product->status);
        $this->assertSame(80.0, $product->notify_price);
        $this->assertSame(5.0, $product->notify_percent);
    }

    public function test_url_existing_create()
    {
        $this->actingAs($this->user);

        Url::query()->delete();
        Product::query()->delete();
        Store::query()->delete();

        $product = Product::factory()->create([
            'title' => 'Existing product',
            'image' => 'https://example.com/image.jpg',
            'status' => Statuses::Published->value,
            'notify_price' => 100,
            'notify_percent' => 10,
            'user_id' => $this->user->getKey(),
        ]);

        $store = Store::factory()->createOne([
            'domains' => [['domain' => 'example.com']],
        ]);

        $url = 'https://example.com/product/create_existing';

        $this->mockScrape('$500.01', 'Test product', 'https://example.com/image.jpg');

        $this->get(ProductResource::getUrl('create'))->assertOk();

        Livewire::test(CreateProduct::class)
            ->fillForm([
                'product_id' => $product->getKey(),
                'url' => $url,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        /** @var Url $urlModel */
        $urlModel = Url::where('url', $url)->first();

        $product->refresh();

        $this->assertSame($store->getKey(), $urlModel->store_id);
        $this->assertSame($product->getKey(), $urlModel->product_id);
        $this->assertSame('https://example.com/image.jpg', $product->image);
        $this->assertSame(Statuses::Published, $product->status);

        $scrapedCache = $product->getPriceCache();
        $this->assertSame(500.01, $scrapedCache->first()->getPrice());
        $this->assertSame($url, $scrapedCache->first()->getUrl());
    }

    public function test_url_new_product_create()
    {
        $this->actingAs($this->user);

        Url::query()->delete();
        Product::query()->delete();
        Store::query()->delete();

        $store = Store::factory()->createOne([
            'domains' => [['domain' => 'example.com']],
        ]);

        $url = 'https://example.com/product/create';

        $this->mockScrape('$800.01', 'Test product 1', 'https://example.com/image.jpg');

        $this->get(ProductResource::getUrl('create'))->assertOk();

        Livewire::test(CreateProduct::class)
            ->fillForm([
                'url' => $url,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        /** @var Product $product */
        $product = Product::where('title', 'Test product 1')->first();

        /** @var Url $urlModel */
        $urlModel = Url::where('url', $url)->first();

        $this->assertSame($store->getKey(), $urlModel->store_id);
        $this->assertSame($product->getKey(), $urlModel->product_id);
        $this->assertSame('Test product 1', $product->title);
        $this->assertSame('https://example.com/image.jpg', $product->image);
        $this->assertSame(Statuses::Published, $product->status);

        $scrapedCache = $product->getPriceCache();
        $this->assertSame(800.01, $scrapedCache->first()->getPrice());
        $this->assertSame($url, $scrapedCache->first()->getUrl());
    }
}

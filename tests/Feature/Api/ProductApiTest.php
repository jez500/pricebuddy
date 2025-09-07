<?php

namespace Tests\Feature\Api;

use App\Enums\Statuses;
use App\Models\Product;
use App\Models\Store;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\ScraperTrait;

class ProductApiTest extends TestCase
{
    use RefreshDatabase;
    use ScraperTrait;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $token = $this->user->createToken('test-token')->plainTextToken;
        $this->withHeaders(['Authorization' => 'Bearer '.$token]);

        // Create a store for URL scraping
        Store::factory()->create([
            'domains' => [['domain' => 'example.com']],
        ]);
    }

    public function test_can_list_products(): void
    {
        $products = Product::factory()->count(3)->create(['user_id' => $this->user->id]);
        Product::factory()->count(2)->create(); // Other users' products

        $response = $this->getJson('/api/products');

        $response->assertSuccessful()
            ->assertJsonCount(3, 'data')
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'title',
                        'image',
                        'status',
                        'notify_price',
                        'notify_percent',
                        'favourite',
                        'only_official',
                        'created_at',
                        'updated_at',
                    ],
                ],
                'links',
                'meta',
            ]);
    }

    public function test_can_show_single_product(): void
    {
        $product = Product::factory()->create(['user_id' => $this->user->id]);

        $response = $this->getJson("/api/products/{$product->id}");

        $response->assertSuccessful()
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'title',
                    'image',
                    'status',
                    'notify_price',
                    'notify_percent',
                    'favourite',
                    'only_official',
                    'created_at',
                    'updated_at',
                ],
            ])
            ->assertJson([
                'data' => [
                    'id' => $product->id,
                    'title' => $product->title,
                ],
            ]);
    }

    public function test_cannot_show_other_users_product(): void
    {
        $otherUser = User::factory()->create();
        $product = Product::factory()->create(['user_id' => $otherUser->id]);

        $response = $this->getJson("/api/products/{$product->id}");

        $response->assertNotFound();
    }

    public function test_can_create_product(): void
    {
        $this->mockScrape('$99.99', 'Test Product', 'https://example.com/image.jpg');

        $productData = [
            'title' => 'Test Product',
            'url' => 'https://example.com/test-product',
        ];

        $response = $this->postJson('/api/products', $productData);

        $response->assertCreated()
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'title',
                    'image',
                    'status',
                    'notify_price',
                    'notify_percent',
                    'favourite',
                    'only_official',
                    'created_at',
                    'updated_at',
                ],
                'message',
            ])
            ->assertJson([
                'data' => [
                    'title' => 'Test Product',
                ],
                'message' => 'Product created',
            ]);

        $this->assertDatabaseHas('products', [
            'title' => 'Test Product',
            'user_id' => $this->user->id,
        ]);
    }

    public function test_cannot_spoof_user_id_when_creating_product(): void
    {
        $this->mockScrape('$99.99', 'Test Product', 'https://example.com/image.jpg');

        $otherUser = User::factory()->create();

        $productData = [
            'title' => 'Test Product',
            'url' => 'https://example.com/test-product',
            'user_id' => $otherUser->id, // Attempting to spoof ownership
        ];

        $response = $this->postJson('/api/products', $productData);

        $response->assertCreated();

        // The product should be created but with the authenticated user's ID, not the spoofed one
        $this->assertDatabaseHas('products', [
            'title' => 'Test Product',
            'user_id' => $this->user->id, // Should be the authenticated user, not the other user
        ]);

        $this->assertDatabaseMissing('products', [
            'title' => 'Test Product',
            'user_id' => $otherUser->id, // Should not be created with the spoofed user_id
        ]);
    }

    public function test_create_product_validation_fails_with_invalid_data(): void
    {
        $invalidData = [
            'title' => '', // Required field empty
            'notify_price' => -10, // Invalid negative price
        ];

        $response = $this->postJson('/api/products', $invalidData);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['title']);
    }

    public function test_can_update_product(): void
    {
        $product = Product::factory()->create(['user_id' => $this->user->id]);

        $updateData = [
            'title' => 'Updated Product Title',
            'image' => $product->image,
            'status' => Statuses::Published->value,
            'notify_price' => 150.00,
            'notify_percent' => $product->notify_percent,
            'favourite' => false,
            'only_official' => $product->only_official,
            'weight' => $product->weight ?? 100.0,
            'current_price' => $product->current_price ?? 150.00,
            'price_cache' => $product->price_cache ?: [['price' => 150.00, 'date' => now()->toDateString()]],
            'ignored_urls' => $product->ignored_urls ?: ['https://example.com/ignored'],
            'user_id' => $this->user->id,
        ];

        $response = $this->putJson("/api/products/{$product->id}", $updateData);

        $response->assertSuccessful()
            ->assertJson([
                'data' => [
                    'id' => $product->id,
                    'title' => 'Updated Product Title',
                    'notify_price' => 150.00,
                    'favourite' => false,
                ],
            ]);

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'title' => 'Updated Product Title',
            'notify_price' => 150.00,
        ]);
    }

    public function test_cannot_update_other_users_product(): void
    {
        $otherUser = User::factory()->create();
        $product = Product::factory()->create(['user_id' => $otherUser->id]);

        $updateData = [
            'title' => 'Hacked Product Title',
            'image' => $product->image,
            'status' => Statuses::Published->value,
            'notify_price' => 150.00,
            'notify_percent' => $product->notify_percent,
            'favourite' => false,
            'only_official' => $product->only_official,
            'weight' => $product->weight ?? 100.0,
            'current_price' => $product->current_price ?? 150.00,
            'price_cache' => $product->price_cache ?: [['price' => 150.00, 'date' => now()->toDateString()]],
            'ignored_urls' => $product->ignored_urls ?: ['https://example.com/ignored'],
            'user_id' => $this->user->id,
        ];

        $response = $this->putJson("/api/products/{$product->id}", $updateData);

        $response->assertNotFound();
    }

    public function test_can_delete_product(): void
    {
        $product = Product::factory()->create(['user_id' => $this->user->id]);

        $response = $this->deleteJson("/api/products/{$product->id}");

        $response->assertNoContent();
        $this->assertDatabaseMissing('products', ['id' => $product->id]);
    }

    public function test_cannot_delete_other_users_product(): void
    {
        $otherUser = User::factory()->create();
        $product = Product::factory()->create(['user_id' => $otherUser->id]);

        $response = $this->deleteJson("/api/products/{$product->id}");

        $response->assertNotFound();
        $this->assertDatabaseHas('products', ['id' => $product->id]);
    }

    public function test_can_filter_products_by_status(): void
    {
        Product::factory()->create([
            'user_id' => $this->user->id,
            'status' => Statuses::Published->value,
        ]);
        Product::factory()->create([
            'user_id' => $this->user->id,
            'status' => Statuses::Archived->value,
        ]);

        $response = $this->getJson('/api/products?filter[status]='.Statuses::Published->value);

        $response->assertSuccessful()
            ->assertJsonCount(1, 'data');
    }

    public function test_can_filter_products_by_favourite(): void
    {
        Product::factory()->create([
            'user_id' => $this->user->id,
            'favourite' => true,
        ]);
        Product::factory()->create([
            'user_id' => $this->user->id,
            'favourite' => false,
        ]);

        $response = $this->getJson('/api/products?filter[favourite]=true');

        $response->assertSuccessful()
            ->assertJsonCount(1, 'data');
    }

    public function test_can_sort_products(): void
    {
        Product::factory()->create([
            'user_id' => $this->user->id,
            'title' => 'B Product',
            'created_at' => now()->subDay(),
        ]);
        Product::factory()->create([
            'user_id' => $this->user->id,
            'title' => 'A Product',
            'created_at' => now(),
        ]);

        $response = $this->getJson('/api/products?sort=title');

        $response->assertSuccessful();
        $data = $response->json('data');
        $this->assertEquals('A Product', $data[0]['title']);
        $this->assertEquals('B Product', $data[1]['title']);
    }

    public function test_can_include_relationships(): void
    {
        $product = Product::factory()->create(['user_id' => $this->user->id]);
        $tag = Tag::factory()->create();
        $product->tags()->attach($tag);

        $response = $this->getJson("/api/products/{$product->id}?include=tags,user");

        $response->assertSuccessful()
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'title',
                    'tags',
                    'user',
                ],
            ]);
    }

    public function test_pagination_works(): void
    {
        Product::factory()->count(25)->create(['user_id' => $this->user->id]);

        $response = $this->getJson('/api/products?per_page=10');

        $response->assertSuccessful()
            ->assertJsonCount(10, 'data')
            ->assertJsonStructure([
                'data',
                'links' => [
                    'first',
                    'last',
                    'prev',
                    'next',
                ],
                'meta' => [
                    'current_page',
                    'last_page',
                    'per_page',
                    'total',
                ],
            ]);
    }

    public function test_requires_authentication(): void
    {
        $this->withHeaders(['Authorization' => '']);

        $response = $this->getJson('/api/products');

        $response->assertUnauthorized();
    }
}

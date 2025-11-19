<?php

namespace Tests\Feature\Api;

use App\Models\Product;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TagApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $token = $this->user->createToken('test-token')->plainTextToken;
        $this->withHeaders(['Authorization' => 'Bearer '.$token]);
    }

    public function test_can_list_tags(): void
    {
        $tags = Tag::factory()->count(3)->create(['user_id' => $this->user->id]);
        Tag::factory()->count(2)->create(); // Other users' tags

        $response = $this->getJson('/api/tags');

        $response->assertSuccessful()
            ->assertJsonCount(3, 'data')
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'name',
                        'user_id',
                        'created_at',
                        'updated_at',
                    ],
                ],
                'links',
                'meta',
            ]);
    }

    public function test_can_show_single_tag(): void
    {
        $tag = Tag::factory()->create(['user_id' => $this->user->id]);

        $response = $this->getJson("/api/tags/{$tag->id}");

        $response->assertSuccessful()
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'name',
                    'user_id',
                    'created_at',
                    'updated_at',
                ],
            ])
            ->assertJson([
                'data' => [
                    'id' => $tag->id,
                    'name' => $tag->name,
                    'user_id' => $this->user->id,
                ],
            ]);
    }

    public function test_cannot_show_other_users_tag(): void
    {
        $otherUser = User::factory()->create();
        $tag = Tag::factory()->create(['user_id' => $otherUser->id]);

        $response = $this->getJson("/api/tags/{$tag->id}");

        $response->assertNotFound();
    }

    public function test_can_create_tag(): void
    {
        $tagData = [
            'name' => 'Electronics',
        ];

        $response = $this->postJson('/api/tags', $tagData);

        $response->assertCreated()
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'name',
                    'user_id',
                    'created_at',
                    'updated_at',
                ],
            ])
            ->assertJson([
                'data' => [
                    'name' => 'Electronics',
                    'user_id' => $this->user->id,
                ],
            ]);

        $this->assertDatabaseHas('tags', [
            'name' => 'Electronics',
            'user_id' => $this->user->id,
        ]);
    }

    public function test_create_tag_validation_fails_with_invalid_data(): void
    {
        $invalidData = [
            'name' => '', // Required field empty
        ];

        $response = $this->postJson('/api/tags', $invalidData);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    }

    public function test_cannot_create_duplicate_tag_name_for_user(): void
    {
        Tag::factory()->create([
            'name' => 'Electronics',
            'user_id' => $this->user->id,
        ]);

        $tagData = [
            'name' => 'Electronics',
        ];

        $response = $this->postJson('/api/tags', $tagData);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    }

    public function test_can_create_same_tag_name_for_different_users(): void
    {
        $otherUser = User::factory()->create();
        Tag::factory()->create([
            'name' => 'Electronics',
            'user_id' => $otherUser->id,
        ]);

        $tagData = [
            'name' => 'Electronics',
        ];

        $response = $this->postJson('/api/tags', $tagData);

        $response->assertCreated()
            ->assertJson([
                'data' => [
                    'name' => 'Electronics',
                    'user_id' => $this->user->id,
                ],
            ]);
    }

    public function test_can_update_tag(): void
    {
        $tag = Tag::factory()->create(['user_id' => $this->user->id]);

        $updateData = [
            'name' => 'Updated Tag Name',
        ];

        $response = $this->putJson("/api/tags/{$tag->id}", $updateData);

        $response->assertSuccessful()
            ->assertJson([
                'data' => [
                    'id' => $tag->id,
                    'name' => 'Updated Tag Name',
                    'user_id' => $this->user->id,
                ],
            ]);

        $this->assertDatabaseHas('tags', [
            'id' => $tag->id,
            'name' => 'Updated Tag Name',
        ]);
    }

    public function test_cannot_update_other_users_tag(): void
    {
        $otherUser = User::factory()->create();
        $tag = Tag::factory()->create(['user_id' => $otherUser->id]);

        $updateData = [
            'name' => 'Hacked Tag Name',
        ];

        $response = $this->putJson("/api/tags/{$tag->id}", $updateData);

        $response->assertNotFound();
    }

    public function test_can_delete_tag(): void
    {
        $tag = Tag::factory()->create(['user_id' => $this->user->id]);

        $response = $this->deleteJson("/api/tags/{$tag->id}");

        $response->assertNoContent();
        $this->assertDatabaseMissing('tags', ['id' => $tag->id]);
    }

    public function test_cannot_delete_other_users_tag(): void
    {
        $otherUser = User::factory()->create();
        $tag = Tag::factory()->create(['user_id' => $otherUser->id]);

        $response = $this->deleteJson("/api/tags/{$tag->id}");

        $response->assertNotFound();
        $this->assertDatabaseHas('tags', ['id' => $tag->id]);
    }

    public function test_can_sort_tags(): void
    {
        Tag::factory()->create([
            'user_id' => $this->user->id,
            'name' => 'Z Tag',
            'created_at' => now()->subDay(),
        ]);
        Tag::factory()->create([
            'user_id' => $this->user->id,
            'name' => 'A Tag',
            'created_at' => now(),
        ]);

        $response = $this->getJson('/api/tags?sort=name');

        $response->assertSuccessful();
        $data = $response->json('data');
        $this->assertEquals('A Tag', $data[0]['name']);
        $this->assertEquals('Z Tag', $data[1]['name']);
    }

    public function test_can_sort_tags_by_created_at(): void
    {
        Tag::factory()->create([
            'user_id' => $this->user->id,
            'name' => 'Older Tag',
            'created_at' => now()->subDay(),
        ]);
        Tag::factory()->create([
            'user_id' => $this->user->id,
            'name' => 'Newer Tag',
            'created_at' => now(),
        ]);

        $response = $this->getJson('/api/tags?sort=-created_at');

        $response->assertSuccessful();
        $data = $response->json('data');
        $this->assertEquals('Newer Tag', $data[0]['name']);
        $this->assertEquals('Older Tag', $data[1]['name']);
    }

    public function test_can_include_products_relationship(): void
    {
        $tag = Tag::factory()->create(['user_id' => $this->user->id]);
        $product = Product::factory()->create(['user_id' => $this->user->id]);
        $tag->products()->attach($product);

        $response = $this->getJson("/api/tags/{$tag->id}?include=products");

        $response->assertSuccessful()
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'name',
                    'products' => [
                        '*' => [
                            'id',
                            'title',
                        ],
                    ],
                ],
            ]);
    }

    public function test_pagination_works(): void
    {
        Tag::factory()->count(25)->create(['user_id' => $this->user->id]);

        $response = $this->getJson('/api/tags?per_page=10');

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

    public function test_can_search_tags_by_name(): void
    {
        Tag::factory()->create([
            'user_id' => $this->user->id,
            'name' => 'Electronics',
        ]);
        Tag::factory()->create([
            'user_id' => $this->user->id,
            'name' => 'Clothing',
        ]);

        $response = $this->getJson('/api/tags?search=Elect');

        $response->assertSuccessful()
            ->assertJsonCount(1, 'data');
    }

    public function test_can_filter_tags_by_name(): void
    {
        Tag::factory()->create([
            'user_id' => $this->user->id,
            'name' => 'Electronics',
        ]);
        Tag::factory()->create([
            'user_id' => $this->user->id,
            'name' => 'Clothing',
        ]);

        $response = $this->getJson('/api/tags?filter[name]=Electronics');

        $response->assertSuccessful()
            ->assertJsonCount(1, 'data')
            ->assertJson([
                'data' => [
                    [
                        'name' => 'Electronics',
                    ],
                ],
            ]);
    }

    public function test_can_get_tags_with_product_count(): void
    {
        $tag = Tag::factory()->create(['user_id' => $this->user->id]);
        $products = Product::factory()->count(3)->create(['user_id' => $this->user->id]);

        foreach ($products as $product) {
            $tag->products()->attach($product);
        }

        $response = $this->getJson("/api/tags/{$tag->id}?include=products");

        $response->assertSuccessful()
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'name',
                    'products',
                ],
            ]);

        $this->assertCount(3, $response->json('data.products'));
    }

    public function test_deleting_tag_removes_product_associations(): void
    {
        $tag = Tag::factory()->create(['user_id' => $this->user->id]);
        $product = Product::factory()->create(['user_id' => $this->user->id]);
        $tag->products()->attach($product);

        $this->assertDatabaseHas('taggables', [
            'tag_id' => $tag->id,
            'taggable_id' => $product->id,
            'taggable_type' => Product::class,
        ]);

        $response = $this->deleteJson("/api/tags/{$tag->id}");

        $response->assertNoContent();

        $this->assertDatabaseMissing('tags', ['id' => $tag->id]);
        $this->assertDatabaseMissing('taggables', [
            'tag_id' => $tag->id,
            'taggable_id' => $product->id,
        ]);
    }

    public function test_requires_authentication(): void
    {
        $this->withHeaders(['Authorization' => '']);

        $response = $this->getJson('/api/tags');

        $response->assertUnauthorized();
    }
}

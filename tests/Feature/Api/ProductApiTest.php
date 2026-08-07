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
        Store::query()->delete();
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

    public function test_can_pause_and_resume_product_via_api(): void
    {
        $product = Product::factory()->create(['user_id' => $this->user->id, 'paused' => false]);

        $this->putJson("/api/products/{$product->id}", [
            'title' => $product->title,
            'image' => $product->image,
            'paused' => true,
        ])->assertSuccessful()->assertJsonPath('data.paused', true);

        $this->assertDatabaseHas('products', ['id' => $product->id, 'paused' => true]);

        $this->putJson("/api/products/{$product->id}", [
            'title' => $product->title,
            'image' => $product->image,
            'paused' => false,
        ])->assertSuccessful()->assertJsonPath('data.paused', false);

        $this->assertDatabaseHas('products', ['id' => $product->id, 'paused' => false]);
    }

    public function test_can_set_refresh_interval_via_api(): void
    {
        $product = Product::factory()->create(['user_id' => $this->user->id, 'refresh_interval' => null]);

        $this->putJson("/api/products/{$product->id}", [
            'title' => $product->title,
            'image' => $product->image,
            'refresh_interval' => 3600,
        ])->assertSuccessful()->assertJsonPath('data.refresh_interval', 3600);

        $product->refresh();
        $this->assertSame(3600, $product->refresh_interval);
        $this->assertNotNull($product->next_check_at);
    }

    public function test_can_clear_refresh_interval_via_api(): void
    {
        $product = Product::factory()->create([
            'user_id' => $this->user->id,
            'refresh_interval' => 3600,
            'next_check_at' => now(),
        ]);

        $this->putJson("/api/products/{$product->id}", [
            'title' => $product->title,
            'image' => $product->image,
            'refresh_interval' => null,
        ])->assertSuccessful()->assertJsonPath('data.refresh_interval', null);

        $product->refresh();
        $this->assertNull($product->refresh_interval);
        $this->assertNull($product->next_check_at);
    }

    public function test_rejects_invalid_refresh_interval(): void
    {
        $product = Product::factory()->create(['user_id' => $this->user->id]);

        $this->putJson("/api/products/{$product->id}", [
            'title' => $product->title,
            'image' => $product->image,
            'refresh_interval' => 42,
        ])->assertStatus(422)->assertJsonValidationErrors(['refresh_interval']);
    }

    public function test_can_toggle_notify_in_stock_via_api(): void
    {
        $product = Product::factory()->create(['user_id' => $this->user->id, 'notify_in_stock' => false]);

        $this->putJson("/api/products/{$product->id}", [
            'title' => $product->title,
            'image' => $product->image,
            'notify_in_stock' => true,
        ])->assertSuccessful()->assertJsonPath('data.notify_in_stock', true);

        $this->assertDatabaseHas('products', ['id' => $product->id, 'notify_in_stock' => true]);
    }

    private function productWithInsights(): Product
    {
        return Product::factory()
            ->addUrlWithPrices('https://example.com/insight', [120, 110, 100, 95])
            ->create(['user_id' => $this->user->id]);
    }

    public function test_detail_includes_insights_when_requested(): void
    {
        $product = $this->productWithInsights();

        $this->getJson("/api/products/{$product->id}?include=insights")
            ->assertOk()
            ->assertJsonPath('data.insights.hasEnoughData', true)
            ->assertJsonStructure(['data' => ['insights' => [
                'dealScore' => ['verdict'],
                'stats' => ['lowest', 'highest'],
                'dailyBest',
            ]]]);
    }

    public function test_detail_excludes_insights_by_default(): void
    {
        $product = $this->productWithInsights();

        $this->getJson("/api/products/{$product->id}")
            ->assertOk()
            ->assertJsonMissingPath('data.insights');
    }

    public function test_detail_insights_falls_back_when_cache_null(): void
    {
        $product = $this->productWithInsights();
        $product->update(['insights_cache' => null]);

        $this->getJson("/api/products/{$product->id}?include=insights")
            ->assertOk()
            ->assertJsonPath('data.insights.hasEnoughData', true);
    }

    public function test_list_endpoint_ignores_insights_include(): void
    {
        $this->productWithInsights();

        $this->getJson('/api/products?include=insights')
            ->assertOk()
            ->assertJsonMissingPath('data.0.insights');
    }

    public function test_can_filter_products_by_url(): void
    {
        $product = Product::factory()->create(['user_id' => $this->user->id]);
        \App\Models\Url::factory()->create([
            'product_id' => $product->id,
            'url' => 'https://www.target.com.au/p/xbox-controller/',
        ]);
        Product::factory()->create(['user_id' => $this->user->id]);

        $response = $this->getJson('/api/products?filter[url]='.urlencode('https://target.com.au/p/Xbox-Controller?ref=nav'));

        $response->assertSuccessful()->assertJsonCount(1, 'data');
        $this->assertSame($product->id, $response->json('data.0.id'));
    }

    public function test_url_filter_distinguishes_significant_query_params(): void
    {
        $red = Product::factory()->create(['user_id' => $this->user->id]);
        $blue = Product::factory()->create(['user_id' => $this->user->id]);
        \App\Models\Url::factory()->create(['product_id' => $red->id, 'url' => 'https://shop.com/p/tee?variant=red']);
        \App\Models\Url::factory()->create(['product_id' => $blue->id, 'url' => 'https://shop.com/p/tee?variant=blue']);

        $response = $this->getJson('/api/products?filter[url]='.urlencode('https://shop.com/p/tee?variant=blue&utm_source=x'));

        $response->assertSuccessful()->assertJsonCount(1, 'data');
        $this->assertSame($blue->id, $response->json('data.0.id'));
    }

    public function test_url_filter_returns_empty_for_an_untracked_url(): void
    {
        Product::factory()->create(['user_id' => $this->user->id]);

        $this->getJson('/api/products?filter[url]='.urlencode('https://shop.com/never-tracked'))
            ->assertSuccessful()
            ->assertJsonCount(0, 'data');
    }

    public function test_url_filter_returns_empty_for_garbage_input(): void
    {
        Product::factory()->create(['user_id' => $this->user->id]);

        $this->getJson('/api/products?filter[url]=not%20a%20url')
            ->assertSuccessful()
            ->assertJsonCount(0, 'data');
    }

    public function test_url_filter_does_not_leak_another_users_product(): void
    {
        $other = User::factory()->create();
        $theirs = Product::factory()->create(['user_id' => $other->id]);
        \App\Models\Url::factory()->create(['product_id' => $theirs->id, 'url' => 'https://shop.com/p/shared']);

        $this->getJson('/api/products?filter[url]='.urlencode('https://shop.com/p/shared'))
            ->assertSuccessful()
            ->assertJsonCount(0, 'data');
    }

    public function test_url_filter_returns_every_matching_product(): void
    {
        $first = Product::factory()->create(['user_id' => $this->user->id]);
        $second = Product::factory()->create(['user_id' => $this->user->id]);
        \App\Models\Url::factory()->create(['product_id' => $first->id, 'url' => 'https://shop.com/p/dupe']);
        \App\Models\Url::factory()->create(['product_id' => $second->id, 'url' => 'https://www.shop.com/p/dupe/']);

        $this->getJson('/api/products?filter[url]='.urlencode('https://shop.com/p/dupe'))
            ->assertSuccessful()
            ->assertJsonCount(2, 'data');
    }

    public function test_url_filter_does_not_duplicate_a_product_with_two_matching_urls(): void
    {
        $product = Product::factory()->create(['user_id' => $this->user->id]);
        \App\Models\Url::factory()->create(['product_id' => $product->id, 'url' => 'https://shop.com/p/x']);
        \App\Models\Url::factory()->create(['product_id' => $product->id, 'url' => 'https://www.shop.com/p/x/?utm_source=a']);

        $this->getJson('/api/products?filter[url]='.urlencode('https://shop.com/p/x'))
            ->assertSuccessful()
            ->assertJsonCount(1, 'data');
    }

    public function test_url_filter_finds_an_affiliate_tagged_product_from_the_untagged_browser_url(): void
    {
        config()->set('affiliates.enabled', true);

        $product = Product::factory()->create(['user_id' => $this->user->id]);
        $url = \App\Models\Url::factory()->create([
            'product_id' => $product->id,
            'url' => 'https://www.ebay.com.au/itm/123456',
        ]);

        // buy_url carries six eBay affiliate params that are deliberately NOT on the
        // denylist. This pins that url_normalized derives from urls.url and never from
        // buy_url — if that ever flips, every eBay product silently stops matching.
        $this->assertStringContainsString('campid=', $url->buy_url);

        $this->getJson('/api/products?filter[url]='.urlencode('https://www.ebay.com.au/itm/123456'))
            ->assertSuccessful()
            ->assertJsonCount(1, 'data');
    }

    /**
     * Build a product with one URL and a materialised price cache.
     *
     * @return array{0: Product, 1: \App\Models\Url}
     */
    private function productWithTrackedUrl(string $url, ?User $owner = null): array
    {
        $product = Product::factory()->create(['user_id' => ($owner ?? $this->user)->id]);
        $urlModel = \App\Models\Url::factory()->withPrices([10.00])->create([
            'product_id' => $product->id,
            'url' => $url,
        ]);

        return [$product->fresh(), $urlModel];
    }

    public function test_current_url_flags_the_matching_price_cache_entry(): void
    {
        [$product] = $this->productWithTrackedUrl('https://shop.com/p/x');

        $response = $this->getJson("/api/products/{$product->id}?current_url=".urlencode('https://shop.com/p/x'));

        $response->assertSuccessful();
        $this->assertTrue($response->json('data.price_cache.0.is_current'));
    }

    public function test_current_url_matches_across_tracking_params_www_case_and_slash(): void
    {
        [$product] = $this->productWithTrackedUrl('https://shop.com/p/Widget');

        $response = $this->getJson("/api/products/{$product->id}?current_url=".urlencode('https://WWW.shop.com/p/widget/?utm_source=fb&gclid=z'));

        $response->assertSuccessful();
        $this->assertTrue($response->json('data.price_cache.0.is_current'));
    }

    public function test_current_url_flags_nothing_for_garbage_input(): void
    {
        [$product] = $this->productWithTrackedUrl('https://shop.com/p/x');

        $response = $this->getJson("/api/products/{$product->id}?current_url=not%20a%20url");

        $response->assertSuccessful();
        $this->assertFalse($response->json('data.price_cache.0.is_current'));
    }

    public function test_is_current_is_absent_when_the_param_is_not_supplied(): void
    {
        [$product] = $this->productWithTrackedUrl('https://shop.com/p/x');

        $response = $this->getJson("/api/products/{$product->id}");

        $response->assertSuccessful();
        $this->assertArrayNotHasKey('is_current', $response->json('data.price_cache.0'));
    }

    public function test_is_current_is_present_but_false_when_the_param_is_supplied_empty(): void
    {
        // Laravel's global ConvertEmptyStringsToNull middleware collapses a literal
        // `?current_url=` query value to null before the handler sees it, so this
        // "supplied but empty" branch is unreachable via a real HTTP request in this
        // app. It is still a real contract of the transformer (see withCurrentUrl()'s
        // null-vs-empty-string distinction), so exercise it directly.
        [$product] = $this->productWithTrackedUrl('https://shop.com/p/x');

        $data = (new \App\Filament\Resources\ProductResource\Api\Transformers\ProductTransformer($product))
            ->withCurrentUrl('')
            ->toArray(request());

        $this->assertArrayHasKey('is_current', $data['price_cache'][0]);
        $this->assertFalse($data['price_cache'][0]['is_current']);
    }

    public function test_current_url_flags_every_matching_entry(): void
    {
        $product = Product::factory()->create(['user_id' => $this->user->id]);
        \App\Models\Url::factory()->withPrices([10.00])->create(['product_id' => $product->id, 'url' => 'https://shop.com/p/x']);
        \App\Models\Url::factory()->withPrices([12.00])->create(['product_id' => $product->id, 'url' => 'https://www.shop.com/p/x/?ref=a']);

        $response = $this->getJson("/api/products/{$product->id}?current_url=".urlencode('https://shop.com/p/x'));

        $response->assertSuccessful();
        $flags = array_column($response->json('data.price_cache'), 'is_current');
        $this->assertSame([true, true], $flags);
    }

    public function test_is_current_is_never_persisted_into_price_cache(): void
    {
        [$product] = $this->productWithTrackedUrl('https://shop.com/p/x');

        // Exercise the transformer directly so this test actually fails if
        // decoratePriceCache() ever writes back into $this->resource->price_cache,
        // rather than only reconfirming that the HTTP path never calls save().
        $data = (new \App\Filament\Resources\ProductResource\Api\Transformers\ProductTransformer($product))
            ->withCurrentUrl('https://shop.com/p/x')
            ->toArray(request());

        $this->assertTrue($data['price_cache'][0]['is_current']);
        $this->assertArrayNotHasKey('is_current', $product->price_cache[0]);
        $this->assertFalse($product->isDirty());

        // Belt-and-braces: confirm the materialised column is untouched end-to-end too.
        $this->getJson("/api/products/{$product->id}?current_url=".urlencode('https://shop.com/p/x'))
            ->assertSuccessful();

        $stored = \Illuminate\Support\Facades\DB::table('products')->where('id', $product->id)->value('price_cache');
        $this->assertStringNotContainsString('is_current', (string) $stored);
    }

    public function test_current_url_does_not_expose_another_users_product(): void
    {
        $other = User::factory()->create();
        [$product] = $this->productWithTrackedUrl('https://shop.com/p/x', $other);

        $this->getJson("/api/products/{$product->id}?current_url=".urlencode('https://shop.com/p/x'))
            ->assertNotFound();
    }

    public function test_current_url_flags_an_affiliate_tagged_ebay_listing(): void
    {
        config()->set('affiliates.enabled', true);

        [$product] = $this->productWithTrackedUrl('https://www.ebay.com.au/itm/123456');

        // price_cache[].url is buy_url, carrying six non-denylisted eBay affiliate
        // params. Matching keys off url_id, so the tagging is irrelevant.
        $response = $this->getJson("/api/products/{$product->id}?current_url=".urlencode('https://www.ebay.com.au/itm/123456'));

        $response->assertSuccessful();
        $this->assertStringContainsString('campid=', $response->json('data.price_cache.0.url'));
        $this->assertTrue($response->json('data.price_cache.0.is_current'));
    }

    public function test_an_over_long_current_url_flags_nothing_without_erroring(): void
    {
        [$product] = $this->productWithTrackedUrl('https://shop.com/p/x');

        $long = 'https://shop.com/p/x?pad='.str_repeat('a', 2100);

        $response = $this->getJson("/api/products/{$product->id}?current_url=".urlencode($long));

        $response->assertSuccessful();
        $this->assertFalse($response->json('data.price_cache.0.is_current'));
    }

    public function test_list_endpoint_flags_the_current_url(): void
    {
        [$match] = $this->productWithTrackedUrl('https://shop.com/p/x');
        [$other] = $this->productWithTrackedUrl('https://shop.com/p/y');

        $response = $this->getJson('/api/products?current_url='.urlencode('https://www.shop.com/p/x/?utm_source=a'));

        $response->assertSuccessful();

        $byId = collect($response->json('data'))->keyBy('id');
        $this->assertTrue($byId[$match->id]['price_cache'][0]['is_current']);
        $this->assertFalse($byId[$other->id]['price_cache'][0]['is_current']);
    }

    public function test_list_endpoint_omits_is_current_without_the_param(): void
    {
        $this->productWithTrackedUrl('https://shop.com/p/x');

        $response = $this->getJson('/api/products');

        $response->assertSuccessful();
        $this->assertArrayNotHasKey('is_current', $response->json('data.0.price_cache.0'));
    }

    public function test_list_endpoint_resolves_current_url_in_a_single_query(): void
    {
        foreach (range(1, 5) as $i) {
            $this->productWithTrackedUrl("https://shop.com/p/{$i}");
        }

        \Illuminate\Support\Facades\DB::enableQueryLog();
        $this->getJson('/api/products?current_url='.urlencode('https://shop.com/p/3'))->assertSuccessful();
        $lookups = collect(\Illuminate\Support\Facades\DB::getQueryLog())
            ->filter(fn (array $q): bool => str_contains($q['query'], 'url_normalized'))
            ->count();
        \Illuminate\Support\Facades\DB::disableQueryLog();

        $this->assertSame(1, $lookups, 'current_url must resolve in one query for the whole page, not per product.');
    }

    public function test_url_filter_handles_a_comma_in_a_query_value_without_erroring(): void
    {
        $product = Product::factory()->create(['user_id' => $this->user->id]);
        $url = 'https://shop.com/p/x?size=s,m';
        \App\Models\Url::factory()->create(['product_id' => $product->id, 'url' => $url]);

        $response = $this->getJson('/api/products?filter[url]='.urlencode($url));

        $response->assertSuccessful()->assertJsonCount(1, 'data');
        $this->assertSame($product->id, $response->json('data.0.id'));
    }

    public function test_url_filter_handles_a_realistic_amazon_style_comma_url_without_erroring(): void
    {
        $product = Product::factory()->create(['user_id' => $this->user->id]);
        // location.href arrives with real commas here — the extension decodes
        // sprefix=tv%2Caps%2C300 before Spatie ever sees the query string.
        $url = 'https://www.amazon.com.au/dp/B0EXAMPLE?sprefix=tv,aps,300';
        \App\Models\Url::factory()->create(['product_id' => $product->id, 'url' => $url]);

        $response = $this->getJson('/api/products?filter[url]='.urlencode($url));

        $response->assertSuccessful()->assertJsonCount(1, 'data');
        $this->assertSame($product->id, $response->json('data.0.id'));
    }

    public function test_url_filter_returns_empty_for_an_empty_value(): void
    {
        Product::factory()->create(['user_id' => $this->user->id]);

        $this->getJson('/api/products?filter[url]=')
            ->assertSuccessful()
            ->assertJsonCount(0, 'data');
    }

    public function test_sparse_fieldset_without_price_cache_omits_the_price_cache_key(): void
    {
        $this->productWithTrackedUrl('https://shop.com/p/x');

        $response = $this->getJson('/api/products?fields[products]=id&current_url='.urlencode('https://shop.com/p/x'));

        $response->assertSuccessful();
        $this->assertArrayNotHasKey('price_cache', $response->json('data.0'));
    }

    public function test_sparse_fieldsets_do_not_break_the_urls_eager_load(): void
    {
        $product = Product::factory()->create(['user_id' => $this->user->id]);
        \App\Models\Url::factory()->create([
            'product_id' => $product->id,
            'url' => 'https://shop.com/p/x',
        ]);

        $response = $this->getJson('/api/products?fields[products]=id&include=urls');

        $response->assertSuccessful();
        $this->assertSame(['id', 'urls'], array_keys($response->json('data.0')));
        $this->assertCount(1, $response->json('data.0.urls'));
    }
}

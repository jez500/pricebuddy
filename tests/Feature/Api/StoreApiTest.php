<?php

namespace Tests\Feature\Api;

use App\Enums\ScraperService;
use App\Models\Product;
use App\Models\Store;
use App\Models\Url;
use App\Models\User;
use App\Services\Helpers\SettingsHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StoreApiTest extends TestCase
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

    public function test_can_list_stores(): void
    {
        // Stores are shared: the list includes stores that other users created and
        // stores with no creator.
        Store::factory()->count(3)->create(['user_id' => $this->user->id]);
        Store::factory()->count(2)->create(['user_id' => User::factory()->create()->id]);
        $this->createOwnerlessStore();

        $response = $this->getJson('/api/stores');

        $response->assertSuccessful()
            ->assertJsonCount(6, 'data')
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'name',
                        'slug',
                        'initials',
                        'domains',
                        'scrape_strategy',
                        'settings',
                        'created_at',
                        'updated_at',
                    ],
                ],
                'links',
                'meta',
            ]);
    }

    public function test_can_show_single_store(): void
    {
        $store = Store::factory()->create(['user_id' => $this->user->id]);

        $response = $this->getJson("/api/stores/{$store->id}");

        $response->assertSuccessful()
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'name',
                    'slug',
                    'initials',
                    'domains',
                    'scrape_strategy',
                    'settings',
                    'created_at',
                    'updated_at',
                ],
            ])
            ->assertJson([
                'data' => [
                    'id' => $store->id,
                    'name' => $store->name,
                ],
            ]);
    }

    public function test_list_includes_resolved_currency_and_locale(): void
    {
        SettingsHelper::setSetting('default_locale_settings', ['locale' => 'en_AU', 'currency' => 'AUD']);

        $withOverride = Store::factory()->create([
            'user_id' => $this->user->id,
            'settings' => ['locale_settings' => ['currency' => 'GBP', 'locale' => 'en_GB']],
        ]);
        $withoutOverride = Store::factory()->create(['user_id' => $this->user->id]);

        $response = $this->getJson('/api/stores?sort=id');

        $response->assertSuccessful()
            ->assertJsonStructure(['data' => ['*' => ['currency', 'locale']]]);

        $byId = collect($response->json('data'))->keyBy('id');

        $this->assertSame('GBP', $byId[$withOverride->id]['currency']);
        $this->assertSame('en-GB', $byId[$withOverride->id]['locale']);
        $this->assertSame('AUD', $byId[$withoutOverride->id]['currency']);
        $this->assertSame('en-AU', $byId[$withoutOverride->id]['locale']);
    }

    public function test_show_includes_resolved_currency_and_locale(): void
    {
        SettingsHelper::setSetting('default_locale_settings', ['locale' => 'en_AU', 'currency' => 'AUD']);

        $store = Store::factory()->create([
            'user_id' => $this->user->id,
            'settings' => ['locale_settings' => ['currency' => 'GBP', 'locale' => 'en_GB']],
        ]);

        $this->getJson("/api/stores/{$store->id}")
            ->assertSuccessful()
            ->assertJsonPath('data.currency', 'GBP')
            ->assertJsonPath('data.locale', 'en-GB');
    }

    public function test_sparse_fieldset_still_reports_the_stores_own_currency(): void
    {
        SettingsHelper::setSetting('default_locale_settings', ['locale' => 'en_AU', 'currency' => 'AUD']);

        $store = Store::factory()->create([
            'user_id' => $this->user->id,
            'settings' => ['locale_settings' => ['currency' => 'GBP', 'locale' => 'en_GB']],
        ]);

        // `settings` is not requested, but the currency/locale accessors read from it.
        // It is selected internally to resolve them, and must not leak into the response.
        $this->getJson('/api/stores?fields[stores]=id,name')
            ->assertSuccessful()
            ->assertJsonPath('data.0.id', $store->id)
            ->assertJsonPath('data.0.currency', 'GBP')
            ->assertJsonPath('data.0.locale', 'en-GB')
            ->assertJsonMissingPath('data.0.settings');
    }

    public function test_sparse_fieldset_returns_settings_when_it_is_requested(): void
    {
        $store = Store::factory()->create([
            'user_id' => $this->user->id,
            'settings' => ['locale_settings' => ['currency' => 'GBP', 'locale' => 'en_GB']],
        ]);

        $this->getJson('/api/stores?fields[stores]=id,settings')
            ->assertSuccessful()
            ->assertJsonPath('data.0.id', $store->id)
            ->assertJsonPath('data.0.settings.locale_settings.currency', 'GBP');
    }

    public function test_empty_locale_overrides_fall_back_to_the_app_defaults(): void
    {
        SettingsHelper::setSetting('default_locale_settings', ['locale' => 'en_AU', 'currency' => 'AUD']);

        // An explicitly null override is not the same as an absent one: the accessors use
        // data_get(), which only falls back when the key is missing.
        $store = Store::factory()->create([
            'user_id' => $this->user->id,
            'settings' => ['locale_settings' => ['currency' => null, 'locale' => null]],
        ]);

        $this->getJson('/api/stores')
            ->assertSuccessful()
            ->assertJsonPath('data.0.currency', 'AUD')
            ->assertJsonPath('data.0.locale', 'en-AU');

        $this->getJson("/api/stores/{$store->id}")
            ->assertSuccessful()
            ->assertJsonPath('data.currency', 'AUD')
            ->assertJsonPath('data.locale', 'en-AU');
    }

    public function test_can_show_a_store_that_another_user_created(): void
    {
        $theirs = Store::factory()->create(['user_id' => User::factory()->create()->id]);
        $ownerless = $this->createOwnerlessStore();

        $this->getJson("/api/stores/{$theirs->id}")->assertSuccessful()->assertJsonPath('data.id', $theirs->id);
        $this->getJson("/api/stores/{$ownerless->id}")->assertSuccessful()->assertJsonPath('data.id', $ownerless->id);
    }

    public function test_show_returns_404_for_a_missing_store(): void
    {
        $this->getJson('/api/stores/999999')->assertNotFound();
    }

    /**
     * A shared store must not expose another user's URLs, products or email address.
     */
    public function test_includes_only_contain_the_callers_own_data(): void
    {
        $other = User::factory()->create();
        $store = Store::factory()->create(['user_id' => $other->id]);

        $mine = Product::factory()->create(['user_id' => $this->user->id]);
        $theirs = Product::factory()->create(['user_id' => $other->id]);
        $myUrl = Url::factory()->create(['store_id' => $store->id, 'product_id' => $mine->id]);
        Url::factory()->create(['store_id' => $store->id, 'product_id' => $theirs->id]);

        $responses = [
            'detail' => $this->getJson("/api/stores/{$store->id}?include=user,urls,products")->assertSuccessful()->json('data'),
            'list' => $this->getJson('/api/stores?include=user,urls,products')->assertSuccessful()->json('data.0'),
        ];

        foreach ($responses as $uri => $data) {
            $this->assertSame([$myUrl->id], array_column($data['urls'], 'id'), $uri);
            $this->assertSame([$mine->id], array_column($data['products'], 'id'), $uri);
            $this->assertArrayNotHasKey('email', (array) ($data['user'] ?? []), $uri);
        }
    }

    public function test_include_user_shows_the_creator_to_the_creator(): void
    {
        $store = Store::factory()->create(['user_id' => $this->user->id]);

        $this->getJson("/api/stores/{$store->id}?include=user")
            ->assertSuccessful()
            ->assertJsonPath('data.user.id', $this->user->id);
    }

    public function test_can_create_store(): void
    {
        $storeData = [
            'name' => 'Test Store',
            'domains' => [
                ['domain' => 'teststore.com'],
            ],
            'scrape_strategy' => [
                'title' => [
                    'value' => 'h1',
                    'type' => 'selector',
                ],
                'price' => [
                    'value' => '.price',
                    'type' => 'selector',
                ],
                'image' => [
                    'value' => 'img.product',
                    'type' => 'selector',
                ],
            ],
            'settings' => [
                'scraper_service' => ScraperService::Http->value,
                'scraper_service_settings' => '',
            ],
        ];

        $response = $this->postJson('/api/stores', $storeData);

        $response->assertCreated()
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'name',
                    'slug',
                    'domains',
                    'scrape_strategy',
                    'settings',
                    'created_at',
                    'updated_at',
                ],
            ])
            ->assertJson([
                'data' => [
                    'name' => 'Test Store',
                ],
            ]);

        $this->assertDatabaseHas('stores', [
            'name' => 'Test Store',
            'user_id' => $this->user->id,
        ]);
    }

    public function test_create_store_validation_fails_with_invalid_data(): void
    {
        $invalidData = [
            'name' => '', // Required field empty
            'domains' => [], // Required field empty
        ];

        $response = $this->postJson('/api/stores', $invalidData);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    }

    public function test_can_update_store(): void
    {
        $store = Store::factory()->create(['user_id' => $this->user->id]);

        $updateData = [
            'name' => 'Updated Store Name',
            'domains' => [
                ['domain' => 'updated.com'],
            ],
        ];

        $response = $this->putJson("/api/stores/{$store->id}", $updateData);

        $response->assertSuccessful()
            ->assertJson([
                'data' => [
                    'id' => $store->id,
                    'name' => 'Updated Store Name',
                ],
            ]);

        $this->assertDatabaseHas('stores', [
            'id' => $store->id,
            'name' => 'Updated Store Name',
        ]);
    }

    public function test_any_user_can_update_a_store_they_did_not_create(): void
    {
        $other = User::factory()->create();
        $store = Store::factory()->create(['user_id' => $other->id]);

        $this->putJson("/api/stores/{$store->id}", [
            'name' => 'Renamed by another user',
            'domains' => $store->domains ?: [['domain' => 'renamed.com']],
            'scrape_strategy' => $store->scrape_strategy,
            'settings' => $store->settings,
            'user_id' => $this->user->id,
        ])
            ->assertSuccessful()
            ->assertJsonPath('data.name', 'Renamed by another user');

        // user_id records who created the store. An edit does not change it.
        $this->assertSame($other->id, $store->fresh()->user_id);
    }

    public function test_can_update_a_store_with_no_creator(): void
    {
        $store = $this->createOwnerlessStore();

        $this->putJson("/api/stores/{$store->id}", ['name' => 'Named', 'domains' => [['domain' => 'named.com']]])
            ->assertSuccessful();

        $this->assertSame('Named', $store->fresh()->name);
    }

    public function test_update_returns_404_for_a_missing_store(): void
    {
        $this->putJson('/api/stores/999999', ['name' => 'Missing', 'domains' => [['domain' => 'missing.com']]])
            ->assertNotFound();
    }

    public function test_admin_can_delete_any_store(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create(), ['*']);
        $store = Store::factory()->create(['user_id' => User::factory()->create()->id]);

        $response = $this->deleteJson("/api/stores/{$store->id}");

        $response->assertNoContent();
        $this->assertDatabaseMissing('stores', ['id' => $store->id]);
    }

    /**
     * Deleting a shared store deletes every user's URLs and prices for it, so only an
     * admin can do it. The refusal is a 403, so a client can explain it.
     */
    public function test_non_admin_cannot_delete_a_store_even_one_they_created(): void
    {
        $mine = Store::factory()->create(['user_id' => $this->user->id]);
        $theirs = Store::factory()->create(['user_id' => User::factory()->create()->id]);

        foreach ([$mine, $theirs] as $store) {
            $this->deleteJson("/api/stores/{$store->id}")->assertForbidden();
            $this->assertDatabaseHas('stores', ['id' => $store->id]);
        }
    }

    public function test_delete_returns_404_for_a_missing_store(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create(), ['*']);

        $this->deleteJson('/api/stores/999999')->assertNotFound();
    }

    public function test_can_filter_stores_by_domain(): void
    {
        Store::factory()->create([
            'user_id' => $this->user->id,
            'name' => 'Store A',
            'domains' => [['domain' => 'example.com']],
        ]);
        Store::factory()->create([
            'user_id' => $this->user->id,
            'name' => 'Store B',
            'domains' => [['domain' => 'another.com']],
        ]);

        $response = $this->getJson('/api/stores?filter[domains]=example.com');

        $response->assertSuccessful()
            ->assertJsonCount(1, 'data');
    }

    public function test_can_sort_stores(): void
    {
        Store::factory()->create([
            'user_id' => $this->user->id,
            'name' => 'Z Store',
            'created_at' => now()->subDay(),
        ]);
        Store::factory()->create([
            'user_id' => $this->user->id,
            'name' => 'A Store',
            'created_at' => now(),
        ]);

        $response = $this->getJson('/api/stores?sort=name');

        $response->assertSuccessful();
        $data = $response->json('data');
        $this->assertEquals('A Store', $data[0]['name']);
        $this->assertEquals('Z Store', $data[1]['name']);
    }

    public function test_can_include_relationships(): void
    {
        $store = Store::factory()->create(['user_id' => $this->user->id]);

        $response = $this->getJson("/api/stores/{$store->id}?include=user,urls,products");

        $response->assertSuccessful()
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'name',
                    'user',
                ],
            ]);
    }

    public function test_pagination_works(): void
    {
        Store::factory()->count(25)->create(['user_id' => $this->user->id]);

        $response = $this->getJson('/api/stores?per_page=10');

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

    public function test_can_search_stores_by_name(): void
    {
        Store::factory()->create([
            'user_id' => $this->user->id,
            'name' => 'Amazon Store',
        ]);
        Store::factory()->create([
            'user_id' => $this->user->id,
            'name' => 'eBay Store',
        ]);

        $response = $this->getJson('/api/stores?search=Amazon');

        $response->assertSuccessful()
            ->assertJsonCount(1, 'data');
    }

    public function test_store_has_correct_slug(): void
    {
        $storeData = [
            'name' => 'Test Store Name',
            'domains' => [['domain' => 'teststore.com']],
            'scrape_strategy' => [
                'title' => ['value' => 'h1', 'type' => 'selector'],
                'price' => ['value' => '.price', 'type' => 'selector'],
                'image' => ['value' => 'img', 'type' => 'selector'],
            ],
            'settings' => [
                'scraper_service' => ScraperService::Http->value,
            ],
        ];

        $response = $this->postJson('/api/stores', $storeData);

        $response->assertCreated()
            ->assertJson([
                'data' => [
                    'name' => 'Test Store Name',
                    'slug' => 'test-store-name',
                ],
            ]);
    }

    public function test_can_filter_by_scraper_service(): void
    {
        Store::factory()->create([
            'user_id' => $this->user->id,
            'settings' => [
                'scraper_service' => ScraperService::Http->value,
            ],
        ]);
        Store::factory()->create([
            'user_id' => $this->user->id,
            'settings' => [
                'scraper_service' => ScraperService::Api->value,
            ],
        ]);

        $response = $this->getJson('/api/stores?filter[scraper_service]='.ScraperService::Http->value);

        $response->assertSuccessful()
            ->assertJsonCount(1, 'data');
    }

    public function test_requires_authentication(): void
    {
        $this->withHeaders(['Authorization' => '']);

        $response = $this->getJson('/api/stores');

        $response->assertUnauthorized();
    }

    public function test_domain_filter_matches_regardless_of_www_and_case(): void
    {
        $store = Store::factory()->create([
            'user_id' => $this->user->id,
            'domains' => [['domain' => 'www.Target.com.au']],
        ]);
        Store::factory()->create([
            'user_id' => $this->user->id,
            'domains' => [['domain' => 'kmart.com.au']],
        ]);

        foreach (['www.Target.com.au', 'target.com.au', 'TARGET.COM.AU', 'target.com.au:443'] as $host) {
            $response = $this->getJson('/api/stores?filter[domain]='.urlencode($host));

            $response->assertSuccessful()->assertJsonCount(1, 'data');
            $this->assertSame($store->id, $response->json('data.0.id'), "Failed for host: {$host}");
        }
    }

    public function test_domain_filter_does_not_match_a_different_domain(): void
    {
        Store::factory()->create([
            'user_id' => $this->user->id,
            'domains' => [['domain' => 'target.com.au']],
        ]);

        $this->getJson('/api/stores?filter[domain]='.urlencode('kmart.com.au'))
            ->assertSuccessful()
            ->assertJsonCount(0, 'data');
    }

    public function test_domain_filter_does_not_partially_match(): void
    {
        Store::factory()->create([
            'user_id' => $this->user->id,
            'domains' => [['domain' => 'target.com.au']],
        ]);

        $this->getJson('/api/stores?filter[domain]='.urlencode('arget.com.au'))
            ->assertSuccessful()
            ->assertJsonCount(0, 'data');
    }

    public function test_domain_filter_returns_empty_for_garbage(): void
    {
        Store::factory()->create([
            'user_id' => $this->user->id,
            'domains' => [['domain' => 'target.com.au']],
        ]);

        $this->getJson('/api/stores?filter[domain]=')
            ->assertSuccessful()
            ->assertJsonCount(0, 'data');
    }

    public function test_domain_filters_return_stores_whatever_the_creator(): void
    {
        $ownerless = $this->createOwnerlessStore(['domains' => [['domain' => 'amazon.com.au'], ['domain' => 'www.amazon.com.au']]]);
        $theirs = Store::factory()->create([
            'user_id' => User::factory()->create()->id,
            'domains' => [['domain' => 'www.amazon.com.au']],
        ]);

        foreach (['filter[domain]=www.amazon.com.au', 'filter[domains]=amazon.com.au'] as $filter) {
            $ids = collect($this->getJson('/api/stores?'.$filter)->assertSuccessful()->json('data'))->pluck('id')->sort()->values()->all();

            $this->assertSame([$ownerless->id, $theirs->id], $ids, $filter);
        }
    }

    public function test_create_ignores_a_client_supplied_user_id(): void
    {
        $other = User::factory()->create();

        $this->postJson('/api/stores', [
            'name' => 'Attributed Store',
            'domains' => [['domain' => 'attributed.com']],
            'scrape_strategy' => [
                'title' => ['value' => 'h1', 'type' => 'selector'],
                'price' => ['value' => '.price', 'type' => 'selector'],
                'image' => ['value' => 'img', 'type' => 'selector'],
            ],
            'settings' => ['scraper_service' => ScraperService::Http->value],
            'user_id' => $other->id,
        ])->assertCreated();

        $this->assertSame($this->user->id, Store::where('name', 'Attributed Store')->value('user_id'));
    }

    /**
     * Make a store with no creator, the way older installs and the seeder made them.
     * A query update skips model events, so nothing fills in user_id.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function createOwnerlessStore(array $attributes = []): Store
    {
        $store = Store::factory()->create($attributes);

        Store::query()->whereKey($store->id)->update(['user_id' => null]);

        return $store->fresh();
    }

    public function test_domain_filter_handles_a_comma_without_erroring(): void
    {
        Store::factory()->create([
            'user_id' => $this->user->id,
            'domains' => [['domain' => 'target.com.au']],
        ]);

        $this->getJson('/api/stores?filter[domain]='.urlencode('target.com.au,other.com'))
            ->assertSuccessful()
            ->assertJsonCount(0, 'data');
    }

    public function test_domain_filter_handles_a_bracket_array_without_erroring(): void
    {
        $store = Store::factory()->create([
            'user_id' => $this->user->id,
            'domains' => [['domain' => 'target.com.au']],
        ]);

        // A single bracket-array value must still resolve to the plain host.
        $response = $this->getJson('/api/stores?filter[domain][]='.urlencode('www.Target.com.au'));
        $response->assertSuccessful()->assertJsonCount(1, 'data');
        $this->assertSame($store->id, $response->json('data.0.id'));

        // A comma inside a bracket-array value nests once Spatie splits it, which
        // used to reach implode() and raise "Array to string conversion" -> HTTP 500.
        $this->getJson('/api/stores?filter[domain][]='.urlencode('target.com.au,other.com'))
            ->assertSuccessful()
            ->assertJsonCount(0, 'data');
    }

    public function test_partial_domains_filter_still_works(): void
    {
        Store::factory()->create([
            'user_id' => $this->user->id,
            'domains' => [['domain' => 'target.com.au']],
        ]);

        $this->getJson('/api/stores?filter[domains]=target')
            ->assertSuccessful()
            ->assertJsonCount(1, 'data');
    }
}

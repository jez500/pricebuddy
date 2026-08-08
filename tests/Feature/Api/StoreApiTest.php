<?php

namespace Tests\Feature\Api;

use App\Enums\ScraperService;
use App\Models\Store;
use App\Models\User;
use App\Services\Helpers\SettingsHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $stores = Store::factory()->count(3)->create(['user_id' => $this->user->id]);
        Store::factory()->count(2)->create(); // Other users' stores

        $response = $this->getJson('/api/stores');

        $response->assertSuccessful()
            ->assertJsonCount(3, 'data')
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
        $this->getJson('/api/stores?fields[stores]=id,name')
            ->assertSuccessful()
            ->assertJsonPath('data.0.id', $store->id)
            ->assertJsonPath('data.0.currency', 'GBP')
            ->assertJsonPath('data.0.locale', 'en-GB');
    }

    public function test_cannot_show_other_users_store(): void
    {
        $otherUser = User::factory()->create();
        $store = Store::factory()->create(['user_id' => $otherUser->id]);

        $response = $this->getJson("/api/stores/{$store->id}");

        $response->assertNotFound();
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

    public function test_cannot_update_other_users_store(): void
    {
        $otherUser = User::factory()->create();
        $store = Store::factory()->create(['user_id' => $otherUser->id]);

        $updateData = [
            'name' => 'Hacked Store Name',
            'domains' => $store->domains ?: [['domain' => 'hacked.com']],
            'scrape_strategy' => $store->scrape_strategy,
            'settings' => $store->settings,
        ];

        $response = $this->putJson("/api/stores/{$store->id}", $updateData);

        $response->assertNotFound();
    }

    public function test_can_delete_store(): void
    {
        $store = Store::factory()->create(['user_id' => $this->user->id]);

        $response = $this->deleteJson("/api/stores/{$store->id}");

        $response->assertNoContent();
        $this->assertDatabaseMissing('stores', ['id' => $store->id]);
    }

    public function test_cannot_delete_other_users_store(): void
    {
        $otherUser = User::factory()->create();
        $store = Store::factory()->create(['user_id' => $otherUser->id]);

        $response = $this->deleteJson("/api/stores/{$store->id}");

        $response->assertNotFound();
        $this->assertDatabaseHas('stores', ['id' => $store->id]);
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

    public function test_domain_filter_does_not_leak_another_users_store(): void
    {
        $other = User::factory()->create();
        Store::factory()->create([
            'user_id' => $other->id,
            'domains' => [['domain' => 'target.com.au']],
        ]);

        $this->getJson('/api/stores?filter[domain]='.urlencode('target.com.au'))
            ->assertSuccessful()
            ->assertJsonCount(0, 'data');
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

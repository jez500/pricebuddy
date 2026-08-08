<?php

namespace Tests\Feature\Api;

use App\Enums\ScraperService;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\ScraperTrait;

/**
 * The `value` of a scrape strategy is conditional on its `type`: schema_org reads the
 * page's embedded metadata and takes no value, every other type requires one. All three
 * endpoints that accept a strategy must agree, otherwise a strategy that meta-extraction
 * accepts cannot be saved (or vice versa).
 */
class StoreScrapeStrategyValidationTest extends TestCase
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
    }

    public function test_create_accepts_schema_org_strategies_without_a_value(): void
    {
        $this->postJson('/api/stores', $this->createPayload($this->schemaOrgStrategies()))
            ->assertCreated();

        $store = Store::query()->firstOrFail();

        $this->assertSame('schema_org', $store->scrape_strategy->title?->type->value);
        $this->assertNull($store->scrape_strategy->title?->value);
    }

    public function test_create_rejects_a_value_on_a_schema_org_strategy(): void
    {
        $strategies = $this->schemaOrgStrategies();
        $strategies['title']['value'] = 'h1';

        $this->postJson('/api/stores', $this->createPayload($strategies))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['scrape_strategy.title.value']);
    }

    public function test_create_rejects_a_selector_strategy_without_a_value(): void
    {
        $strategies = $this->schemaOrgStrategies();
        $strategies['price'] = ['type' => 'selector'];

        $this->postJson('/api/stores', $this->createPayload($strategies))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['scrape_strategy.price.value']);
    }

    public function test_create_accepts_a_selector_strategy_with_a_value(): void
    {
        $strategies = $this->schemaOrgStrategies();
        $strategies['price'] = ['type' => 'selector', 'value' => '.price'];

        $this->postJson('/api/stores', $this->createPayload($strategies))
            ->assertCreated();
    }

    public function test_create_rejects_a_blank_value_on_a_selector_strategy(): void
    {
        $strategies = $this->schemaOrgStrategies();
        $strategies['price'] = ['type' => 'selector', 'value' => '   '];

        $this->postJson('/api/stores', $this->createPayload($strategies))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['scrape_strategy.price.value']);
    }

    public function test_update_accepts_schema_org_strategies_without_a_value(): void
    {
        $store = Store::factory()->create(['user_id' => $this->user->id]);

        $this->putJson("/api/stores/{$store->id}", ['scrape_strategy' => $this->schemaOrgStrategies()])
            ->assertSuccessful();

        $store->refresh();

        $this->assertSame('schema_org', $store->scrape_strategy->image?->type->value);
        $this->assertNull($store->scrape_strategy->image?->value);
    }

    public function test_update_rejects_a_value_on_a_schema_org_strategy(): void
    {
        $store = Store::factory()->create(['user_id' => $this->user->id]);

        $strategies = $this->schemaOrgStrategies();
        $strategies['image']['value'] = 'img';

        $this->putJson("/api/stores/{$store->id}", ['scrape_strategy' => $strategies])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['scrape_strategy.image.value']);
    }

    public function test_update_rejects_a_selector_strategy_without_a_value(): void
    {
        $store = Store::factory()->create(['user_id' => $this->user->id]);

        $strategies = $this->schemaOrgStrategies();
        $strategies['image'] = ['type' => 'selector'];

        $this->putJson("/api/stores/{$store->id}", ['scrape_strategy' => $strategies])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['scrape_strategy.image.value']);
    }

    public function test_update_accepts_a_selector_strategy_with_a_value(): void
    {
        $store = Store::factory()->create(['user_id' => $this->user->id]);

        $strategies = $this->schemaOrgStrategies();
        $strategies['image'] = ['type' => 'selector', 'value' => 'img.product'];

        $this->putJson("/api/stores/{$store->id}", ['scrape_strategy' => $strategies])
            ->assertSuccessful();

        $this->assertSame('img.product', $store->refresh()->scrape_strategy->image?->value);
    }

    public function test_a_saved_schema_org_strategy_is_stored_and_returned_without_a_value_key(): void
    {
        $this->postJson('/api/stores', $this->createPayload($this->schemaOrgStrategies()))
            ->assertCreated();

        $store = Store::query()->firstOrFail();

        // The DTO drops null values entirely rather than persisting `"value": null`.
        $stored = json_decode($store->getRawOriginal('scrape_strategy'), true);
        $this->assertSame(['type' => 'schema_org'], $stored['title']);

        $this->getJson("/api/stores/{$store->id}")
            ->assertSuccessful()
            ->assertJsonPath('data.scrape_strategy.title', ['type' => 'schema_org']);
    }

    public function test_a_saved_schema_org_store_round_trips_through_meta_extraction(): void
    {
        $this->postJson('/api/stores', $this->createPayload($this->schemaOrgStrategies()))
            ->assertCreated();

        $store = Store::query()->firstOrFail();

        $read = $this->getJson("/api/stores/{$store->id}")->assertSuccessful()->json('data');

        $this->mockScrapeSchema('42.50', 'Round trip product', 'https://example.com/rt.jpg');

        // Exactly what a client reads back, sent straight into the endpoint that made the
        // original strategy testable.
        $this->postJson('/api/meta-extraction', [
            'url' => 'https://teststore.com/product/1',
            'store' => [
                'name' => $read['name'],
                'domains' => $read['domains'],
                'settings' => $read['settings'],
                'scrape_strategy' => $read['scrape_strategy'],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('data.title', 'Round trip product')
            ->assertJsonPath('data.price', 42.5);
    }

    public function test_meta_extraction_still_rejects_a_value_on_a_schema_org_strategy(): void
    {
        $this->postJson('/api/meta-extraction', [
            'url' => 'https://teststore.com/product/1',
            'store' => [
                'scrape_strategy' => [
                    'title' => ['type' => 'schema_org', 'value' => 'h1'],
                ],
            ],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['store.scrape_strategy.title.value']);
    }

    public function test_meta_extraction_still_requires_a_value_on_a_selector_strategy(): void
    {
        $this->postJson('/api/meta-extraction', [
            'url' => 'https://teststore.com/product/1',
            'store' => [
                'scrape_strategy' => [
                    'title' => ['type' => 'selector'],
                ],
            ],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['store.scrape_strategy.title.value']);
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function schemaOrgStrategies(): array
    {
        return [
            'title' => ['type' => 'schema_org'],
            'price' => ['type' => 'schema_org'],
            'image' => ['type' => 'schema_org'],
        ];
    }

    /**
     * @param  array<string, array<string, string>>  $strategies
     * @return array<string, mixed>
     */
    private function createPayload(array $strategies): array
    {
        return [
            'name' => 'Test Store',
            'domains' => [
                ['domain' => 'teststore.com'],
            ],
            'scrape_strategy' => $strategies,
            'settings' => [
                'scraper_service' => ScraperService::Http->value,
                'scraper_service_settings' => '',
            ],
        ];
    }
}

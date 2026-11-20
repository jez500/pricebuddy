<?php

namespace Tests\Feature\Api;

use App\Enums\StockStatus;
use App\Jobs\AddUrlToProductJob;
use App\Models\Product;
use App\Models\Url;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use Tests\Traits\VariantTrait;

class UrlVariantApiTest extends TestCase
{
    use RefreshDatabase;
    use VariantTrait;

    private User $user;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        $this->user = User::factory()->create();
        $token = $this->user->createToken('test-token')->plainTextToken;
        $this->withHeaders(['Authorization' => 'Bearer '.$token]);

        $this->createVariantStore();
        $this->mockVariantPage();

        $this->product = Product::factory()->create(['user_id' => $this->user->id]);
    }

    public function test_create_product_tracks_a_variant()
    {
        $this->postJson('/api/products', [
            'title' => 'Trail shorts',
            'url' => 'https://example.com/trail-shorts',
            'variant' => ['values' => ['Small']],
        ])->assertCreated();

        /** @var Url $url */
        $url = Url::query()->where('url', 'https://example.com/trail-shorts')->firstOrFail();
        $this->assertSame(['values' => ['Small']], $url->variant);
        $this->assertSame(StockStatus::OutOfStock, $url->availability);
    }

    public function test_add_urls_queues_the_variant_for_every_url()
    {
        Queue::fake();

        $this->postJson("/api/products/{$this->product->id}/urls", [
            'urls' => ['https://example.com/a', 'https://example.com/b'],
            'variant' => ['values' => ['Small']],
        ])->assertStatus(202);

        Queue::assertPushed(AddUrlToProductJob::class, 2);
        Queue::assertPushed(
            AddUrlToProductJob::class,
            fn (AddUrlToProductJob $job) => $job->url === 'https://example.com/b' && $job->variant === ['values' => ['Small']]
        );
    }

    public function test_the_same_page_can_be_tracked_once_per_variant()
    {
        Queue::fake();

        Url::factory()->create([
            'product_id' => $this->product->id,
            'url' => 'https://example.com/a',
            'variant' => ['values' => ['Small']],
        ]);

        $this->postJson("/api/products/{$this->product->id}/urls", [
            'urls' => ['https://example.com/a'],
            'variant' => ['values' => ['Medium']],
        ])->assertStatus(202)->assertJsonPath('data.queued', ['https://example.com/a']);

        $this->postJson("/api/products/{$this->product->id}/urls", [
            'urls' => ['https://example.com/a'],
            'variant' => ['values' => ['small']],
        ])->assertStatus(202)->assertJsonPath('data.queued', []);

        // The same value twice is still the same variant.
        $this->postJson("/api/products/{$this->product->id}/urls", [
            'urls' => ['https://example.com/a'],
            'variant' => ['values' => ['SMALL', 'small']],
        ])->assertStatus(202)->assertJsonPath('data.queued', []);
    }

    public function test_variant_is_validated()
    {
        $this->postJson("/api/products/{$this->product->id}/urls", [
            'urls' => ['https://example.com/a'],
            'variant' => ['values' => 'Small'],
        ])->assertUnprocessable()->assertJsonValidationErrors(['variant.values']);
    }

    public function test_store_api_accepts_a_variants_strategy()
    {
        $response = $this->postJson('/api/stores', [
            'name' => 'Variant store',
            'domains' => [['domain' => 'variants.example']],
            'settings' => ['scraper_service' => 'http'],
            'scrape_strategy' => [
                'title' => ['type' => 'schema_org'],
                'price' => ['type' => 'schema_org'],
                'image' => ['type' => 'schema_org'],
                'variants' => $this->nextDataVariantStrategy(),
            ],
        ]);

        $response->assertSuccessful();

        $this->postJson('/api/stores', [
            'name' => 'Bad variant store',
            'domains' => [['domain' => 'bad.example']],
            'settings' => ['scraper_service' => 'http'],
            'scrape_strategy' => [
                'title' => ['type' => 'schema_org'],
                'price' => ['type' => 'schema_org'],
                'image' => ['type' => 'schema_org'],
                'variants' => ['type' => 'nonsense'],
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors(['scrape_strategy.variants.type']);
    }
}

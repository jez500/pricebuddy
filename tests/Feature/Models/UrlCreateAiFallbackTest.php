<?php

namespace Tests\Feature\Models;

use App\Models\Product;
use App\Models\Store;
use App\Models\Url;
use App\Models\User;
use App\Services\AiConfigHealer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\ScraperTrait;

class UrlCreateAiFallbackTest extends TestCase
{
    use RefreshDatabase;
    use ScraperTrait;

    const URL = 'https://newdomain.test/product/1';

    public function test_ai_builds_a_store_when_create_would_otherwise_fail(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $product = Product::factory()->create();

        // No store exists for newdomain.test, so the initial scrape finds nothing.
        // The (mocked) healer creates a working store; the re-scrape then succeeds.
        $this->mock(AiConfigHealer::class, fn ($m) => $m->shouldReceive('healStoreForUrl')
            ->once()
            ->andReturnUsing(fn ($url, $store, $html) => Store::factory()->create([
                'domains' => [['domain' => 'newdomain.test']],
            ])));

        $this->mockScrape(50, 'Healed Widget');

        $urlModel = Url::createFromUrl(self::URL, productId: $product->id, createStore: false);

        $this->assertInstanceOf(Url::class, $urlModel);
        $this->assertSame($product->id, $urlModel->product_id);
        $this->assertSame('newdomain.test', parse_url($urlModel->url, PHP_URL_HOST));
    }

    public function test_returns_false_and_creates_nothing_when_ai_cannot_heal(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->mock(AiConfigHealer::class, fn ($m) => $m->shouldReceive('healStoreForUrl')
            ->once()
            ->andReturnNull());

        $this->mockScrape('', '');

        $result = Url::createFromUrl(self::URL, createStore: false);

        $this->assertFalse($result);
        $this->assertDatabaseCount('stores', 0);
        $this->assertDatabaseCount('urls', 0);
    }
}

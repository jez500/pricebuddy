<?php

namespace Tests\Unit\Services;

use App\Models\Store;
use App\Services\MetaExtractionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MetaExtractionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_override_nested_cookies_are_promoted_to_the_store_model(): void
    {
        Store::factory()->create([
            'domains' => [
                ['domain' => 'example.com'],
            ],
        ]);

        $service = new class extends MetaExtractionService
        {
            public function resolveStoreForTest(string $url, array $storeOverride = []): ?Store
            {
                return $this->resolveStore($url, $storeOverride);
            }
        };

        $store = $service->resolveStoreForTest('https://example.com/product', [
            'settings' => [
                'cookies' => 'sessionid=abc123',
                'scraper_service' => 'http',
            ],
        ]);

        $this->assertInstanceOf(Store::class, $store);
        $this->assertSame('sessionid=abc123', $store->cookies);
    }
}

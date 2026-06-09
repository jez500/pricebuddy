<?php

namespace Tests\Feature\Models;

use App\Dto\AiExtractionResultDto;
use App\Models\Price;
use App\Models\Product;
use App\Models\Store;
use App\Models\Url;
use App\Services\AiConfigHealer;
use App\Services\AiExtractionService;
use App\Services\Helpers\SettingsHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Once;
use Tests\TestCase;

class UrlUpdatePriceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SettingsHelper::$settings = null;
        Cache::flush();
        Once::flush();

        // The healer is tested separately; make it a no-op here so these tests
        // focus purely on the AiScrapeEnhancer fallback path.
        $this->mock(AiConfigHealer::class, fn ($m) => $m->shouldReceive('heal')
            ->andReturnUsing(fn ($u, $r) => $r));
    }

    private function configureProviders(): void
    {
        SettingsHelper::setSetting('integrated_services', ['ai' => [
            'enabled' => true,
            'default_provider_id' => 'p1',
            'providers' => [[
                'id' => 'p1', 'name' => 'Local', 'type' => 'ollama',
                'base_url' => 'http://ai.example:11434', 'model' => 'm',
            ]],
        ]]);
        SettingsHelper::$settings = null;
        Cache::flush();
        Once::flush();
    }

    public function test_ai_backfills_price_when_scrape_finds_none(): void
    {
        $this->configureProviders();
        $this->mock(AiExtractionService::class, fn ($m) => $m->shouldReceive('extract')
            ->once()->andReturn(new AiExtractionResultDto(price: 9.99, confidence: 0.9)));
        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('withContext')->andReturnSelf();
        Log::shouldReceive('info');
        Log::shouldReceive('debug');

        $store = Store::factory()->create([
            'settings' => ['scraper_service' => 'http', 'ai_extraction_enabled' => true],
        ]);
        $url = Url::factory()->for(Product::factory())->for($store)->create();

        $price = $url->updatePrice(null, ['price' => null, 'body' => '<html>9.99</html>', 'availability' => null]);

        $this->assertInstanceOf(Price::class, $price);
        $this->assertSame(9.99, (float) $price->price);
    }

    public function test_no_price_recorded_when_ai_disabled_and_scrape_finds_none(): void
    {
        $this->mock(AiExtractionService::class, fn ($m) => $m->shouldReceive('extract')->never());

        $url = Url::factory()->for(Product::factory())->create();

        $price = $url->updatePrice(null, ['price' => null, 'body' => '<html>9.99</html>', 'availability' => null]);

        $this->assertNull($price);
        $this->assertDatabaseCount('prices', 0);
    }
}

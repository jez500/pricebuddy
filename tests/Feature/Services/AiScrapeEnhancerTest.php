<?php

namespace Tests\Feature\Services;

use App\Dto\AiExtractionResultDto;
use App\Models\Store;
use App\Models\Url;
use App\Services\AiExtractionService;
use App\Services\AiScrapeEnhancer;
use App\Services\Helpers\SettingsHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Once;
use Tests\TestCase;

class AiScrapeEnhancerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SettingsHelper::$settings = null;
        Cache::flush();
        Once::flush();
    }

    /**
     * Register one usable provider in app settings so the store's
     * ai_provider_id (or the global default) resolves to something.
     */
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

    /**
     * @param  array<string, mixed>  $settings
     */
    private function url(array $settings = ['ai_extraction_enabled' => true]): Url
    {
        $store = Store::factory()->create([
            'settings' => array_merge(['scraper_service' => 'http'], $settings),
        ]);

        return Url::factory()->for($store)->create();
    }

    public function test_leaves_result_untouched_when_a_price_is_present(): void
    {
        $this->configureProviders();
        $this->mock(AiExtractionService::class, fn ($m) => $m->shouldReceive('extract')->never());

        $result = AiScrapeEnhancer::new()->enhance($this->url(), ['price' => '12.99', 'body' => '<html>']);

        $this->assertSame('12.99', $result['price']);
    }

    public function test_does_nothing_when_store_ai_extraction_disabled(): void
    {
        $this->configureProviders();
        $this->mock(AiExtractionService::class, fn ($m) => $m->shouldReceive('extract')->never());

        $result = AiScrapeEnhancer::new()->enhance(
            $this->url(['ai_extraction_enabled' => false]),
            ['price' => null, 'body' => '<html>9.99</html>'],
        );

        $this->assertNull($result['price']);
    }

    public function test_does_nothing_when_no_provider_is_configured(): void
    {
        // Store opts in, but no providers exist in app settings.
        $this->mock(AiExtractionService::class, fn ($m) => $m->shouldReceive('extract')->never());

        $result = AiScrapeEnhancer::new()->enhance($this->url(), ['price' => null, 'body' => '<html>9.99</html>']);

        $this->assertNull($result['price']);
    }

    public function test_does_nothing_when_item_is_unavailable(): void
    {
        $this->configureProviders();
        $this->mock(AiExtractionService::class, fn ($m) => $m->shouldReceive('extract')->never());

        $result = AiScrapeEnhancer::new()->enhance(
            $this->url(),
            ['price' => null, 'body' => '<html>', 'availability' => 'Sold out'],
        );

        $this->assertNull($result['price']);
    }

    public function test_does_nothing_when_confidence_below_threshold(): void
    {
        $this->configureProviders();
        $this->mock(AiExtractionService::class, fn ($m) => $m->shouldReceive('extract')
            ->once()->andReturn(new AiExtractionResultDto(price: 9.99, confidence: 0.4)));
        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('withContext')->andReturnSelf();
        Log::shouldReceive('debug');

        $result = AiScrapeEnhancer::new()->enhance($this->url(), ['price' => null, 'body' => '<html>9.99</html>']);

        $this->assertNull($result['price']);
    }

    public function test_backfills_price_for_a_confident_result(): void
    {
        $this->configureProviders();
        $this->mock(AiExtractionService::class, fn ($m) => $m->shouldReceive('extract')
            ->once()->andReturn(new AiExtractionResultDto(price: 9.99, confidence: 0.82)));
        Log::shouldReceive('channel')->with('db')->andReturnSelf();
        Log::shouldReceive('withContext')->andReturnSelf();
        Log::shouldReceive('info')->once();

        $result = AiScrapeEnhancer::new()->enhance($this->url(), ['price' => null, 'body' => '<html>9.99</html>']);

        $this->assertSame(9.99, $result['price']);
    }

    public function test_does_nothing_when_extract_returns_null(): void
    {
        $this->configureProviders();
        $this->mock(AiExtractionService::class, fn ($m) => $m->shouldReceive('extract')
            ->once()->andReturnNull());
        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('withContext')->andReturnSelf();
        Log::shouldReceive('debug');

        $result = AiScrapeEnhancer::new()->enhance($this->url(), ['price' => null, 'body' => '<html>9.99</html>']);

        $this->assertNull($result['price']);
    }

    public function test_store_chosen_provider_id_threads_through_to_extract(): void
    {
        SettingsHelper::setSetting('integrated_services', ['ai' => [
            'enabled' => true,
            'default_provider_id' => 'p1',
            'providers' => [
                ['id' => 'p1', 'name' => 'Default', 'type' => 'ollama', 'model' => 'm'],
                ['id' => 'p2', 'name' => 'Chosen', 'type' => 'ollama', 'model' => 'm'],
            ],
        ]]);
        SettingsHelper::$settings = null;
        Cache::flush();
        Once::flush();

        $captured = null;
        $this->mock(AiExtractionService::class, function ($m) use (&$captured): void {
            $m->shouldReceive('extract')->once()
                ->andReturnUsing(function ($html, $schemaOrg = null, $provider = null) use (&$captured) {
                    $captured = $provider;

                    return new AiExtractionResultDto(price: 9.99, confidence: 0.9);
                });
        });
        Log::shouldReceive('channel')->with('db')->andReturnSelf();
        Log::shouldReceive('withContext')->andReturnSelf();
        Log::shouldReceive('info')->once();

        AiScrapeEnhancer::new()->enhance(
            $this->url(['ai_extraction_enabled' => true, 'ai_provider_id' => 'p2']),
            ['price' => null, 'body' => '<html>9.99</html>'],
        );

        $this->assertSame('p2', $captured?->id);
    }
}

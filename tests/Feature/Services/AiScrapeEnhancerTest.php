<?php

namespace Tests\Feature\Services;

use App\Dto\AiExtractionResultDto;
use App\Models\Product;
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

    private function enableAi(): void
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

    private function url(array $productAttrs = []): Url
    {
        $product = Product::factory()->create($productAttrs);

        return Url::factory()->for($product)->create();
    }

    public function test_leaves_result_untouched_when_a_price_is_present(): void
    {
        $this->enableAi();
        $this->mock(AiExtractionService::class, fn ($m) => $m->shouldReceive('extract')->never());

        $result = AiScrapeEnhancer::new()->enhance($this->url(), ['price' => '12.99', 'body' => '<html>']);

        $this->assertSame('12.99', $result['price']);
    }

    public function test_does_nothing_when_ai_is_disabled(): void
    {
        $this->mock(AiExtractionService::class, fn ($m) => $m->shouldReceive('extract')->never());

        $result = AiScrapeEnhancer::new()->enhance($this->url(), ['price' => null, 'body' => '<html>9.99</html>']);

        $this->assertNull($result['price']);
    }

    public function test_does_nothing_when_product_opted_out(): void
    {
        $this->enableAi();
        $this->mock(AiExtractionService::class, fn ($m) => $m->shouldReceive('extract')->never());

        $result = AiScrapeEnhancer::new()->enhance(
            $this->url(['ai_extraction_disabled' => true]),
            ['price' => null, 'body' => '<html>9.99</html>'],
        );

        $this->assertNull($result['price']);
    }

    public function test_does_nothing_when_item_is_unavailable(): void
    {
        $this->enableAi();
        $this->mock(AiExtractionService::class, fn ($m) => $m->shouldReceive('extract')->never());

        $result = AiScrapeEnhancer::new()->enhance(
            $this->url(),
            ['price' => null, 'body' => '<html>', 'availability' => 'Sold out'],
        );

        $this->assertNull($result['price']);
    }

    public function test_does_nothing_when_confidence_below_threshold(): void
    {
        $this->enableAi();
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
        $this->enableAi();
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
        $this->enableAi();
        $this->mock(AiExtractionService::class, fn ($m) => $m->shouldReceive('extract')
            ->once()->andReturnNull());
        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('withContext')->andReturnSelf();
        Log::shouldReceive('debug');

        $result = AiScrapeEnhancer::new()->enhance($this->url(), ['price' => null, 'body' => '<html>9.99</html>']);

        $this->assertNull($result['price']);
    }
}

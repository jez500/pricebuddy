<?php

namespace Tests\Feature\Services;

use App\Models\Store;
use App\Models\Url;
use App\Services\AiConfigHealer;
use App\Services\AiService;
use App\Services\Helpers\SettingsHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Once;
use Jez500\WebScraperForLaravel\Facades\WebScraper;
use Jez500\WebScraperForLaravel\WebScraperFake;
use Tests\TestCase;

class AiConfigHealerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        SettingsHelper::$settings = null;
        Cache::flush();
        Once::flush();

        // The deterministic step may escalate to a browser fetch; return non-structured
        // HTML so heuristics find nothing and the mocked agent path is exercised.
        WebScraper::shouldReceive('make')->andReturn((new WebScraperFake)->setBody($this->html()));
    }

    private function configureProviders(array $aiOverrides = []): void
    {
        SettingsHelper::setSetting('integrated_services', ['ai' => array_merge([
            'enabled' => true,
            'default_provider_id' => 'p1',
            'providers' => [[
                'id' => 'p1', 'name' => 'Local', 'type' => 'ollama',
                'base_url' => 'http://ai.example:11434', 'model' => 'm',
            ]],
        ], $aiOverrides)]);
        SettingsHelper::$settings = null;
        Cache::flush();
        Once::flush();
    }

    private function url(array $settings = []): Url
    {
        $store = Store::factory()->create([
            'scrape_strategy' => [],
            'settings' => array_merge(['scraper_service' => 'http'], $settings),
        ]);

        return Url::factory()->for($store)->create(['url' => 'https://shop.test/widget']);
    }

    private function html(): string
    {
        return '<html><body><div class="t">Widget</div><span id="pr">$12.99</span></body></html>';
    }

    private function mockAgent(?array $proposal, string $expectation = 'once'): void
    {
        $this->mock(AiService::class, fn ($m) => $m->shouldReceive('runAgent')->{$expectation}()->andReturn($proposal));
    }

    public function test_heals_config_applies_selectors_and_recovers_price(): void
    {
        $this->configureProviders();
        $this->mockAgent([
            'is_product' => true,
            'fields' => [
                'title' => ['type' => 'selector', 'value' => '.t'],
                'price' => ['type' => 'selector', 'value' => '#pr'],
            ],
        ]);
        $url = $this->url();

        $result = AiConfigHealer::new()->heal($url, ['price' => null, 'body' => $this->html(), 'availability' => null]);

        $this->assertSame('$12.99', $result['price']);
        $this->assertSame('Widget', $result['title']);
        $this->assertSame('#pr', data_get($url->store->fresh()->scrape_strategy, 'price.value'));
        $this->assertNull($url->store->fresh()->getAiHealFailedAt());
    }

    public function test_heal_merges_into_existing_strategy_without_dropping_other_fields(): void
    {
        $this->configureProviders();
        $this->mockAgent([
            'is_product' => true,
            'fields' => [
                'title' => ['type' => 'selector', 'value' => '.t'],
                'price' => ['type' => 'selector', 'value' => '#pr'],
            ],
        ]);
        $store = Store::factory()->create([
            'scrape_strategy' => ['image' => ['type' => 'selector', 'value' => 'img.hero|src']],
            'settings' => ['scraper_service' => 'http'],
        ]);
        $url = Url::factory()->for($store)->create(['url' => 'https://shop.test/widget']);

        AiConfigHealer::new()->heal($url, ['price' => null, 'body' => $this->html(), 'availability' => null]);

        $strategy = $store->fresh()->scrape_strategy;
        $this->assertSame('img.hero|src', data_get($strategy, 'image.value'));
        $this->assertSame('#pr', data_get($strategy, 'price.value'));
        $this->assertSame('.t', data_get($strategy, 'title.value'));
    }

    public function test_logs_when_a_heal_is_attempted(): void
    {
        $this->configureProviders();
        $this->mockAgent([
            'is_product' => true,
            'fields' => [
                'title' => ['type' => 'selector', 'value' => '.t'],
                'price' => ['type' => 'selector', 'value' => '#pr'],
            ],
        ]);
        $url = $this->url();

        AiConfigHealer::new()->heal($url, ['price' => null, 'body' => $this->html(), 'availability' => null]);

        $this->assertDatabaseHas('log_messages', [
            'message' => 'AI self-healing started; attempting to repair store scraper config.',
        ]);
    }

    public function test_no_op_when_price_already_present(): void
    {
        $this->configureProviders();
        $this->mockAgent([], 'never');

        $result = AiConfigHealer::new()->heal($this->url(), ['price' => '5.00', 'body' => $this->html()]);

        $this->assertSame('5.00', $result['price']);
    }

    public function test_skips_when_store_opted_out(): void
    {
        $this->configureProviders();
        $this->mockAgent([], 'never');

        $result = AiConfigHealer::new()->heal(
            $this->url(['ai_self_healing_disabled' => true]),
            ['price' => null, 'body' => $this->html()],
        );

        $this->assertNull($result['price']);
    }

    public function test_skips_when_feature_disabled_globally(): void
    {
        $this->configureProviders(['feature_providers' => ['healing' => '__disabled__']]);
        $this->mockAgent([], 'never');

        $result = AiConfigHealer::new()->heal($this->url(), ['price' => null, 'body' => $this->html()]);

        $this->assertNull($result['price']);
    }

    public function test_skips_when_within_cooldown(): void
    {
        $this->configureProviders();
        $this->mockAgent([], 'never');
        $url = $this->url();
        $url->store->markAiHealFailed();

        $result = AiConfigHealer::new()->heal($url, ['price' => null, 'body' => $this->html()]);

        $this->assertNull($result['price']);
    }

    public function test_skips_when_out_of_stock(): void
    {
        $this->configureProviders();
        $this->mockAgent([], 'never');

        $result = AiConfigHealer::new()->heal(
            $this->url(),
            ['price' => null, 'body' => $this->html(), 'availability' => 'OutOfStock'],
        );

        $this->assertNull($result['price']);
    }

    public function test_marks_failure_and_keeps_config_when_required_fields_do_not_validate(): void
    {
        $this->configureProviders();
        $this->mockAgent([
            'is_product' => true,
            'fields' => [
                'title' => ['type' => 'selector', 'value' => '.does-not-exist'],
                'price' => ['type' => 'selector', 'value' => '#nope'],
            ],
        ]);
        $url = $this->url();

        $result = AiConfigHealer::new()->heal($url, ['price' => null, 'body' => $this->html(), 'availability' => null]);

        $this->assertNull($result['price']);
        $this->assertSame([], $url->store->fresh()->scrape_strategy->toArray());
        $this->assertNotNull($url->store->fresh()->getAiHealFailedAt());
    }

    public function test_skips_when_store_lock_is_held(): void
    {
        $this->configureProviders();
        $this->mockAgent([], 'never');
        $url = $this->url();
        Cache::lock('ai-heal:store:'.$url->store->getKey(), 120)->get();

        $result = AiConfigHealer::new()->heal($url, ['price' => null, 'body' => $this->html(), 'availability' => null]);

        $this->assertNull($result['price']);
    }

    public function test_skips_and_marks_failure_when_agent_says_not_a_product(): void
    {
        $this->configureProviders();
        $this->mockAgent([
            'is_product' => false,
            'fields' => [
                'title' => ['type' => 'selector', 'value' => '.t'],
                'price' => ['type' => 'selector', 'value' => '#pr'],
            ],
        ]);
        $url = $this->url();

        $result = AiConfigHealer::new()->heal($url, ['price' => null, 'body' => $this->html(), 'availability' => null]);

        $this->assertNull($result['price']);
        $this->assertSame([], $url->store->fresh()->scrape_strategy->toArray());
        $this->assertNotNull($url->store->fresh()->getAiHealFailedAt());
    }

    public function test_marks_failure_when_agent_returns_null(): void
    {
        $this->configureProviders();
        $this->mockAgent(null);
        $url = $this->url();

        $result = AiConfigHealer::new()->heal($url, ['price' => null, 'body' => $this->html(), 'availability' => null]);

        $this->assertNull($result['price']);
        $this->assertSame([], $url->store->fresh()->scrape_strategy->toArray());
        $this->assertNotNull($url->store->fresh()->getAiHealFailedAt());
    }

    public function test_apply_preview_to_store_merges_fields_in_memory_without_saving(): void
    {
        $store = Store::factory()->create([
            'scrape_strategy' => ['image' => ['type' => 'selector', 'value' => 'img|src']],
            'settings' => ['scraper_service' => 'http'],
        ]);

        AiConfigHealer::new()->applyPreviewToStore($store, [
            'fields' => ['price' => ['type' => 'selector', 'value' => '#pr']],
            'usedBrowser' => true,
        ]);

        // In memory: merged with the existing field, switched to the browser scraper.
        $this->assertSame('#pr', data_get($store->scrape_strategy, 'price.value'));
        $this->assertSame('img|src', data_get($store->scrape_strategy, 'image.value'));
        $this->assertSame('api', data_get($store->settings, 'scraper_service'));

        // Not persisted: the DB row is unchanged.
        $fresh = $store->fresh();
        $this->assertSame(['image' => ['type' => 'selector', 'value' => 'img|src']], $fresh->scrape_strategy->toArray());
        $this->assertSame('http', data_get($fresh->settings, 'scraper_service'));
    }
}

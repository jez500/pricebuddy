<?php

namespace Tests\Feature\Services;

use App\Models\Store;
use App\Services\AiConfigHealer;
use App\Services\AiService;
use App\Services\Helpers\SettingsHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Once;
use Jez500\WebScraperForLaravel\Facades\WebScraper;
use Jez500\WebScraperForLaravel\WebScraperFake;
use Laravel\Ai\Tools\Request;
use Tests\TestCase;

class AiConfigHealerBootstrapTest extends TestCase
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

    private function mockAgent(?array $proposal, string $expectation = 'once'): void
    {
        $this->mock(AiService::class, fn ($m) => $m->shouldReceive('runAgent')->{$expectation}()->andReturn($proposal));
    }

    private function html(): string
    {
        return '<html><body><div class="t">Widget</div><span id="pr">$12.99</span></body></html>';
    }

    private function validProposal(): array
    {
        return [
            'is_product' => true,
            'fields' => [
                'title' => ['type' => 'selector', 'value' => '.t'],
                'price' => ['type' => 'selector', 'value' => '#pr'],
            ],
        ];
    }

    public function test_creates_a_new_store_from_ai_selectors_when_none_exists(): void
    {
        $this->configureProviders();
        $this->mockAgent($this->validProposal());

        $store = AiConfigHealer::new()->healStoreForUrl('https://shop.test/widget', null, $this->html());

        $this->assertInstanceOf(Store::class, $store);
        $this->assertTrue($store->exists);
        $this->assertSame('#pr', data_get($store->scrape_strategy, 'price.value'));
        $this->assertSame('.t', data_get($store->scrape_strategy, 'title.value'));
        $this->assertSame('Shop.test', $store->name);
        $this->assertContains('shop.test', collect($store->domains)->pluck('domain')->all());
        // Deterministic escalation always runs before the agent, so usedBrowser is true
        // and the store is switched to the browser scraper.
        $this->assertSame('api', data_get($store->settings, 'scraper_service'));
    }

    /**
     * Mock the agent so that, while running, it calls the fetch tool with rendered=true
     * (simulating a switch to browser scraping), backed by a faked scraper.
     */
    private function mockAgentUsingBrowser(array $proposal): void
    {
        $this->mock(AiService::class, function ($m) use ($proposal) {
            $m->shouldReceive('runAgent')->once()->andReturnUsing(function ($instructions, $schema, $prompt, $tools) use ($proposal) {
                $tools[0]->handle(new Request(['rendered' => true]));

                return $proposal;
            });
        });
    }

    public function test_new_store_uses_browser_scraper_when_agent_switched_to_rendered(): void
    {
        $this->configureProviders();
        $this->mockAgentUsingBrowser($this->validProposal());

        $store = AiConfigHealer::new()->healStoreForUrl('https://shop.test/widget', null, null);

        $this->assertInstanceOf(Store::class, $store);
        $this->assertSame('api', data_get($store->settings, 'scraper_service'));
        $this->assertSame('#pr', data_get($store->scrape_strategy, 'price.value'));
    }

    public function test_existing_store_switched_to_browser_when_agent_used_rendered(): void
    {
        $this->configureProviders();
        $this->mockAgentUsingBrowser($this->validProposal());
        $store = Store::factory()->create([
            'domains' => [['domain' => 'shop.test']],
            'settings' => ['scraper_service' => 'http'],
        ]);

        AiConfigHealer::new()->healStoreForUrl('https://shop.test/widget', $store, null);

        $this->assertSame('api', data_get($store->fresh()->settings, 'scraper_service'));
    }

    public function test_repairs_an_existing_store(): void
    {
        $this->configureProviders();
        $this->mockAgent($this->validProposal());
        $store = Store::factory()->create([
            'domains' => [['domain' => 'shop.test']],
            'scrape_strategy' => ['image' => ['type' => 'selector', 'value' => 'img|src']],
            'settings' => ['scraper_service' => 'http'],
        ]);

        $returned = AiConfigHealer::new()->healStoreForUrl('https://shop.test/widget', $store, $this->html());

        $this->assertSame($store->getKey(), $returned?->getKey());
        $this->assertSame('#pr', data_get($store->fresh()->scrape_strategy, 'price.value'));
        $this->assertSame('img|src', data_get($store->fresh()->scrape_strategy, 'image.value'));
    }

    public function test_returns_null_and_creates_nothing_when_healing_disabled_globally(): void
    {
        $this->configureProviders(['feature_providers' => ['healing' => '__disabled__']]);
        $this->mockAgent(null, 'never');

        $store = AiConfigHealer::new()->healStoreForUrl('https://shop.test/widget', null, $this->html());

        $this->assertNull($store);
        $this->assertDatabaseCount('stores', 0);
    }

    public function test_returns_null_when_existing_store_opted_out(): void
    {
        $this->configureProviders();
        $this->mockAgent(null, 'never');
        $store = Store::factory()->create([
            'domains' => [['domain' => 'shop.test']],
            'settings' => ['scraper_service' => 'http', 'ai_self_healing_disabled' => true],
        ]);

        $this->assertNull(AiConfigHealer::new()->healStoreForUrl('https://shop.test/widget', $store, $this->html()));
    }

    public function test_persists_no_store_when_ai_fails_for_new_domain(): void
    {
        $this->configureProviders();
        $this->mockAgent(null);

        $store = AiConfigHealer::new()->healStoreForUrl('https://shop.test/widget', null, $this->html());

        $this->assertNull($store);
        $this->assertDatabaseCount('stores', 0);
    }

    public function test_sets_cooldown_when_ai_fails_for_existing_store(): void
    {
        $this->configureProviders();
        $this->mockAgent(null);
        $store = Store::factory()->create([
            'domains' => [['domain' => 'shop.test']],
            'settings' => ['scraper_service' => 'http'],
        ]);

        $this->assertNull(AiConfigHealer::new()->healStoreForUrl('https://shop.test/widget', $store, $this->html()));
        $this->assertNotNull($store->fresh()->getAiHealFailedAt());
    }

    public function test_returns_null_when_domain_lock_is_held(): void
    {
        $this->configureProviders();
        $this->mockAgent(null, 'never');

        // Pre-acquire the same per-domain lock healStoreForUrl will try to take.
        Cache::lock('ai-heal:store:shop.test', 120)->get();

        $store = AiConfigHealer::new()->healStoreForUrl('https://shop.test/widget', null, $this->html());

        $this->assertNull($store);
        $this->assertDatabaseCount('stores', 0);
    }

    public function test_preview_returns_proposal_without_persisting(): void
    {
        $this->configureProviders();
        $this->mockAgent($this->validProposal());

        $preview = AiConfigHealer::new()->previewForUrl('https://shop.test/widget', null, $this->html());

        $this->assertSame('#pr', data_get($preview, 'fields.price.value'));
        $this->assertSame('.t', data_get($preview, 'fields.title.value'));
        $this->assertSame('$12.99', data_get($preview, 'extracted.price'));
        // Deterministic escalation always runs before the agent, so usedBrowser is true.
        $this->assertTrue(data_get($preview, 'usedBrowser'));
        $this->assertDatabaseCount('stores', 0);
    }

    public function test_preview_reports_used_browser_and_persists_nothing(): void
    {
        $this->configureProviders();
        $this->mockAgentUsingBrowser($this->validProposal());

        $preview = AiConfigHealer::new()->previewForUrl('https://shop.test/widget', null, null);

        $this->assertTrue(data_get($preview, 'usedBrowser'));
        $this->assertDatabaseCount('stores', 0);
    }

    public function test_preview_returns_null_when_healing_disabled(): void
    {
        $this->configureProviders(['feature_providers' => ['healing' => '__disabled__']]);
        $this->mockAgent(null, 'never');

        $this->assertNull(AiConfigHealer::new()->previewForUrl('https://shop.test/widget', null, $this->html()));
    }
}

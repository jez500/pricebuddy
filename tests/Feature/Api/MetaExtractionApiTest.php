<?php

namespace Tests\Feature\Api;

use App\Models\Store;
use App\Models\User;
use App\Services\AiService;
use App\Services\Helpers\SettingsHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Once;
use Jez500\WebScraperForLaravel\Facades\WebScraper;
use Jez500\WebScraperForLaravel\WebScraperFake;
use Tests\TestCase;
use Tests\Traits\ScraperTrait;

class MetaExtractionApiTest extends TestCase
{
    use RefreshDatabase;
    use ScraperTrait;

    protected User $user;

    protected string $url = 'https://example.com/product/123';

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $token = $this->user->createToken('test-token')->plainTextToken;
        $this->withHeaders(['Authorization' => 'Bearer '.$token]);

        SettingsHelper::$settings = null;
        Cache::flush();
        Once::flush();
    }

    public function test_can_extract_meta_using_domain_store_configuration(): void
    {
        Store::factory()->create([
            'user_id' => $this->user->id,
            'domains' => [
                ['domain' => parse_url($this->url, PHP_URL_HOST)],
            ],
            'settings' => [
                'scraper_service' => 'http',
                'scraper_service_settings' => '',
            ],
            'scrape_strategy' => [
                'title' => [
                    'type' => 'selector',
                    'value' => 'meta[property="og:title"]|content',
                ],
                'price' => [
                    'type' => 'selector',
                    'value' => 'meta[property="og:price:amount"]|content',
                ],
                'image' => [
                    'type' => 'selector',
                    'value' => 'meta[property="og:image"]|content',
                ],
            ],
        ]);

        $this->mockScrape('$35.00', 'Example product', 'https://example.com/image.jpg');

        $response = $this->postJson('/api/meta-extraction', [
            'url' => $this->url,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.title', 'Example product')
            ->assertJsonPath('data.price', 35)
            ->assertJsonPath('data.image', 'https://example.com/image.jpg');
    }

    public function test_can_extract_meta_using_store_override(): void
    {
        $this->mockScrape('$19.99', 'Override product', 'https://example.com/override.jpg');

        $response = $this->postJson('/api/meta-extraction', [
            'url' => $this->url,
            'store' => [
                'settings' => [
                    'scraper_service' => 'http',
                    'scraper_service_settings' => '',
                ],
                'scrape_strategy' => [
                    'title' => [
                        'type' => 'selector',
                        'value' => 'meta[property="og:title"]|content',
                    ],
                    'price' => [
                        'type' => 'selector',
                        'value' => 'meta[property="og:price:amount"]|content',
                    ],
                    'image' => [
                        'type' => 'selector',
                        'value' => 'meta[property="og:image"]|content',
                    ],
                ],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.title', 'Override product')
            ->assertJsonPath('data.price', 19.99)
            ->assertJsonPath('data.image', 'https://example.com/override.jpg');
    }

    public function test_can_extract_meta_using_schema_org_strategies_with_null_values(): void
    {
        $this->mockScrapeSchema('$42.50', 'Schema product', 'https://example.com/schema.jpg');

        $response = $this->postJson('/api/meta-extraction', [
            'url' => $this->url,
            'store' => [
                'settings' => [
                    'scraper_service' => 'http',
                    'scraper_service_settings' => '',
                ],
                'scrape_strategy' => [
                    'title' => [
                        'type' => 'schema_org',
                        'value' => null,
                    ],
                    'price' => [
                        'type' => 'schema_org',
                        'value' => null,
                    ],
                    'image' => [
                        'type' => 'schema_org',
                        'value' => null,
                    ],
                ],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.title', 'Schema product')
            ->assertJsonPath('data.price', 42.5)
            ->assertJsonPath('data.image', 'https://example.com/schema.jpg');
    }

    public function test_auto_create_path_normalizes_the_price_output(): void
    {
        $this->mockScrape('$35.00', 'Example product', 'https://example.com/image.jpg');

        $response = $this->postJson('/api/meta-extraction', [
            'url' => $this->url,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.title', 'Example product')
            ->assertJsonPath('data.price', 35)
            ->assertJsonPath('data.image', 'https://example.com/image.jpg');
    }

    public function test_auto_create_path_returns_the_detected_store(): void
    {
        $this->mockScrape('$35.00', 'Example product', 'https://example.com/image.jpg');

        $response = $this->postJson('/api/meta-extraction', [
            'url' => $this->url,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.title', 'Example product')
            ->assertJsonStructure([
                'data' => [
                    'store' => [
                        'name',
                        'domains',
                        'scrape_settings' => ['title', 'price'],
                    ],
                ],
            ])
            ->assertJsonPath('data.store.name', 'Example.com');

        $domains = collect($response->json('data.store.domains'))->pluck('domain');
        $this->assertTrue($domains->contains('example.com'));
    }

    public function test_validation_fails_without_a_url(): void
    {
        $response = $this->postJson('/api/meta-extraction', []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['url']);
    }

    public function test_validation_rejects_internal_and_non_http_urls(): void
    {
        foreach (['http://169.254.169.254/latest/meta-data/', 'http://127.0.0.1/', 'file:///etc/passwd'] as $url) {
            $this->postJson('/api/meta-extraction', ['url' => $url])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['url']);
        }
    }

    public function test_heals_an_existing_store_via_the_agent_when_scrape_finds_no_price(): void
    {
        $this->configureProviders();
        $this->fakeHtml($this->healHtml());
        $this->mockAgent([
            'is_product' => true,
            'fields' => [
                'title' => ['type' => 'selector', 'value' => '.t'],
                'price' => ['type' => 'selector', 'value' => '#pr'],
            ],
        ]);

        Store::factory()->create([
            'user_id' => $this->user->id,
            'domains' => [['domain' => parse_url($this->url, PHP_URL_HOST)]],
            'scrape_strategy' => [],
            'settings' => ['scraper_service' => 'http'],
        ]);

        $response = $this->postJson('/api/meta-extraction', ['url' => $this->url]);

        $response->assertOk()
            ->assertJsonPath('data.title', 'Widget')
            ->assertJsonPath('data.price', 12.99)
            ->assertJsonPath('data.store.scrape_settings.price.value', '#pr');
    }

    public function test_heals_an_existing_store_deterministically_without_the_agent(): void
    {
        $this->configureProviders();
        $this->mockScrape('$35.00', 'Example product', 'https://example.com/image.jpg');
        $this->mockAgent([], 'never');

        Store::factory()->create([
            'user_id' => $this->user->id,
            'domains' => [['domain' => parse_url($this->url, PHP_URL_HOST)]],
            'scrape_strategy' => [
                'price' => ['type' => 'selector', 'value' => '.no-such-price'],
            ],
            'settings' => ['scraper_service' => 'http'],
        ]);

        $response = $this->postJson('/api/meta-extraction', ['url' => $this->url]);

        $response->assertOk()
            ->assertJsonPath('data.price', 35)
            ->assertJsonStructure(['data' => ['store' => ['scrape_settings' => ['price']]]]);
    }

    public function test_does_not_heal_when_the_scrape_already_found_a_price(): void
    {
        $this->configureProviders();
        $this->mockScrape('$35.00', 'Example product', 'https://example.com/image.jpg');
        $this->mockAgent([], 'never');

        Store::factory()->create([
            'user_id' => $this->user->id,
            'domains' => [['domain' => parse_url($this->url, PHP_URL_HOST)]],
            'settings' => ['scraper_service' => 'http'],
            'scrape_strategy' => [
                'title' => ['type' => 'selector', 'value' => 'meta[property="og:title"]|content'],
                'price' => ['type' => 'selector', 'value' => 'meta[property="og:price:amount"]|content'],
                'image' => ['type' => 'selector', 'value' => 'meta[property="og:image"]|content'],
            ],
        ]);

        $response = $this->postJson('/api/meta-extraction', ['url' => $this->url]);

        $response->assertOk()->assertJsonPath('data.price', 35);
    }

    public function test_does_not_heal_when_store_opted_out(): void
    {
        $this->configureProviders();
        $this->fakeHtml($this->healHtml());
        $this->mockAgent([], 'never');

        Store::factory()->create([
            'user_id' => $this->user->id,
            'domains' => [['domain' => parse_url($this->url, PHP_URL_HOST)]],
            'scrape_strategy' => [],
            'settings' => ['scraper_service' => 'http', 'ai_self_healing_disabled' => true],
        ]);

        $response = $this->postJson('/api/meta-extraction', ['url' => $this->url]);

        $response->assertOk()->assertJsonPath('data.price', null);
    }

    public function test_does_not_heal_when_healing_feature_is_disabled(): void
    {
        $this->configureProviders(['feature_providers' => ['healing' => '__disabled__']]);
        $this->fakeHtml($this->healHtml());
        $this->mockAgent([], 'never');

        Store::factory()->create([
            'user_id' => $this->user->id,
            'domains' => [['domain' => parse_url($this->url, PHP_URL_HOST)]],
            'scrape_strategy' => [],
            'settings' => ['scraper_service' => 'http'],
        ]);

        $response = $this->postJson('/api/meta-extraction', ['url' => $this->url]);

        $response->assertOk()->assertJsonPath('data.price', null);
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

    /**
     * HTML the deterministic heuristics do NOT recognise, but a `.t` / `#pr` selector does,
     * so the mocked agent path is exercised.
     */
    private function healHtml(): string
    {
        return '<html><body><div class="t">Widget</div><span id="pr">$12.99</span></body></html>';
    }

    private function fakeHtml(string $html): void
    {
        WebScraper::shouldReceive('make')->andReturn((new WebScraperFake)->setBody($html));
    }
}

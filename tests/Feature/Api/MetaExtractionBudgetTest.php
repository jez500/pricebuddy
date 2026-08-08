<?php

namespace Tests\Feature\Api;

use App\Enums\AiFeature;
use App\Exceptions\AiProviderException;
use App\Models\Store;
use App\Models\User;
use App\Services\Ai\AiProviderHealth;
use App\Services\AiService;
use App\Services\Helpers\IntegrationHelper;
use App\Services\Helpers\SettingsHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Once;
use Tests\TestCase;
use Tests\Traits\ScraperTrait;

/**
 * POST /api/meta-extraction is synchronous and user-facing, so AI healing must be opt-in
 * and the whole request must be bounded. A slow or wedged provider has to cost the caller
 * the budget at most, and must never turn a working extraction into an error.
 */
class MetaExtractionBudgetTest extends TestCase
{
    use RefreshDatabase;
    use ScraperTrait;

    protected User $user;

    protected string $url = 'https://example.com/product/123';

    /** Kept small so a test that waits out the budget stays quick. */
    private const int BUDGET = 2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $token = $this->user->createToken('test-token')->plainTextToken;
        $this->withHeaders(['Authorization' => 'Bearer '.$token]);

        SettingsHelper::$settings = null;
        Cache::flush();
        Once::flush();

        config()->set('price_buddy.meta_extraction.budget_seconds', self::BUDGET);
        config()->set('price_buddy.meta_extraction.heal_floor_seconds', 1);
    }

    public function test_healing_is_not_attempted_by_default(): void
    {
        $this->configureProviders();
        $this->fakeHtml($this->healHtml());
        $this->mockAgent([], 'never');
        $this->storeWithMissingSelectors();

        $this->postJson('/api/meta-extraction', ['url' => $this->url])
            ->assertOk()
            ->assertJsonPath('data.healing.attempted', false)
            ->assertJsonPath('data.healing.applied', false)
            ->assertJsonPath('data.healing.reason', 'disabled');
    }

    public function test_healing_runs_and_is_applied_when_opted_in(): void
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
        $this->storeWithMissingSelectors();

        $this->postJson('/api/meta-extraction', ['url' => $this->url, 'heal' => true])
            ->assertOk()
            ->assertJsonPath('data.price', 12.99)
            ->assertJsonPath('data.healing.attempted', true)
            ->assertJsonPath('data.healing.applied', true)
            ->assertJsonPath('data.healing.reason', null);
    }

    public function test_healing_reports_not_needed_when_the_scrape_found_a_price(): void
    {
        $this->configureProviders();
        $this->mockScrape('$35.00', 'Example product', 'https://example.com/image.jpg');
        $this->mockAgent([], 'never');

        Store::factory()->create([
            'user_id' => $this->user->id,
            'domains' => [['domain' => parse_url($this->url, PHP_URL_HOST)]],
            'settings' => ['scraper_service' => 'http'],
        ]);

        $this->postJson('/api/meta-extraction', ['url' => $this->url, 'heal' => true])
            ->assertOk()
            ->assertJsonPath('data.price', 35)
            ->assertJsonPath('data.healing.attempted', false)
            ->assertJsonPath('data.healing.reason', 'not_needed');
    }

    public function test_healing_reports_disabled_when_the_store_opted_out(): void
    {
        $this->configureProviders();
        $this->fakeHtml($this->healHtml());
        $this->mockAgent([], 'never');
        $this->storeWithMissingSelectors(['ai_self_healing_disabled' => true]);

        $this->postJson('/api/meta-extraction', ['url' => $this->url, 'heal' => true])
            ->assertOk()
            ->assertJsonPath('data.healing.attempted', false)
            ->assertJsonPath('data.healing.reason', 'disabled');
    }

    public function test_a_provider_that_outlasts_the_budget_returns_the_deterministic_result(): void
    {
        $this->configureProviders();
        $this->fakeHtml($this->healHtml());
        // Simulates a wedged provider: blocks for exactly the timeout it was handed, then
        // fails. If the request passed the provider's own timeout instead of the remaining
        // budget, this test would block for that long and the elapsed assertion would fail.
        $this->mockAgentThatBlocksForItsTimeout();
        $this->storeWithMissingSelectors();

        $startedAt = microtime(true);

        $response = $this->postJson('/api/meta-extraction', ['url' => $this->url, 'heal' => true]);

        $elapsed = microtime(true) - $startedAt;

        $response->assertOk()
            ->assertJsonPath('data.price', null)
            ->assertJsonPath('data.healing.attempted', true)
            ->assertJsonPath('data.healing.applied', false)
            ->assertJsonPath('data.healing.reason', 'timeout');

        $this->assertLessThan(self::BUDGET + 3, $elapsed, 'The request outlived its budget.');
    }

    public function test_the_ai_call_is_given_the_remaining_budget_as_its_timeout(): void
    {
        $this->configureProviders();
        $this->fakeHtml($this->healHtml());

        $timeout = null;
        $this->mock(AiService::class, function ($mock) use (&$timeout) {
            $mock->shouldReceive('runAgent')->once()->andReturnUsing(function (...$args) use (&$timeout) {
                $timeout = $args[6] ?? null;

                throw new AiProviderException('simulated provider failure');
            });
        });

        $this->storeWithMissingSelectors();

        $this->postJson('/api/meta-extraction', ['url' => $this->url, 'heal' => true])->assertOk();

        $this->assertNotNull($timeout, 'No timeout was passed to the agent.');
        $this->assertGreaterThan(0, $timeout);
        $this->assertLessThanOrEqual(self::BUDGET, $timeout);
    }

    public function test_a_provider_that_fails_fast_is_reported_as_an_error_not_a_timeout(): void
    {
        $this->configureProviders();
        $this->fakeHtml($this->healHtml());
        $this->mock(AiService::class, fn ($mock) => $mock->shouldReceive('runAgent')->once()
            ->andThrow(new AiProviderException('bad credentials')));
        $this->storeWithMissingSelectors();

        $this->postJson('/api/meta-extraction', ['url' => $this->url, 'heal' => true])
            ->assertOk()
            ->assertJsonPath('data.healing.attempted', true)
            ->assertJsonPath('data.healing.applied', false)
            ->assertJsonPath('data.healing.reason', 'error');
    }

    public function test_healing_is_skipped_when_too_little_budget_remains(): void
    {
        // A floor above the whole budget can never be met, which is the same state a
        // request reaches when the scrape has already spent everything.
        config()->set('price_buddy.meta_extraction.heal_floor_seconds', self::BUDGET + 10);

        $this->configureProviders();
        $this->fakeHtml($this->healHtml());
        $this->mockAgent([], 'never');
        $this->storeWithMissingSelectors();

        $this->postJson('/api/meta-extraction', ['url' => $this->url, 'heal' => true])
            ->assertOk()
            ->assertJsonPath('data.healing.attempted', false)
            ->assertJsonPath('data.healing.applied', false)
            ->assertJsonPath('data.healing.reason', 'timeout');
    }

    public function test_the_auto_create_path_honours_the_same_budget(): void
    {
        // No store matches this URL, which is the first-run case and the one most likely
        // to reach healing.
        $this->configureProviders();
        $this->fakeHtml($this->healHtml());
        $this->mockAgentThatBlocksForItsTimeout();

        $startedAt = microtime(true);

        $response = $this->postJson('/api/meta-extraction', ['url' => $this->url, 'heal' => true]);

        $elapsed = microtime(true) - $startedAt;

        $response->assertOk()
            ->assertJsonPath('data.healing.attempted', true)
            ->assertJsonPath('data.healing.reason', 'timeout');

        $this->assertEmpty($response->json('data.store'));
        $this->assertLessThan(self::BUDGET + 3, $elapsed, 'The request outlived its budget.');
    }

    public function test_a_provider_with_an_open_breaker_is_not_tried_at_all(): void
    {
        $this->configureProviders();
        $this->fakeHtml($this->healHtml());
        $this->storeWithMissingSelectors();
        $this->openTheBreaker();

        // The whole point: a provider already known to be failing must not cost this
        // request its budget to discover that again.
        $this->mockAgent([], 'never');

        $startedAt = microtime(true);

        $this->postJson('/api/meta-extraction', ['url' => $this->url, 'heal' => true])
            ->assertOk()
            ->assertJsonPath('data.healing.attempted', false)
            ->assertJsonPath('data.healing.applied', false)
            ->assertJsonPath('data.healing.reason', 'provider_unavailable');

        $this->assertLessThan(
            self::BUDGET,
            microtime(true) - $startedAt,
            'The request still paid for a provider already known to be failing.'
        );
    }

    public function test_the_provider_is_tried_again_once_the_cooldown_lapses(): void
    {
        config()->set('price_buddy.ai_provider_breaker.cooldown_seconds', 60);

        $this->configureProviders();
        $this->fakeHtml($this->healHtml());
        $this->storeWithMissingSelectors();
        $this->openTheBreaker();

        $this->mockAgent([
            'is_product' => true,
            'fields' => [
                'title' => ['type' => 'selector', 'value' => '.t'],
                'price' => ['type' => 'selector', 'value' => '#pr'],
            ],
        ]);

        $this->travel(61)->seconds();

        $this->postJson('/api/meta-extraction', ['url' => $this->url, 'heal' => true])
            ->assertOk()
            ->assertJsonPath('data.healing.applied', true)
            ->assertJsonPath('data.price', 12.99);
    }

    public function test_the_breaker_does_not_affect_a_request_that_is_not_healing(): void
    {
        $this->configureProviders();
        $this->mockScrape('$35.00', 'Example product', 'https://example.com/image.jpg');
        $this->mockAgent([], 'never');
        $this->openTheBreaker();

        Store::factory()->create([
            'user_id' => $this->user->id,
            'domains' => [['domain' => parse_url($this->url, PHP_URL_HOST)]],
            'settings' => ['scraper_service' => 'http'],
        ]);

        $this->postJson('/api/meta-extraction', ['url' => $this->url, 'heal' => true])
            ->assertOk()
            ->assertJsonPath('data.price', 35)
            ->assertJsonPath('data.healing.reason', 'not_needed');
    }

    /**
     * Drive the real health tracker to the failure threshold for the configured provider.
     * Feature tests mock AiService wholesale, which is where failures are recorded, so
     * the breaker is opened directly here; AiProviderHealthTest covers the counting and
     * AiServiceHealthTest covers AiService feeding it.
     */
    private function openTheBreaker(): void
    {
        $provider = IntegrationHelper::resolveFeatureProvider(AiFeature::Healing);
        $health = AiProviderHealth::new();

        for ($i = 0; $i < $health->failureThreshold(); $i++) {
            $health->recordFailure($provider);
        }
    }

    public function test_healing_is_reported_on_a_plain_successful_extraction(): void
    {
        $this->mockScrape('$35.00', 'Example product', 'https://example.com/image.jpg');

        Store::factory()->create([
            'user_id' => $this->user->id,
            'domains' => [['domain' => parse_url($this->url, PHP_URL_HOST)]],
            'settings' => ['scraper_service' => 'http'],
        ]);

        $this->postJson('/api/meta-extraction', ['url' => $this->url])
            ->assertOk()
            ->assertJsonStructure(['data' => ['healing' => ['attempted', 'applied', 'reason']]]);
    }

    public function test_heal_must_be_a_boolean(): void
    {
        $this->postJson('/api/meta-extraction', ['url' => $this->url, 'heal' => 'maybe'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['heal']);
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function storeWithMissingSelectors(array $settings = []): Store
    {
        return Store::factory()->create([
            'user_id' => $this->user->id,
            'domains' => [['domain' => parse_url($this->url, PHP_URL_HOST)]],
            'scrape_strategy' => [],
            'settings' => array_merge(['scraper_service' => 'http'], $settings),
        ]);
    }

    /**
     * @param  array<string, mixed>  $aiOverrides
     */
    private function configureProviders(array $aiOverrides = []): void
    {
        SettingsHelper::setSetting('integrated_services', ['ai' => array_merge([
            'enabled' => true,
            'default_provider_id' => 'p1',
            'providers' => [[
                'id' => 'p1', 'name' => 'Local', 'type' => 'ollama',
                'base_url' => 'http://ai.example:11434', 'model' => 'm',
                'timeout_seconds' => 120,
            ]],
        ], $aiOverrides)]);
        SettingsHelper::$settings = null;
        Cache::flush();
        Once::flush();
    }

    /**
     * @param  array<string, mixed>|null  $proposal
     */
    private function mockAgent(?array $proposal, string $expectation = 'once'): void
    {
        $this->mock(AiService::class, fn ($mock) => $mock->shouldReceive('runAgent')->{$expectation}()->andReturn($proposal));
    }

    private function mockAgentThatBlocksForItsTimeout(): void
    {
        $this->mock(AiService::class, function ($mock) {
            $mock->shouldReceive('runAgent')->once()->andReturnUsing(function (...$args) {
                usleep((int) (($args[6] ?? 120) * 1_000_000));

                throw new AiProviderException('simulated provider timeout');
            });
        });
    }

    /**
     * HTML the deterministic heuristics do NOT recognise, but a `.t` / `#pr` selector does,
     * so the agent path is exercised.
     */
    private function healHtml(): string
    {
        return '<html><body><div class="t">Widget</div><span id="pr">$12.99</span></body></html>';
    }

    private function fakeHtml(string $html): void
    {
        \Jez500\WebScraperForLaravel\Facades\WebScraper::shouldReceive('make')
            ->andReturn((new \Jez500\WebScraperForLaravel\WebScraperFake)->setBody($html));
    }
}

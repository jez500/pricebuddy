<?php

namespace Tests\Unit\Services;

use App\Dto\AiProviderConfigDto;
use App\Enums\AiProvider;
use App\Services\Ai\AiProviderHealth;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class AiProviderHealthTest extends TestCase
{
    private AiProviderHealth $health;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config()->set('price_buddy.ai_provider_breaker.failure_threshold', 3);
        config()->set('price_buddy.ai_provider_breaker.cooldown_seconds', 300);

        $this->health = new AiProviderHealth;
    }

    public function test_a_healthy_provider_is_available(): void
    {
        $this->assertFalse($this->health->isUnavailable($this->provider()));
    }

    public function test_the_breaker_opens_only_at_the_threshold(): void
    {
        $provider = $this->provider();

        $this->health->recordFailure($provider);
        $this->health->recordFailure($provider);

        $this->assertFalse($this->health->isUnavailable($provider), 'Opened before the threshold.');

        $this->health->recordFailure($provider);

        $this->assertTrue($this->health->isUnavailable($provider));
    }

    public function test_a_success_closes_the_breaker_immediately(): void
    {
        $provider = $this->provider();

        for ($i = 0; $i < 3; $i++) {
            $this->health->recordFailure($provider);
        }

        $this->assertTrue($this->health->isUnavailable($provider));

        $this->health->recordSuccess($provider);

        $this->assertFalse($this->health->isUnavailable($provider));
    }

    public function test_a_success_also_resets_the_consecutive_failure_count(): void
    {
        $provider = $this->provider();

        $this->health->recordFailure($provider);
        $this->health->recordFailure($provider);
        $this->health->recordSuccess($provider);
        $this->health->recordFailure($provider);
        $this->health->recordFailure($provider);

        // Five failures overall, but never three in a row: slow or flaky, not wedged.
        $this->assertFalse($this->health->isUnavailable($provider));
    }

    public function test_the_breaker_closes_when_the_cooldown_lapses(): void
    {
        config()->set('price_buddy.ai_provider_breaker.cooldown_seconds', 60);
        $provider = $this->provider();

        for ($i = 0; $i < 3; $i++) {
            $this->health->recordFailure($provider);
        }

        $this->assertTrue($this->health->isUnavailable($provider));

        $this->travel(61)->seconds();

        $this->assertFalse($this->health->isUnavailable($provider));
    }

    public function test_providers_are_tracked_independently(): void
    {
        $wedged = $this->provider('wedged');
        $healthy = $this->provider('healthy');

        for ($i = 0; $i < 3; $i++) {
            $this->health->recordFailure($wedged);
        }

        $this->assertTrue($this->health->isUnavailable($wedged));
        $this->assertFalse($this->health->isUnavailable($healthy));
    }

    private function provider(string $id = 'p1'): AiProviderConfigDto
    {
        return new AiProviderConfigDto(id: $id, name: 'Local', type: AiProvider::Ollama, model: 'm');
    }
}

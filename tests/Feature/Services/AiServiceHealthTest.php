<?php

namespace Tests\Feature\Services;

use App\Dto\AiProviderConfigDto;
use App\Enums\AiProvider;
use App\Exceptions\AiProviderException;
use App\Services\Ai\AiProviderHealth;
use App\Services\AiService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * AiService is where a provider call is known to have succeeded or failed, so it is where
 * the circuit breaker is fed. Everything downstream only reads the resulting state.
 */
class AiServiceHealthTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config()->set('price_buddy.ai_provider_breaker.failure_threshold', 1);
        config()->set('price_buddy.ai_provider_breaker.cooldown_seconds', 300);
    }

    public function test_a_failed_agent_run_feeds_the_circuit_breaker(): void
    {
        Http::fake(['*' => Http::response('upstream is unwell', 500)]);

        $provider = $this->provider();
        $health = AiProviderHealth::new();

        $this->assertFalse($health->isUnavailable($provider));

        try {
            AiService::new()->runAgent(
                'Do the thing.',
                fn (JsonSchema $schema): array => ['ok' => $schema->string()],
                'Go.',
                [],
                $provider,
            );
            $this->fail('The agent run should have failed against a 500.');
        } catch (AiProviderException) {
            // Expected: the run failed, which is what the breaker counts.
        }

        $this->assertTrue($health->isUnavailable($provider), 'A failed run did not open the breaker.');
    }

    public function test_a_reachability_check_does_not_open_the_circuit_breaker(): void
    {
        Http::fake(['*' => Http::response('upstream is unwell', 500)]);

        $provider = $this->provider();
        $health = AiProviderHealth::new();

        // The Ollama connection test lists models rather than generating, which proves the
        // HTTP front end is up but not that generation works — the exact state the breaker
        // exists for. It must therefore neither open nor close it.
        $result = AiService::new()->testProviderConfig($provider);

        $this->assertSame('Could not reach Ollama at http://ai.example:11434.', $result);

        $this->assertFalse(
            $health->isUnavailable($provider),
            'A reachability check must not open the breaker; only a real generation counts.'
        );
    }

    private function provider(): AiProviderConfigDto
    {
        return new AiProviderConfigDto(
            id: 'p1',
            name: 'Local',
            type: AiProvider::Ollama,
            model: 'm',
            baseUrl: 'http://ai.example:11434',
            timeoutSeconds: 5,
        );
    }
}

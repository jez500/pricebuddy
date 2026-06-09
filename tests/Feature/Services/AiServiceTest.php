<?php

namespace Tests\Feature\Services;

use App\Exceptions\AiProviderException;
use App\Services\Ai\ConfiguredStructuredAgent;
use App\Services\AiService;
use App\Services\Helpers\SettingsHelper;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Once;
use Tests\TestCase;

class AiServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SettingsHelper::$settings = null;
        Once::flush();
    }

    /**
     * Write AI settings into the Spatie settings store and clear caches so
     * IntegrationHelper re-reads them.
     *
     * @param  array<string, mixed>  $ai
     */
    private function setAiSettings(array $ai): void
    {
        SettingsHelper::setSetting('integrated_services', ['ai' => $ai]);
        SettingsHelper::$settings = null;
        Cache::flush();
        Once::flush();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function setActiveProvider(array $overrides = []): void
    {
        $provider = array_merge([
            'id' => 'p1', 'name' => 'Test', 'type' => 'anthropic',
            'model' => 'claude-haiku-4-5-20251001',
            'api_key' => Crypt::encryptString('test-key'),
            'timeout_seconds' => 30, 'max_tokens' => 2000, 'temperature' => 0.1,
        ], $overrides);

        $this->setAiSettings([
            'enabled' => true,
            'default_provider_id' => $provider['id'],
            'providers' => [$provider],
        ]);
    }

    public function test_structured_returns_null_when_ai_is_disabled(): void
    {
        $this->setAiSettings(['enabled' => false, 'providers' => []]);

        $result = AiService::new()->structured(
            'Extract.',
            fn (JsonSchema $schema) => ['price' => $schema->number()->required()],
            '<html>...</html>',
        );

        $this->assertNull($result);
    }

    public function test_structured_returns_array_when_enabled_and_agent_is_faked(): void
    {
        $this->setActiveProvider();

        ConfiguredStructuredAgent::fake([
            ['price' => 19.99, 'currency' => 'USD'],
        ]);

        $result = AiService::new()->structured(
            'Extract.',
            fn (JsonSchema $schema) => [
                'price' => $schema->number()->required(),
                'currency' => $schema->string()->required(),
            ],
            '<html>...</html>',
        );

        $this->assertSame(['price' => 19.99, 'currency' => 'USD'], $result);
    }

    public function test_structured_throws_and_logs_redacted_when_sdk_throws(): void
    {
        // Provider api_key decrypts to 'test-key' (see setActiveProvider()).
        $this->setActiveProvider();
        Log::spy();

        ConfiguredStructuredAgent::fake(function () {
            throw new \RuntimeException('Unauthorized: Bearer test-key was rejected');
        });

        try {
            AiService::new()->structured(
                'Extract.',
                fn (JsonSchema $schema) => ['price' => $schema->number()->required()],
                '<html>...</html>',
            );
            $this->fail('Expected AiProviderException to be thrown.');
        } catch (AiProviderException $e) {
            $this->assertStringContainsString('RuntimeException', $e->getMessage());
        }

        Log::shouldHaveReceived('error')->withArgs(function (string $message, array $context): bool {
            return $message === 'AI structured prompt failed.'
                && ! str_contains($context['message'], 'test-key')
                && str_contains($context['message'], '[redacted]');
        });
    }

    public function test_structured_returns_null_when_default_points_nowhere(): void
    {
        $this->setAiSettings(['enabled' => true, 'default_provider_id' => 'gone', 'providers' => [
            ['id' => 'p1', 'name' => 'A', 'type' => 'anthropic'],
        ]]);

        $result = AiService::new()->structured('x',
            fn (JsonSchema $s) => ['ok' => $s->integer()->required()], 'p');

        $this->assertNull($result);
    }

    public function test_test_connection_returns_string_when_ai_is_disabled(): void
    {
        $this->setAiSettings(['enabled' => false, 'providers' => []]);

        $result = AiService::new()->testConnection();

        $this->assertIsString($result);
        $this->assertSame('AI is not enabled.', $result);
    }

    public function test_test_connection_returns_true_when_enabled_and_agent_is_faked(): void
    {
        $this->setActiveProvider();

        ConfiguredStructuredAgent::fake([['ok' => 1]]);

        $result = AiService::new()->testConnection();

        $this->assertTrue($result);
    }

    public function test_test_connection_returns_error_string_when_sdk_throws(): void
    {
        $this->setActiveProvider();

        ConfiguredStructuredAgent::fake(function () {
            throw new \RuntimeException('boom');
        });

        $result = AiService::new()->testConnection();

        $this->assertIsString($result);
        $this->assertStringContainsString('RuntimeException', $result);
    }

    public function test_test_connection_returns_true_when_ollama_is_reachable_and_model_present(): void
    {
        $this->setActiveProvider(['type' => 'ollama', 'api_key' => null,
            'base_url' => 'http://ai.example:11434', 'model' => 'gemma4:e4b']);

        Http::fake([
            '*/api/tags' => Http::response(['models' => [
                ['name' => 'gemma4:e4b'],
                ['name' => 'qwen2.5-coder:7b'],
            ]]),
        ]);

        $result = AiService::new()->testConnection();

        $this->assertTrue($result);
    }

    public function test_test_connection_reports_when_ollama_model_is_missing(): void
    {
        $this->setActiveProvider(['type' => 'ollama', 'api_key' => null,
            'base_url' => 'http://ai.example:11434', 'model' => 'gemma4:e4b']);

        Http::fake(['*/api/tags' => Http::response(['models' => [['name' => 'qwen2.5-coder:7b']]])]);

        $result = AiService::new()->testConnection();

        $this->assertIsString($result);
        $this->assertStringContainsString("model 'gemma4:e4b' was not found", $result);
    }

    public function test_test_connection_reports_when_ollama_is_unreachable(): void
    {
        $this->setActiveProvider(['type' => 'ollama', 'api_key' => null,
            'base_url' => 'http://unreachable:11434', 'model' => 'gemma4:e4b']);

        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('refused'));

        $result = AiService::new()->testConnection();

        $this->assertSame('Could not reach Ollama at http://unreachable:11434.', $result);
    }
}

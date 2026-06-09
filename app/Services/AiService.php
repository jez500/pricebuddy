<?php

namespace App\Services;

use App\Dto\AiProviderConfigDto;
use App\Enums\AiProvider;
use App\Exceptions\AiProviderException;
use App\Services\Ai\ConfiguredStructuredAgent;
use App\Services\Ai\SecretRedactor;
use App\Services\Helpers\IntegrationHelper;
use Closure;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Throwable;

class AiService
{
    public static function new(): self
    {
        return resolve(static::class);
    }

    public function isEnabled(): bool
    {
        return IntegrationHelper::isAiEnabled();
    }

    /**
     * Run a structured prompt through the given provider, or the active default.
     *
     * @param  Closure(JsonSchema): array<string, mixed>  $schema
     * @return array<string, mixed>|null
     */
    public function structured(string $instructions, Closure $schema, string $prompt, ?AiProviderConfigDto $provider = null): ?array
    {
        $provider ??= IntegrationHelper::getActiveAiProvider();

        if ($provider === null) {
            return null;
        }

        return $this->runStructuredFor($provider, $instructions, $schema, $prompt);
    }

    /**
     * Run a tool-using structured agent and return the validated structured
     * output, or null. Used for agentic flows (e.g. config self-healing).
     *
     * @param  Closure(JsonSchema): array<string, mixed>  $schema
     * @param  array<int, \Laravel\Ai\Contracts\Tool>  $tools
     * @return array<string, mixed>|null
     */
    public function runAgent(string $instructions, Closure $schema, string $prompt, array $tools, ?AiProviderConfigDto $provider = null, int $maxSteps = 25): ?array
    {
        $provider ??= IntegrationHelper::getActiveAiProvider();

        if ($provider === null) {
            return null;
        }

        $this->configureProviderCredentials($provider);

        $agent = new ConfiguredStructuredAgent(
            instructions: $instructions,
            tools: $tools,
            schema: $schema,
            temperature: $provider->temperature,
            maxTokens: $provider->maxTokens,
            maxSteps: $maxSteps,
        );

        try {
            $response = $agent->prompt(
                $prompt,
                provider: $provider->type->toLab(),
                model: $provider->model,
                timeout: $provider->timeoutSeconds,
            );

            return $response instanceof StructuredAgentResponse ? $response->toArray() : null;
        } catch (Throwable $e) {
            Log::error('AI agent run failed.', [
                'provider' => $provider->type->driver(),
                'model' => $provider->model,
                'exception' => $e::class,
                'message' => SecretRedactor::redact($e->getMessage(), $this->decrypt($provider->apiKey)),
            ]);

            throw new AiProviderException('AI agent request failed ('.$e::class.').', previous: $e);
        }
    }

    /**
     * @param  Closure(JsonSchema): array<string, mixed>  $schema
     * @return array<string, mixed>|null
     */
    protected function runStructuredFor(AiProviderConfigDto $provider, string $instructions, Closure $schema, string $prompt): ?array
    {
        $this->configureProviderCredentials($provider);

        $agent = new ConfiguredStructuredAgent(
            instructions: $instructions,
            schema: $schema,
            temperature: $provider->temperature,
            maxTokens: $provider->maxTokens,
        );

        try {
            $response = $agent->prompt(
                $prompt,
                provider: $provider->type->toLab(),
                model: $provider->model,
                timeout: $provider->timeoutSeconds,
            );

            return $response instanceof StructuredAgentResponse ? $response->toArray() : null;
        } catch (Throwable $e) {
            Log::error('AI structured prompt failed.', [
                'provider' => $provider->type->driver(),
                'model' => $provider->model,
                'exception' => $e::class,
                'message' => SecretRedactor::redact($e->getMessage(), $this->decrypt($provider->apiKey)),
            ]);

            throw new AiProviderException('AI provider request failed ('.$e::class.').', previous: $e);
        }
    }

    /**
     * @return true|string true on success, an error message on failure
     */
    public function testConnection(): true|string
    {
        if (! $this->isEnabled()) {
            return 'AI is not enabled.';
        }

        $provider = IntegrationHelper::getActiveAiProvider();

        if ($provider === null) {
            return 'No AI provider is configured.';
        }

        return $this->testProviderConfig($provider);
    }

    /**
     * @return true|string true on success, an error message on failure
     */
    public function testProviderConfig(AiProviderConfigDto $provider): true|string
    {
        if ($provider->type === AiProvider::Ollama) {
            return $this->testOllamaConnection($provider);
        }

        try {
            $result = $this->runStructuredFor(
                $provider,
                'Reply with the number 1.',
                fn (JsonSchema $schema) => ['ok' => $schema->integer()->required()],
                'Return 1.',
            );
        } catch (AiProviderException $e) {
            return $e->getMessage();
        }

        return $result === null ? 'The AI provider did not return a valid response.' : true;
    }

    /**
     * @return true|string true on success, an error message on failure
     */
    protected function testOllamaConnection(AiProviderConfigDto $provider): true|string
    {
        $baseUrl = $provider->baseUrl;

        if (blank($baseUrl)) {
            return 'No Ollama base URL is configured.';
        }

        try {
            $models = OllamaService::new()->listModels($baseUrl);
        } catch (Throwable $e) {
            Log::warning('Ollama reachability check failed.', ['exception' => $e::class]);

            return "Could not reach Ollama at {$baseUrl}.";
        }

        if (blank($provider->model)) {
            return 'Connected to Ollama, but no model is selected.';
        }

        if (! in_array($provider->model, $models, true)) {
            return "Connected to Ollama, but model '{$provider->model}' was not found. Available: ".
                (implode(', ', $models) ?: 'none').'.';
        }

        return true;
    }

    /**
     * Push the provider's decrypted key + base URL into the SDK runtime config.
     *
     * Note: this mutates global runtime config for the current process and assumes
     * AI calls are sequential (not interleaved within one PHP worker). If this is
     * later driven from concurrent in-process work, switch to a per-call credential
     * mechanism instead of mutating shared config.
     */
    protected function configureProviderCredentials(AiProviderConfigDto $provider): void
    {
        $driver = $provider->type->driver();

        config(["ai.providers.{$driver}.key" => $this->decrypt($provider->apiKey)]);

        if (filled($provider->baseUrl)) {
            config(["ai.providers.{$driver}.url" => $provider->baseUrl]);
        }
    }

    protected function decrypt(?string $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        try {
            return Crypt::decryptString($value);
        } catch (Throwable) {
            Log::warning('AI provider API key could not be decrypted; check APP_KEY and the stored credentials.');

            return null;
        }
    }
}

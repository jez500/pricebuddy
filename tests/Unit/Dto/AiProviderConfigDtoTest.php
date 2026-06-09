<?php

use App\Dto\AiProviderConfigDto;
use App\Enums\AiProvider;

it('builds from an array, mapping the type to the enum', function () {
    $dto = AiProviderConfigDto::fromArray([
        'id' => 'p1',
        'name' => 'Claude',
        'type' => 'anthropic',
        'model' => 'claude-haiku-4-5-20251001',
        'base_url' => 'https://api.anthropic.com',
        'api_key' => 'cipher',
        'timeout_seconds' => 45,
        'max_tokens' => 1500,
        'temperature' => 0.3,
    ]);

    expect($dto)->toBeInstanceOf(AiProviderConfigDto::class)
        ->and($dto->id)->toBe('p1')
        ->and($dto->name)->toBe('Claude')
        ->and($dto->type)->toBe(AiProvider::Anthropic)
        ->and($dto->model)->toBe('claude-haiku-4-5-20251001')
        ->and($dto->baseUrl)->toBe('https://api.anthropic.com')
        ->and($dto->apiKey)->toBe('cipher')
        ->and($dto->timeoutSeconds)->toBe(45)
        ->and($dto->maxTokens)->toBe(1500)
        ->and($dto->temperature)->toBe(0.3);
});

it('applies defaults for missing generation params', function () {
    $dto = AiProviderConfigDto::fromArray(['id' => 'p1', 'type' => 'ollama']);

    expect($dto->timeoutSeconds)->toBe(60)
        ->and($dto->maxTokens)->toBe(2000)
        ->and($dto->temperature)->toBe(0.2)
        ->and($dto->name)->toBe('Ollama'); // falls back to the enum case name
});

it('returns null for an unknown or missing type', function () {
    expect(AiProviderConfigDto::fromArray(['id' => 'p1', 'type' => 'nope']))->toBeNull()
        ->and(AiProviderConfigDto::fromArray(['id' => 'p1']))->toBeNull();
});

it('returns null when the id is blank', function () {
    expect(AiProviderConfigDto::fromArray(['id' => '', 'type' => 'ollama']))->toBeNull();
});

<?php

use App\Settings\AiProvidersRestructure;

it('converts a single ollama provider config into the providers array', function () {
    $result = AiProvidersRestructure::transform([
        'ai' => [
            'enabled' => true,
            'provider' => 'ollama',
            'default_model' => 'ignored-for-ollama',
            'timeout_seconds' => 90,
            'max_tokens' => 1234,
            'temperature' => 0.5,
            'ollama' => ['base_url' => 'http://ai.jeznet:11434', 'model' => 'gemma4:e4b'],
        ],
    ]);

    $ai = $result['ai'];
    expect($ai['enabled'])->toBeTrue()
        ->and($ai['providers'])->toHaveCount(1)
        ->and($ai['default_provider_id'])->toBe($ai['providers'][0]['id'])
        ->and($ai['providers'][0]['type'])->toBe('ollama')
        ->and($ai['providers'][0]['base_url'])->toBe('http://ai.jeznet:11434')
        ->and($ai['providers'][0]['model'])->toBe('gemma4:e4b')
        ->and($ai['providers'][0]['timeout_seconds'])->toBe(90)
        ->and($ai)->not->toHaveKeys(['provider', 'default_model', 'ollama', 'timeout_seconds']);
});

it('preserves an encrypted cloud key and uses default_model for cloud providers', function () {
    $result = AiProvidersRestructure::transform([
        'ai' => [
            'enabled' => true,
            'provider' => 'anthropic',
            'default_model' => 'claude-haiku-4-5-20251001',
            'anthropic' => ['api_key' => 'ENCRYPTED', 'base_url' => null],
        ],
    ]);

    expect($result['ai']['providers'][0]['type'])->toBe('anthropic')
        ->and($result['ai']['providers'][0]['api_key'])->toBe('ENCRYPTED')
        ->and($result['ai']['providers'][0]['model'])->toBe('claude-haiku-4-5-20251001');
});

it('produces an empty provider list when no provider was configured', function () {
    $result = AiProvidersRestructure::transform(['ai' => ['enabled' => false]]);

    expect($result['ai']['providers'])->toBe([])
        ->and($result['ai']['default_provider_id'])->toBeNull();
});

it('is idempotent when providers already exist', function () {
    $already = ['ai' => ['enabled' => true, 'default_provider_id' => 'p1', 'providers' => [
        ['id' => 'p1', 'type' => 'ollama'],
    ]]];

    expect(AiProvidersRestructure::transform($already))->toBe($already);
});

it('handles a provider set with no matching subkey', function () {
    $result = AiProvidersRestructure::transform([
        'ai' => ['enabled' => true, 'provider' => 'anthropic'],
    ]);

    expect($result['ai']['providers'])->toHaveCount(1)
        ->and($result['ai']['providers'][0]['type'])->toBe('anthropic')
        ->and($result['ai']['providers'][0]['base_url'])->toBeNull()
        ->and($result['ai']['providers'][0]['api_key'])->toBeNull();
});

it('leaves non-ai integrated services untouched', function () {
    $result = AiProvidersRestructure::transform([
        'search' => ['enabled' => true, 'url' => 'http://searx'],
        'ai' => ['enabled' => false],
    ]);

    expect($result['search'])->toBe(['enabled' => true, 'url' => 'http://searx']);
});

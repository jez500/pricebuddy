<?php

use App\Enums\AiProvider;
use Laravel\Ai\Enums\Lab;

it('has the expected string value for every case', function () {
    expect(AiProvider::OpenAI->value)->toBe('openai')
        ->and(AiProvider::Anthropic->value)->toBe('anthropic')
        ->and(AiProvider::Ollama->value)->toBe('ollama')
        ->and(AiProvider::Gemini->value)->toBe('gemini');
});

it('maps each provider to the matching SDK lab', function () {
    expect(AiProvider::OpenAI->toLab())->toBe(Lab::OpenAI)
        ->and(AiProvider::Anthropic->toLab())->toBe(Lab::Anthropic)
        ->and(AiProvider::Ollama->toLab())->toBe(Lab::Ollama)
        ->and(AiProvider::Gemini->toLab())->toBe(Lab::Gemini);
});

it('exposes the config driver key for each provider', function () {
    expect(AiProvider::OpenAI->driver())->toBe('openai')
        ->and(AiProvider::Anthropic->driver())->toBe('anthropic')
        ->and(AiProvider::Ollama->driver())->toBe('ollama')
        ->and(AiProvider::Gemini->driver())->toBe('gemini');
});

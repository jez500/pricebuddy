<?php

namespace App\Enums;

use Laravel\Ai\Enums\Lab;

enum AiProvider: string
{
    case OpenAI = 'openai';
    case Anthropic = 'anthropic';
    case Ollama = 'ollama';
    case Gemini = 'gemini';

    /**
     * Map this provider to the Laravel AI SDK provider enum.
     */
    public function toLab(): Lab
    {
        return match ($this) {
            self::OpenAI => Lab::OpenAI,
            self::Anthropic => Lab::Anthropic,
            self::Ollama => Lab::Ollama,
            self::Gemini => Lab::Gemini,
        };
    }

    /**
     * The config key for this provider under `config('ai.providers.*')`.
     */
    public function driver(): string
    {
        return $this->value;
    }
}

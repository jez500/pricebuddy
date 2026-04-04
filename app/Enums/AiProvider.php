<?php

namespace App\Enums;

enum AiProvider: string
{
    case OpenAI = 'openai';
    case Anthropic = 'anthropic';
    case Ollama = 'ollama';
}

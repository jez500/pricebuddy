<?php

namespace Tests\Unit\Enums;

use App\Enums\AiProvider;
use Tests\TestCase;

class AiProviderTest extends TestCase
{
    public function test_all_enum_cases_have_expected_values()
    {
        $this->assertSame('openai', AiProvider::OpenAI->value);
        $this->assertSame('anthropic', AiProvider::Anthropic->value);
        $this->assertSame('ollama', AiProvider::Ollama->value);
    }
}

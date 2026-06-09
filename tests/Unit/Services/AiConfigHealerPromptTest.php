<?php

namespace Tests\Unit\Services;

use App\Services\AiConfigHealer;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class AiConfigHealerPromptTest extends TestCase
{
    private function prompt(): string
    {
        return (string) (new ReflectionClass(AiConfigHealer::class))->getConstant('PROMPT');
    }

    public function test_prompt_instructs_static_first_then_browser_fallback(): void
    {
        $prompt = $this->prompt();

        $this->assertStringContainsString('rendered=false', $prompt);
        $this->assertStringContainsString('rendered=true', $prompt);
    }

    public function test_prompt_tells_the_agent_to_switch_to_browser_when_blocked(): void
    {
        $prompt = strtolower($this->prompt());

        $this->assertStringContainsString('blocked', $prompt);
        $this->assertStringContainsString('captcha', $prompt);
        $this->assertStringContainsString('browser scraping', $prompt);
    }
}

<?php

namespace Tests\Unit\Services;

use App\Enums\AiProvider;
use App\Enums\IntegratedServices;
use App\Services\Helpers\IntegrationHelper;
use App\Services\Helpers\SettingsHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Once;
use Tests\TestCase;

class IntegrationHelperTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SettingsHelper::$settings = null;
        Once::flush();
    }

    public function test_get_ai_settings_returns_empty_array_by_default()
    {
        $this->assertSame([], IntegrationHelper::getAiSettings());
    }

    public function test_get_ai_provider_returns_null_when_missing_or_invalid()
    {
        $allSettings = IntegrationHelper::getSettings();
        $allSettings[IntegratedServices::Ai->value] = ['provider' => 'foo'];
        IntegrationHelper::setSettings($allSettings);
        Once::flush();

        $this->assertNull(IntegrationHelper::getAiProvider());
    }

    public function test_get_ai_provider_returns_provider_enum_when_valid()
    {
        $allSettings = IntegrationHelper::getSettings();
        $allSettings[IntegratedServices::Ai->value] = ['provider' => AiProvider::Anthropic->value];
        IntegrationHelper::setSettings($allSettings);
        Once::flush();

        $this->assertSame(AiProvider::Anthropic, IntegrationHelper::getAiProvider());
    }

    public function test_is_ai_enabled_requires_enabled_and_valid_provider()
    {
        $allSettings = IntegrationHelper::getSettings();
        $allSettings[IntegratedServices::Ai->value] = [
            'enabled' => true,
            'provider' => AiProvider::OpenAI->value,
        ];
        IntegrationHelper::setSettings($allSettings);
        Once::flush();

        $this->assertTrue(IntegrationHelper::isAiEnabled());

        $allSettings[IntegratedServices::Ai->value] = [
            'enabled' => true,
            'provider' => 'invalid-provider',
        ];
        IntegrationHelper::setSettings($allSettings);
        Once::flush();

        $this->assertFalse(IntegrationHelper::isAiEnabled());
    }
}

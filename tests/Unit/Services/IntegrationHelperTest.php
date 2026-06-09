<?php

namespace Tests\Unit\Services;

use App\Enums\AiProvider;
use App\Services\Helpers\IntegrationHelper;
use App\Services\Helpers\SettingsHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
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

    /**
     * @param  array<string, mixed>  $value
     */
    private function setIntegratedServices(array $value): void
    {
        SettingsHelper::setSetting('integrated_services', $value);
        SettingsHelper::$settings = null;
        Cache::flush();
        Once::flush();
    }

    public function test_get_ai_settings_returns_default_shape_after_migration(): void
    {
        // The restructure migration always seeds the default multi-provider shape.
        $settings = IntegrationHelper::getAiSettings();
        $this->assertArrayHasKey('providers', $settings);
        $this->assertSame([], $settings['providers']);
        $this->assertArrayHasKey('default_provider_id', $settings);
        $this->assertNull($settings['default_provider_id']);
        $this->assertFalse((bool) $settings['enabled']);
    }

    public function test_get_ai_providers_maps_entries_to_dtos(): void
    {
        $this->setIntegratedServices(['ai' => [
            'enabled' => true,
            'default_provider_id' => 'p1',
            'providers' => [
                ['id' => 'p1', 'name' => 'Claude', 'type' => 'anthropic', 'model' => 'm'],
                ['id' => 'p2', 'name' => 'Local', 'type' => 'ollama', 'model' => 'gemma4:e4b'],
                ['id' => 'p3', 'name' => 'Bad', 'type' => 'nonsense'],
            ],
        ]]);

        $providers = IntegrationHelper::getAiProviders();

        $this->assertCount(2, $providers);
        $this->assertSame('p1', $providers[0]->id);
        $this->assertSame(AiProvider::Ollama, $providers[1]->type);
    }

    public function test_get_active_ai_provider_selects_by_default_id(): void
    {
        $this->setIntegratedServices(['ai' => [
            'enabled' => true,
            'default_provider_id' => 'p2',
            'providers' => [
                ['id' => 'p1', 'name' => 'A', 'type' => 'anthropic'],
                ['id' => 'p2', 'name' => 'B', 'type' => 'ollama'],
            ],
        ]]);

        $active = IntegrationHelper::getActiveAiProvider();

        $this->assertNotNull($active);
        $this->assertSame('p2', $active->id);
    }

    public function test_get_active_ai_provider_is_null_when_default_points_nowhere(): void
    {
        $this->setIntegratedServices(['ai' => [
            'enabled' => true, 'default_provider_id' => 'gone',
            'providers' => [['id' => 'p1', 'name' => 'A', 'type' => 'anthropic']],
        ]]);

        $this->assertNull(IntegrationHelper::getActiveAiProvider());
    }

    public function test_is_ai_enabled_requires_enabled_and_a_valid_default(): void
    {
        $this->setIntegratedServices(['ai' => [
            'enabled' => true, 'default_provider_id' => 'p1',
            'providers' => [['id' => 'p1', 'name' => 'A', 'type' => 'anthropic']],
        ]]);
        $this->assertTrue(IntegrationHelper::isAiEnabled());

        $this->setIntegratedServices(['ai' => [
            'enabled' => false, 'default_provider_id' => 'p1',
            'providers' => [['id' => 'p1', 'name' => 'A', 'type' => 'anthropic']],
        ]]);
        $this->assertFalse(IntegrationHelper::isAiEnabled());
    }
}

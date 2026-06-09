<?php

namespace Tests\Feature\Services;

use App\Enums\AiFeature;
use App\Models\Store;
use App\Services\Helpers\IntegrationHelper;
use App\Services\Helpers\SettingsHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Once;
use Tests\TestCase;

class FeatureProviderResolutionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        SettingsHelper::$settings = null;
        Cache::flush();
        Once::flush();
    }

    /**
     * @param  array<string, mixed>  $ai
     */
    private function settings(array $ai): void
    {
        SettingsHelper::setSetting('integrated_services', ['ai' => array_merge([
            'enabled' => true,
            'default_provider_id' => 'p1',
            'providers' => [
                ['id' => 'p1', 'name' => 'Default', 'type' => 'ollama', 'base_url' => 'http://a:1', 'model' => 'm'],
                ['id' => 'p2', 'name' => 'Second', 'type' => 'ollama', 'base_url' => 'http://b:1', 'model' => 'm'],
            ],
        ], $ai)]);
        SettingsHelper::$settings = null;
        Cache::flush();
        Once::flush();
    }

    public function test_empty_selection_uses_the_global_default_provider(): void
    {
        $this->settings(['feature_providers' => ['healing' => '']]);

        $provider = IntegrationHelper::resolveFeatureProvider(AiFeature::Healing);

        $this->assertSame('p1', $provider?->id);
        $this->assertTrue(IntegrationHelper::isFeatureEnabled(AiFeature::Healing));
    }

    public function test_disabled_sentinel_returns_null(): void
    {
        $this->settings(['feature_providers' => ['healing' => IntegrationHelper::FEATURE_DISABLED]]);

        $this->assertNull(IntegrationHelper::resolveFeatureProvider(AiFeature::Healing));
        $this->assertFalse(IntegrationHelper::isFeatureEnabled(AiFeature::Healing));
    }

    public function test_explicit_provider_id_is_used(): void
    {
        $this->settings(['feature_providers' => ['healing' => 'p2']]);

        $this->assertSame('p2', IntegrationHelper::resolveFeatureProvider(AiFeature::Healing)?->id);
    }

    public function test_global_ai_disabled_returns_null(): void
    {
        $this->settings(['enabled' => false, 'feature_providers' => ['healing' => 'p2']]);

        $this->assertNull(IntegrationHelper::resolveFeatureProvider(AiFeature::Healing));
    }

    public function test_store_provider_override_wins(): void
    {
        $this->settings(['feature_providers' => ['healing' => 'p1']]);
        $store = Store::factory()->create(['settings' => ['ai_provider_id' => 'p2']]);

        $this->assertSame('p2', IntegrationHelper::resolveFeatureProvider(AiFeature::Healing, $store)?->id);
    }
}

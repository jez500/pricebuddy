<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\AppSettingsPage;
use App\Models\User;
use App\Services\Helpers\IntegrationHelper;
use App\Services\Helpers\SettingsHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Once;
use Livewire\Livewire;
use Tests\TestCase;

class AppSettingsAiEncryptionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SettingsHelper::setSetting('integrated_services', []);
        SettingsHelper::$settings = null;
        Cache::flush();
        Once::flush();

        $this->actingAs(User::factory()->admin()->create());
    }

    /**
     * Seed baseline AI settings and flush all caches so helpers re-read them.
     *
     * @param  array<string, mixed>  $ai
     */
    private function setAiSettings(array $ai): void
    {
        SettingsHelper::setSetting('integrated_services', ['ai' => $ai]);
        SettingsHelper::$settings = null;
        Cache::flush();
        Once::flush();
    }

    public function test_a_new_provider_api_key_is_stored_encrypted(): void
    {
        Livewire::test(AppSettingsPage::class)
            ->fillForm([
                'integrated_services.ai.enabled' => true,
                'integrated_services.ai.providers' => [
                    ['id' => 'p1', 'name' => 'Claude', 'type' => 'anthropic',
                        'model' => 'claude-haiku-4-5-20251001', 'api_key' => 'sk-ant-secret-123',
                        'timeout_seconds' => 60, 'max_tokens' => 2000, 'temperature' => 0.2],
                ],
                'integrated_services.ai.default_provider_id' => 'p1',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        SettingsHelper::$settings = null;
        Cache::flush();
        Once::flush();

        $stored = data_get(IntegrationHelper::getAiSettings(), 'providers.0.api_key');
        $this->assertNotSame('sk-ant-secret-123', $stored);
        $this->assertSame('sk-ant-secret-123', Crypt::decryptString($stored));
    }

    public function test_blank_api_key_preserves_the_stored_key_by_id(): void
    {
        $this->setAiSettings([
            'enabled' => true,
            'default_provider_id' => 'p1',
            'providers' => [
                ['id' => 'p1', 'name' => 'Claude', 'type' => 'anthropic',
                    'model' => 'm', 'api_key' => Crypt::encryptString('keep-me'),
                    'timeout_seconds' => 60, 'max_tokens' => 2000, 'temperature' => 0.2],
            ],
        ]);

        Livewire::test(AppSettingsPage::class)
            ->fillForm([
                'integrated_services.ai.enabled' => true,
                'integrated_services.ai.providers' => [
                    ['id' => 'p1', 'name' => 'Claude', 'type' => 'anthropic', 'model' => 'm',
                        'api_key' => '', 'timeout_seconds' => 60, 'max_tokens' => 2000, 'temperature' => 0.2],
                ],
                'integrated_services.ai.default_provider_id' => 'p1',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        SettingsHelper::$settings = null;
        Cache::flush();
        Once::flush();

        $stored = data_get(IntegrationHelper::getAiSettings(), 'providers.0.api_key');
        $this->assertSame('keep-me', Crypt::decryptString($stored));
    }

    public function test_blank_key_for_one_provider_does_not_affect_another(): void
    {
        $this->setAiSettings([
            'enabled' => true,
            'default_provider_id' => 'p1',
            'providers' => [
                ['id' => 'p1', 'name' => 'Claude', 'type' => 'anthropic', 'model' => 'm',
                    'api_key' => Crypt::encryptString('claude-key'),
                    'timeout_seconds' => 60, 'max_tokens' => 2000, 'temperature' => 0.2],
                ['id' => 'p2', 'name' => 'OpenAI', 'type' => 'openai', 'model' => 'm2',
                    'api_key' => Crypt::encryptString('openai-key'),
                    'timeout_seconds' => 60, 'max_tokens' => 2000, 'temperature' => 0.2],
            ],
        ]);

        // Re-save with BOTH keys blank; both stored keys must be preserved by id.
        Livewire::test(AppSettingsPage::class)
            ->fillForm([
                'integrated_services.ai.enabled' => true,
                'integrated_services.ai.providers' => [
                    ['id' => 'p1', 'name' => 'Claude', 'type' => 'anthropic', 'model' => 'm',
                        'api_key' => '', 'timeout_seconds' => 60, 'max_tokens' => 2000, 'temperature' => 0.2],
                    ['id' => 'p2', 'name' => 'OpenAI', 'type' => 'openai', 'model' => 'm2',
                        'api_key' => '', 'timeout_seconds' => 60, 'max_tokens' => 2000, 'temperature' => 0.2],
                ],
                'integrated_services.ai.default_provider_id' => 'p1',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        SettingsHelper::$settings = null;
        Cache::flush();
        Once::flush();

        $providers = data_get(IntegrationHelper::getAiSettings(), 'providers');
        $byId = collect($providers)->keyBy('id');
        $this->assertSame('claude-key', Crypt::decryptString($byId['p1']['api_key']));
        $this->assertSame('openai-key', Crypt::decryptString($byId['p2']['api_key']));
    }

    public function test_base_url_help_text_is_present_for_cloud_providers(): void
    {
        Livewire::test(AppSettingsPage::class)
            ->fillForm([
                'integrated_services.ai.enabled' => true,
                'integrated_services.ai.providers' => [
                    ['id' => 'p1', 'name' => 'Claude', 'type' => 'anthropic',
                        'model' => 'm', 'timeout_seconds' => 60, 'max_tokens' => 2000, 'temperature' => 0.2],
                ],
            ])
            ->assertSee('Leave empty to use default.');
    }
}

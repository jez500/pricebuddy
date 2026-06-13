<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\AppSettingsPage;
use App\Models\User;
use App\Services\Helpers\SettingsHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Once;
use Livewire\Livewire;
use Tests\TestCase;

class AppSettingsAiFeatureProvidersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        SettingsHelper::$settings = null;
        Cache::flush();
        Once::flush();
        $this->actingAs(User::factory()->admin()->create(['email' => 'test@test.com']));
    }

    public function test_feature_provider_selection_persists(): void
    {
        SettingsHelper::setSetting('integrated_services', ['ai' => [
            'enabled' => true,
            'default_provider_id' => 'p1',
            'providers' => [[
                'id' => 'p1', 'name' => 'Local', 'type' => 'ollama',
                'base_url' => 'http://ai.example:11434', 'model' => 'm',
            ]],
        ]]);
        SettingsHelper::$settings = null;
        Cache::flush();
        Once::flush();

        Livewire::test(AppSettingsPage::class)
            ->set('data.integrated_services.ai.feature_providers.healing', '__disabled__')
            ->set('data.integrated_services.ai.feature_providers.extraction', 'p1')
            ->call('save')
            ->assertHasNoFormErrors();

        SettingsHelper::$settings = null;
        Cache::flush();
        Once::flush();

        $ai = data_get(SettingsHelper::getSetting('integrated_services'), 'ai');
        $this->assertSame('__disabled__', data_get($ai, 'feature_providers.healing'));
        $this->assertSame('p1', data_get($ai, 'feature_providers.extraction'));
    }

    public function test_feature_provider_selects_are_rendered(): void
    {
        SettingsHelper::setSetting('integrated_services', ['ai' => [
            'enabled' => true,
            'default_provider_id' => 'p1',
            'providers' => [[
                'id' => 'p1', 'name' => 'Local', 'type' => 'ollama',
                'base_url' => 'http://ai.example:11434', 'model' => 'm',
            ]],
        ]]);
        SettingsHelper::$settings = null;
        Cache::flush();
        Once::flush();

        Livewire::test(AppSettingsPage::class)
            ->assertSee('Extraction provider')
            ->assertSee('Healing provider');
    }
}

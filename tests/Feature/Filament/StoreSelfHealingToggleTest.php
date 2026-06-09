<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\StoreResource\Pages\EditStore;
use App\Models\Store;
use App\Models\User;
use App\Services\Helpers\SettingsHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Once;
use Livewire\Livewire;
use Tests\TestCase;

class StoreSelfHealingToggleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        SettingsHelper::$settings = null;
        Cache::flush();
        Once::flush();
        $this->actingAs(User::factory()->create(['email' => 'test@test.com']));

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
    }

    public function test_opt_out_toggle_is_rendered_when_healing_enabled(): void
    {
        $store = Store::factory()->create(['settings' => ['scraper_service' => 'http']]);

        Livewire::test(EditStore::class, ['record' => $store->getKey()])
            ->assertSee('Disable AI self-healing for this store');
    }

    public function test_opt_out_toggle_persists(): void
    {
        $store = Store::factory()->create(['settings' => ['scraper_service' => 'http']]);

        Livewire::test(EditStore::class, ['record' => $store->getKey()])
            ->set('data.domains', null)
            ->fillForm([
                'domains' => [['domain' => 'example.com']],
                'settings.locale_settings.locale' => 'en_US',
                'settings.locale_settings.currency' => 'USD',
                'settings.ai_self_healing_disabled' => true,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue($store->fresh()->ai_self_healing_disabled);
    }
}

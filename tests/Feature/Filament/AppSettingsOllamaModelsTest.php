<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\AppSettingsPage;
use App\Models\User;
use App\Services\Helpers\SettingsHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Once;
use Livewire\Livewire;
use Tests\TestCase;

class AppSettingsOllamaModelsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SettingsHelper::setSetting('integrated_services', []);
        SettingsHelper::$settings = null;
        Cache::flush();
        Once::flush();

        $this->actingAs(User::factory()->create());
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

    public function test_refresh_populates_models_for_a_provider_id(): void
    {
        Http::fake(['*/api/tags' => Http::response(['models' => [
            ['name' => 'gemma4:e4b'], ['name' => 'qwen2.5-coder:7b'],
        ]])]);

        Livewire::test(AppSettingsPage::class)
            ->call('refreshOllamaModelsFor', 'p1', 'http://ai.example:11434')
            ->assertSet('ollamaModels', ['p1' => ['gemma4:e4b', 'qwen2.5-coder:7b']])
            ->assertNotified();
    }

    public function test_refresh_warns_when_base_url_blank(): void
    {
        Livewire::test(AppSettingsPage::class)
            ->call('refreshOllamaModelsFor', 'p1', '')
            ->assertSet('ollamaModels', [])
            ->assertNotified();
    }

    public function test_refresh_handles_unreachable_ollama(): void
    {
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('refused'));

        Livewire::test(AppSettingsPage::class)
            ->call('refreshOllamaModelsFor', 'p1', 'http://unreachable:11434')
            ->assertSet('ollamaModels', [])
            ->assertNotified();
    }

    public function test_test_provider_warns_when_not_saved(): void
    {
        Livewire::test(AppSettingsPage::class)
            ->call('testProviderById', 'unknown')
            ->assertNotified('Save your settings before testing this provider.');
    }

    public function test_test_provider_runs_against_a_saved_ollama_provider(): void
    {
        $this->setAiSettings([
            'enabled' => true,
            'default_provider_id' => 'p1',
            'providers' => [['id' => 'p1', 'name' => 'Local', 'type' => 'ollama',
                'base_url' => 'http://ai.example:11434', 'model' => 'gemma4:e4b']],
        ]);
        Http::fake(['*/api/tags' => Http::response(['models' => [['name' => 'gemma4:e4b']]])]);

        Livewire::test(AppSettingsPage::class)
            ->call('testProviderById', 'p1')
            ->assertNotified('Connection succeeded');
    }
}

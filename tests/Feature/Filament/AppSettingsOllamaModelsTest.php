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

    public function test_refresh_action_populates_the_row_it_was_clicked_on(): void
    {
        $this->setAiSettings([
            'enabled' => true,
            'default_provider_id' => 'p1',
            'providers' => [
                ['id' => 'p1', 'name' => 'First', 'type' => 'ollama', 'base_url' => 'http://first:11434', 'model' => 'gemma4:e4b'],
                ['id' => 'p2', 'name' => 'Second', 'type' => 'ollama', 'base_url' => 'http://second:11434', 'model' => null],
            ],
        ]);
        Http::fake([
            'first:11434/api/tags' => Http::response(['models' => [['name' => 'gemma4:e4b']]]),
            'second:11434/api/tags' => Http::response(['models' => [['name' => 'gpt-oss:20b']]]),
        ]);

        $livewire = Livewire::test(AppSettingsPage::class);

        // Locate the second row's model Select by state path, then click its refresh action.
        $rowKeys = array_keys($livewire->get('data.integrated_services.ai.providers'));
        $secondRowSelect = $livewire->instance()->form->getComponent(
            fn ($component): bool => $component instanceof \Filament\Forms\Components\Select
                && $component->getStatePath() === "data.integrated_services.ai.providers.{$rowKeys[1]}.model",
            withHidden: true,
        );

        // Mount by the component's own key, as the browser does. callFormComponentAction()
        // prefixes the form state path, which would hide a key collision between rows.
        $livewire->call('mountFormComponentAction', $secondRowSelect->getKey(), 'refreshOllamaModels')
            ->assertSet('ollamaModels', ['p2' => ['gpt-oss:20b']]);
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

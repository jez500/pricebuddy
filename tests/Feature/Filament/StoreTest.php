<?php

namespace Tests\Feature\Filament;

use App\Enums\ScraperService;
use App\Filament\Resources\StoreResource;
use App\Filament\Resources\StoreResource\Pages\CreateStore;
use App\Filament\Resources\StoreResource\Pages\EditStore;
use App\Models\Store;
use App\Models\User;
use App\Services\Helpers\SettingsHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Once;
use Livewire\Livewire;
use Tests\TestCase;

class StoreTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        User::query()->delete();

        $this->user = User::factory()->create([
            'name' => 'Tester',
            'email' => 'tester@test.com',
            'password' => Hash::make('password'),
        ]);
    }

    public function test_store_index()
    {
        $this->actingAs($this->user);

        $this->get(StoreResource::getUrl('index'))->assertOk();
    }

    public function test_edit_store()
    {
        $store = Store::factory()->create([
            'name' => 'My store',
            'initials' => 'MS',
        ]);
        $this->actingAs($this->user);
        $params = ['record' => $store->getKey()];

        $this->get(StoreResource::getUrl('edit', $params))->assertOk();

        Livewire::test(EditStore::class, $params)
            ->set('data.domains', null)
            ->fillForm([
                'name' => 'My new store',
                'domains' => [
                    ['domain' => 'example.test'],
                ],
                'settings.scraper_service' => ScraperService::Api->value,
                'settings.scraper_service_settings' => "foo=bar\nbaz=qux",
                'settings.locale_settings.locale' => 'fr_FR',
                'settings.locale_settings.currency' => 'EUR',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $store->refresh();

        $this->assertSame('My new store', $store->name);
        // Note repeatable is buggy, need to check second domain rather than first.
        $this->assertSame('example.test', collect($store->domains)->first()['domain']);
        $this->assertSame(ScraperService::Api->value, $store->scraper_service);
        $this->assertSame([
            'foo' => 'bar',
            'baz' => 'qux',
        ], $store->scraper_options);
        $this->assertSame('fr_FR', $store->locale);
        $this->assertSame('EUR', $store->currency);
    }

    public function test_store_create()
    {
        $this->actingAs($this->user);

        $this->get(StoreResource::getUrl('create'))->assertOk();

        Livewire::test(StoreResource\Pages\CreateStore::class)
            ->set('data.domains', null)
            ->fillForm([
                'name' => 'Test new store',
                'initials' => 'TS',
                'domains' => [
                    ['domain' => 'example-new.test'],
                ],
                'settings.scraper_service' => ScraperService::Api->value,
                'settings.scraper_service_settings' => "fooz=bar\nbazz=qux",
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        /** @var Store $store */
        $store = Store::where('name', 'Test new store')->first();

        // Note repeatable is buggy, need to check second domain rather than first.
        $this->assertSame('example-new.test', collect($store->domains)->first()['domain']);
        $this->assertSame(ScraperService::Api->value, $store->scraper_service);
        $this->assertSame([
            'fooz' => 'bar',
            'bazz' => 'qux',
        ], $store->scraper_options);
    }

    private function configureAi(): void
    {
        SettingsHelper::setSetting('integrated_services', ['ai' => [
            'enabled' => true,
            'default_provider_id' => 'p1',
            'providers' => [['id' => 'p1', 'name' => 'Local', 'type' => 'ollama', 'model' => 'm']],
        ]]);
        SettingsHelper::$settings = null;
        Cache::flush();
        Once::flush();
    }

    public function test_store_form_shows_ai_extraction_toggle_when_ai_configured(): void
    {
        $this->configureAi();
        $this->actingAs($this->user);

        Livewire::test(CreateStore::class)
            ->assertFormFieldExists('settings.ai_extraction_enabled');
    }

    public function test_store_form_defaults_ai_provider_to_global_default(): void
    {
        $this->configureAi();
        $this->actingAs($this->user);

        Livewire::test(CreateStore::class)
            ->assertFormSet(fn (array $state): bool => data_get($state, 'settings.ai_provider_id') === 'p1');
    }

    public function test_edit_store_loads_saved_ai_field_values(): void
    {
        $this->configureAi();
        $this->actingAs($this->user);

        $store = Store::factory()->create([
            'settings' => [
                'scraper_service' => 'http',
                'ai_extraction_enabled' => true,
                'ai_provider_id' => 'p1',
            ],
        ]);

        Livewire::test(EditStore::class, ['record' => $store->getKey()])
            ->assertFormSet(fn (array $state): bool => data_get($state, 'settings.ai_extraction_enabled') === true
                && data_get($state, 'settings.ai_provider_id') === 'p1');
    }
}

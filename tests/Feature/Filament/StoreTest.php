<?php

namespace Tests\Feature\Filament;

use App\Enums\ScraperService;
use App\Filament\Resources\StoreResource;
use App\Filament\Resources\StoreResource\Pages\EditStore;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
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
}

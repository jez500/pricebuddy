<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\StoreResource\Pages\EditStore;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class StoreMissingPriceToggleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['email' => 'test@test.com']));
    }

    public function test_toggle_is_rendered(): void
    {
        $store = Store::factory()->create(['settings' => ['scraper_service' => 'http']]);

        Livewire::test(EditStore::class, ['record' => $store->getKey()])
            ->assertSee('Treat a missing price as out of stock');
    }

    public function test_toggle_persists(): void
    {
        $store = Store::factory()->create(['settings' => ['scraper_service' => 'http']]);

        Livewire::test(EditStore::class, ['record' => $store->getKey()])
            ->set('data.domains', null)
            ->fillForm([
                'domains' => [['domain' => 'example.com']],
                'settings.locale_settings.locale' => 'en_US',
                'settings.locale_settings.currency' => 'USD',
                'settings.missing_price_out_of_stock' => true,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue($store->fresh()->missing_price_out_of_stock);
    }
}

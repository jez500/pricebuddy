<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\StoreResource\Pages\EditStore;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class StoreAvailabilitySchemaOrgTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['email' => 'test@test.com']));
    }

    public function test_match_values_hidden_for_schema_org_and_shown_for_selector(): void
    {
        $store = Store::factory()->create([
            'scrape_strategy' => ['availability' => ['type' => 'selector', 'value' => '.stock']],
        ]);

        Livewire::test(EditStore::class, ['record' => $store->getKey()])
            ->assertSee('Match values')
            ->assertSee('Default status')
            ->set('data.scrape_strategy.availability.type', 'schema_org')
            ->assertDontSee('Match values')
            ->assertDontSee('Default status');
    }
}

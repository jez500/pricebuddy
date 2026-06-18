<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\StoreResource\Pages\EditStore;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class StoreStrategyDtoBridgeTest extends TestCase
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

    public function test_edit_fill_from_dto_populates_form_fields(): void
    {
        $store = Store::factory()->create([
            'scrape_strategy' => [
                'title' => ['type' => 'selector', 'value' => 'h1.title'],
                'price' => ['type' => 'selector', 'value' => '.price'],
                'image' => ['type' => 'selector', 'value' => 'img|src'],
            ],
        ]);

        $this->actingAs($this->user);

        Livewire::test(EditStore::class, ['record' => $store->getKey()])
            ->assertFormSet(fn (array $state): bool => data_get($state, 'scrape_strategy.title.type') === 'selector'
                && data_get($state, 'scrape_strategy.title.value') === 'h1.title'
                && data_get($state, 'scrape_strategy.price.value') === '.price'
            );
    }

    public function test_edit_save_persists_strategy_via_dto(): void
    {
        $store = Store::factory()->create([
            'scrape_strategy' => [
                'title' => ['type' => 'selector', 'value' => 'h1.old'],
                'price' => ['type' => 'selector', 'value' => '.price'],
                'image' => ['type' => 'selector', 'value' => 'img|src'],
            ],
        ]);

        $this->actingAs($this->user);

        Livewire::test(EditStore::class, ['record' => $store->getKey()])
            ->set('data.domains', null)
            ->fillForm([
                'name' => $store->name,
                'domains' => [
                    ['domain' => 'example.com'],
                ],
                'settings.locale_settings.locale' => 'en_US',
                'settings.locale_settings.currency' => 'USD',
                'scrape_strategy.title.value' => 'h2.new',
                'scrape_strategy.title.type' => 'selector',
                'scrape_strategy.price.value' => '.price',
                'scrape_strategy.price.type' => 'selector',
                'scrape_strategy.image.value' => 'img|src',
                'scrape_strategy.image.type' => 'selector',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $store->refresh();

        $this->assertSame('h2.new', $store->scrape_strategy->title->value);
    }
}

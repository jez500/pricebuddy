<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\StoreResource;
use App\Filament\Resources\StoreResource\Pages\EditStore;
use App\Models\Product;
use App\Models\Store;
use App\Models\Url;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;
use Tests\Traits\ScraperTrait;

class StoreTestModalTest extends TestCase
{
    use RefreshDatabase;
    use ScraperTrait;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        User::query()->delete();

        $this->user = User::factory()->create([
            'name' => 'Tester',
            'email' => 'tester@test.com',
            'password' => Hash::make('password'),
        ]);

        $this->actingAs($this->user);
    }

    private function storeWithProducts(int $count): Store
    {
        $store = Store::factory()->create(['settings' => ['scraper_service' => 'http']]);

        for ($i = 1; $i <= $count; $i++) {
            Url::factory()
                ->for($store)
                ->for(Product::factory()->create(['title' => "Shortcut Product {$i}"]))
                ->create();
        }

        return $store;
    }

    public function test_run_scrape_uses_unsaved_form_values_and_does_not_persist(): void
    {
        $this->mockScrape('19.99', 'Widget');

        // Saved config has a BROKEN price selector (won't match the mock page).
        $store = Store::factory()->create([
            'settings' => ['scraper_service' => 'http'],
            'domains' => [['domain' => 'example.com']],
            'scrape_strategy' => [
                'title' => ['type' => 'selector', 'value' => 'meta[property=og:title]|content'],
                'price' => ['type' => 'selector', 'value' => '.does-not-exist'],
            ],
        ]);

        $component = Livewire::test(EditStore::class, ['record' => $store->getKey()])
            // Fix the price selector in the form only (unsaved).
            ->set('data.scrape_strategy.price', ['type' => 'selector', 'value' => 'meta[property=og:price:amount]|content'])
            ->call('runScrape', 'https://example.com/p');

        // The scrape used the UNSAVED working selector.
        $this->assertSame('19.99', data_get($component->get('testScrapeResult'), 'price'));

        // Nothing persisted: the saved (broken) strategy is unchanged.
        $this->assertSame('.does-not-exist', data_get($store->fresh(), 'scrape_strategy.price.value'));
    }

    public function test_test_action_opens_modal_with_product_shortcuts(): void
    {
        $store = $this->storeWithProducts(3);

        Livewire::test(EditStore::class, ['record' => $store->getKey()])
            ->assertActionExists('test')
            ->mountAction('test')
            ->assertSee('Shortcut Product 1')
            ->assertSee('Shortcut Product 2')
            ->assertSee('Shortcut Product 3')
            ->assertSee('Product URL');
    }

    public function test_modal_caps_product_shortcuts_at_five(): void
    {
        $store = $this->storeWithProducts(6);

        Livewire::test(EditStore::class, ['record' => $store->getKey()])
            ->mountAction('test')
            ->assertSee('Shortcut Product 1')
            ->assertSee('Shortcut Product 5')
            ->assertDontSee('Shortcut Product 6');
    }

    public function test_modal_without_products_still_shows_url_input(): void
    {
        $store = Store::factory()->create(['settings' => ['scraper_service' => 'http']]);

        Livewire::test(EditStore::class, ['record' => $store->getKey()])
            ->mountAction('test')
            ->assertSee('Product URL')
            ->assertDontSee('Existing products');
    }

    public function test_dedicated_test_route_is_removed(): void
    {
        $this->assertArrayNotHasKey('test', StoreResource::getPages());
    }
}

<?php

namespace Tests\Feature\Filament;

use App\Dto\AiExtractionResultDto;
use App\Enums\StockStatus;
use App\Filament\Resources\StoreResource;
use App\Filament\Resources\StoreResource\Pages\EditStore;
use App\Models\Product;
use App\Models\Store;
use App\Models\Url;
use App\Models\User;
use App\Exceptions\AiProviderException;
use App\Services\AiExtractionService;
use App\Services\Helpers\SettingsHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Once;
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

        SettingsHelper::$settings = null;
        Cache::flush();
        Once::flush();
    }

    private function configureAiProvider(): void
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

    public function test_compare_with_ai_populates_ai_result(): void
    {
        $this->configureAiProvider();
        $this->mockScrape('19.99', 'Widget');
        $store = Store::factory()->create([
            'settings' => ['scraper_service' => 'http'],
            'domains' => [['domain' => 'example.com']],
        ]);

        $this->mock(AiExtractionService::class, fn ($m) => $m->shouldReceive('extract')
            ->once()
            ->andReturn(new AiExtractionResultDto(
                title: 'AI Widget',
                price: 9.5,
                currency: 'USD',
                image: 'https://example.com/ai.png',
                stockStatus: StockStatus::InStock,
                confidence: 0.88,
            )));

        $component = Livewire::test(EditStore::class, ['record' => $store->getKey()])
            ->call('runScrape', 'https://example.com/p')
            ->call('compareWithAi');

        $ai = $component->get('testAiResult');
        $this->assertSame('AI Widget', $ai['title']);
        $this->assertSame(9.5, $ai['price']);
        $this->assertSame('USD', $ai['currency']);
        $this->assertSame('https://example.com/ai.png', $ai['image']);
        $this->assertSame(StockStatus::InStock->getLabel(), $ai['availability']);
        $this->assertSame(0.88, $ai['confidence']);
    }

    public function test_compare_with_ai_does_nothing_without_a_scrape(): void
    {
        $store = Store::factory()->create(['settings' => ['scraper_service' => 'http']]);
        $this->mock(AiExtractionService::class, fn ($m) => $m->shouldReceive('extract')->never());

        $component = Livewire::test(EditStore::class, ['record' => $store->getKey()])
            ->call('compareWithAi')
            ->assertNotified('No scraped HTML to analyse');

        $this->assertNull($component->get('testAiResult'));
    }

    public function test_run_scrape_clears_previous_ai_result(): void
    {
        $this->mockScrape('19.99', 'Widget');
        $store = Store::factory()->create([
            'settings' => ['scraper_service' => 'http'],
            'domains' => [['domain' => 'example.com']],
        ]);

        $component = Livewire::test(EditStore::class, ['record' => $store->getKey()])
            ->set('testAiResult', ['title' => 'stale'])
            ->call('runScrape', 'https://example.com/p');

        $this->assertNull($component->get('testAiResult'));
    }

    public function test_compare_with_ai_warns_when_no_provider_configured(): void
    {
        $this->mockScrape('19.99', 'Widget');
        $store = Store::factory()->create([
            'settings' => ['scraper_service' => 'http'],
            'domains' => [['domain' => 'example.com']],
        ]);

        $this->mock(AiExtractionService::class, fn ($m) => $m->shouldReceive('extract')->never());

        $component = Livewire::test(EditStore::class, ['record' => $store->getKey()])
            ->call('runScrape', 'https://example.com/p')
            ->call('compareWithAi')
            ->assertNotified('No AI provider configured');

        $this->assertNull($component->get('testAiResult'));
    }

    public function test_compare_with_ai_warns_when_extract_returns_null(): void
    {
        $this->configureAiProvider();
        $this->mockScrape('19.99', 'Widget');
        $store = Store::factory()->create([
            'settings' => ['scraper_service' => 'http'],
            'domains' => [['domain' => 'example.com']],
        ]);

        $this->mock(AiExtractionService::class, fn ($m) => $m->shouldReceive('extract')->once()->andReturnNull());

        $component = Livewire::test(EditStore::class, ['record' => $store->getKey()])
            ->call('runScrape', 'https://example.com/p')
            ->call('compareWithAi')
            ->assertNotified('AI found no data in the page');

        $this->assertNull($component->get('testAiResult'));
    }

    public function test_compare_with_ai_shows_provider_error_notification(): void
    {
        $this->configureAiProvider();
        $this->mockScrape('19.99', 'Widget');
        $store = Store::factory()->create([
            'settings' => ['scraper_service' => 'http'],
            'domains' => [['domain' => 'example.com']],
        ]);

        $this->mock(AiExtractionService::class, fn ($m) => $m->shouldReceive('extract')
            ->once()->andThrow(new AiProviderException('AI provider request failed (X).')));

        $component = Livewire::test(EditStore::class, ['record' => $store->getKey()])
            ->call('runScrape', 'https://example.com/p')
            ->call('compareWithAi')
            ->assertNotified('AI provider error');

        $this->assertNull($component->get('testAiResult'));
    }

    public function test_scraped_results_render_in_the_table(): void
    {
        $this->mockScrape('19.99', 'Widget');
        $store = Store::factory()->create([
            'settings' => ['scraper_service' => 'http'],
            'domains' => [['domain' => 'example.com']],
        ]);

        Livewire::test(EditStore::class, ['record' => $store->getKey()])
            ->mountAction('test')
            ->call('runScrape', 'https://example.com/p')
            ->assertSee('Scraped'); // results-table header — only the results table renders this
    }

    public function test_compare_with_ai_renders_the_ai_column(): void
    {
        $this->configureAiProvider();
        $this->mockScrape('19.99', 'Widget');
        $store = Store::factory()->create([
            'settings' => ['scraper_service' => 'http'],
            'domains' => [['domain' => 'example.com']],
        ]);

        $this->mock(AiExtractionService::class, fn ($m) => $m->shouldReceive('extract')
            ->once()->andReturn(new AiExtractionResultDto(title: 'AI Widget', price: 9.5, confidence: 0.88)));

        Livewire::test(EditStore::class, ['record' => $store->getKey()])
            ->mountAction('test')
            ->call('runScrape', 'https://example.com/p')
            ->call('compareWithAi')
            ->assertSee('AI Widget'); // value only present in the AI column
    }

    public function test_compare_button_hidden_when_ai_not_configured(): void
    {
        $this->mockScrape('19.99', 'Widget');
        $store = Store::factory()->create([
            'settings' => ['scraper_service' => 'http'],
            'domains' => [['domain' => 'example.com']],
        ]);

        Livewire::test(EditStore::class, ['record' => $store->getKey()])
            ->mountAction('test')
            ->call('runScrape', 'https://example.com/p')
            ->assertDontSee('Compare with AI');
    }

    public function test_compare_button_shown_when_ai_configured_after_scrape(): void
    {
        $this->configureAiProvider();
        $this->mockScrape('19.99', 'Widget');
        $store = Store::factory()->create([
            'settings' => ['scraper_service' => 'http'],
            'domains' => [['domain' => 'example.com']],
        ]);

        Livewire::test(EditStore::class, ['record' => $store->getKey()])
            ->mountAction('test')
            ->call('runScrape', 'https://example.com/p')
            ->assertSee('Compare with AI');
    }
}

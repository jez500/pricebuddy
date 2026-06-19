<?php

namespace Tests\Feature\Filament;

use App\Dto\AiExtractionResultDto;
use App\Enums\StockStatus;
use App\Exceptions\AiProviderException;
use App\Filament\Resources\StoreResource;
use App\Filament\Resources\StoreResource\Pages\EditStore;
use App\Models\Product;
use App\Models\Store;
use App\Models\Url;
use App\Models\User;
use App\Services\AiExtractionService;
use App\Services\Helpers\SettingsHelper;
use App\Services\ScrapeUrl;
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
            ->assertSee('Or product URL'); // label adapts when shortcuts are present
    }

    public function test_modal_caps_product_shortcuts_at_five(): void
    {
        $store = $this->storeWithProducts(6);

        // The 5 most-recently-added products are shown (newest first); the oldest is dropped.
        Livewire::test(EditStore::class, ['record' => $store->getKey()])
            ->mountAction('test')
            ->assertSee('Shortcut Product 6')
            ->assertSee('Shortcut Product 2')
            ->assertDontSee('Shortcut Product 1');
    }

    public function test_modal_without_products_still_shows_url_input(): void
    {
        $store = Store::factory()->create(['settings' => ['scraper_service' => 'http']]);

        Livewire::test(EditStore::class, ['record' => $store->getKey()])
            ->mountAction('test')
            ->assertSee('Product URL')
            ->assertDontSee('Or product URL')
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
            ->once()->andReturn(new AiExtractionResultDto(title: 'AI Widget', description: 'AI description text', price: 9.5, confidence: 0.88)));

        Livewire::test(EditStore::class, ['record' => $store->getKey()])
            ->mountAction('test')
            ->call('runScrape', 'https://example.com/p')
            ->call('compareWithAi')
            ->assertSee('AI Widget') // value only present in the AI column
            ->assertSee('AI description text');
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

    public function test_modal_description_without_ai(): void
    {
        $store = Store::factory()->create(['settings' => ['scraper_service' => 'http']]);

        Livewire::test(EditStore::class, ['record' => $store->getKey()])
            ->mountAction('test')
            ->assertSee('Dry run the current store settings')
            ->assertDontSee('and compare with AI');
    }

    public function test_modal_description_with_ai(): void
    {
        $this->configureAiProvider();
        $store = Store::factory()->create(['settings' => ['scraper_service' => 'http']]);

        Livewire::test(EditStore::class, ['record' => $store->getKey()])
            ->mountAction('test')
            ->assertSee('Dry run the current store settings and compare with AI');
    }

    public function test_run_scrape_uses_the_selected_scraper(): void
    {
        $store = Store::factory()->create([
            'settings' => ['scraper_service' => 'http'], // saved as http
            'domains' => [['domain' => 'example.com']],
        ]);

        // Capture the store passed to the scraper and assert the override took effect.
        // ScrapeUrl::new() calls resolve(static::class, ['url' => $url]) which passes
        // constructor args, so we bind a factory closure to intercept the resolution.
        $mock = \Mockery::mock(ScrapeUrl::class);
        $mock->shouldReceive('scrape')->once()
            ->withArgs(fn (array $opts): bool => $opts['store']->scraper_service === 'api')
            ->andReturn(['title' => 'Widget', 'price' => '9.99', 'body' => '<html>']);

        $this->app->bind(ScrapeUrl::class, fn () => $mock);

        $component = Livewire::test(EditStore::class, ['record' => $store->getKey()])
            ->call('runScrape', 'https://example.com/p', 'api');

        $this->assertSame('9.99', data_get($component->get('testScrapeResult'), 'price'));
    }

    public function test_run_scrape_records_url_and_effective_scraper(): void
    {
        $this->mockScrape('19.99', 'Widget');
        $store = Store::factory()->create([
            'settings' => ['scraper_service' => 'http'],
            'domains' => [['domain' => 'example.com']],
        ]);

        $component = Livewire::test(EditStore::class, ['record' => $store->getKey()])
            ->call('runScrape', 'https://example.com/p', 'api');

        $this->assertSame('https://example.com/p', $component->get('testUrl'));
        $this->assertSame('api', $component->get('testScraper'));
    }

    public function test_run_scrape_records_store_scraper_when_no_override(): void
    {
        $this->mockScrape('19.99', 'Widget');
        $store = Store::factory()->create([
            'settings' => ['scraper_service' => 'http'],
            'domains' => [['domain' => 'example.com']],
        ]);

        $component = Livewire::test(EditStore::class, ['record' => $store->getKey()])
            ->call('runScrape', 'https://example.com/p');

        $this->assertSame('http', $component->get('testScraper'));
    }

    public function test_changing_scraper_retests_with_new_scraper_uncached(): void
    {
        $store = Store::factory()->create([
            'settings' => ['scraper_service' => 'http'],
            'domains' => [['domain' => 'example.com']],
        ]);

        $seen = [];
        $mock = \Mockery::mock(ScrapeUrl::class);
        $mock->shouldReceive('scrape')->andReturnUsing(function (array $opts) use (&$seen) {
            $seen[] = ['scraper' => $opts['store']->scraper_service, 'use_cache' => $opts['use_cache']];

            return ['title' => 'Widget', 'price' => '9.99', 'body' => '<html>'];
        });
        $this->app->bind(ScrapeUrl::class, fn () => $mock);

        $component = Livewire::test(EditStore::class, ['record' => $store->getKey()])
            ->call('runScrape', 'https://example.com/p')        // initial: store scraper (http)
            ->call('runScrape', 'https://example.com/p', 'api'); // re-test with new scraper

        $this->assertSame(['scraper' => 'http', 'use_cache' => false], $seen[0]);
        $this->assertSame(['scraper' => 'api', 'use_cache' => false], $seen[1]);
        $this->assertSame('api', $component->get('testScraper'));
    }

    public function test_change_scraper_select_has_a_live_wire_model_binding(): void
    {
        $this->mockScrape('19.99', 'Widget');
        $store = Store::factory()->create([
            'settings' => ['scraper_service' => 'http'],
            'domains' => [['domain' => 'example.com']],
        ]);

        Livewire::test(EditStore::class, ['record' => $store->getKey()])
            ->mountAction('test')
            ->call('runScrape', 'https://example.com/p')
            ->assertSeeHtml('wire:model.live="mountedActionsData.0.test_scraper"');
    }

    public function test_changing_the_scraper_select_retests_with_the_new_scraper(): void
    {
        $store = Store::factory()->create([
            'settings' => ['scraper_service' => 'http'],
            'domains' => [['domain' => 'example.com']],
        ]);

        $seen = [];
        $mock = \Mockery::mock(ScrapeUrl::class);
        $mock->shouldReceive('scrape')->andReturnUsing(function (array $opts) use (&$seen) {
            $seen[] = $opts['store']->scraper_service;

            return ['title' => 'Widget', 'price' => '9.99', 'body' => '<html>'];
        });
        $this->app->bind(ScrapeUrl::class, fn () => $mock);

        $component = Livewire::test(EditStore::class, ['record' => $store->getKey()])
            ->mountAction('test')
            ->call('runScrape', 'https://example.com/p')        // initial scrape (http)
            ->set('mountedActionsData.0.test_scraper', 'api');  // change select -> reactive re-test

        $this->assertSame(['http', 'api'], $seen);
        $this->assertSame('api', $component->get('testScraper'));
    }

    public function test_results_show_a_loading_indicator(): void
    {
        $this->mockScrape('19.99', 'Widget');
        $store = Store::factory()->create([
            'settings' => ['scraper_service' => 'http'],
            'domains' => [['domain' => 'example.com']],
        ]);

        Livewire::test(EditStore::class, ['record' => $store->getKey()])
            ->mountAction('test')
            ->call('runScrape', 'https://example.com/p')
            ->assertSee('Scraping…')
            // Scoped to the scraper-change re-test so it doesn't fire during Compare with AI.
            ->assertSeeHtml('wire:loading.remove wire:target="mountedActionsData.0.test_scraper"');
    }
}

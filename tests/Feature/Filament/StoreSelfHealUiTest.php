<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\StoreResource\Pages\EditStore;
use App\Models\Store;
use App\Models\User;
use App\Services\AiConfigHealer;
use App\Services\Helpers\SettingsHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Once;
use Livewire\Livewire;
use Tests\TestCase;

class StoreSelfHealUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['email' => 'test@test.com']));

        // Heal-action visibility is gated on AI settings, which IntegrationHelper reads
        // through a once()-memoized SettingsHelper::$settings static. Both survive between
        // tests in a parallel worker, so reset them here to stop a prior test's "AI enabled"
        // state leaking into the disabled-by-default assertions below.
        SettingsHelper::$settings = null;
        Once::flush();
    }

    private function preview(): array
    {
        return [
            'fields' => ['price' => ['type' => 'regex', 'value' => '"price":([0-9.]+)', 'prepend' => '', 'append' => '']],
            'extracted' => ['price' => '48.95'],
            'usedBrowser' => true,
        ];
    }

    public function test_preview_self_heal_uses_the_scraped_url_when_no_url_passed(): void
    {
        // The product-shortcut buttons call runScrape() (setting testUrl) without filling
        // the test_url field, so the heal action must use the URL that was actually scraped.
        $store = Store::factory()->create(['settings' => ['scraper_service' => 'http']]);

        $this->mock(AiConfigHealer::class, fn ($m) => $m->shouldReceive('previewForUrl')
            ->once()
            ->with('https://shop.test/from-button', \Mockery::any(), \Mockery::any())
            ->andReturn($this->preview()));

        Livewire::test(EditStore::class, ['record' => $store->getKey()])
            ->set('testUrl', 'https://shop.test/from-button')
            ->set('testScrapeResult', ['body' => '<html></html>'])
            ->call('previewSelfHeal')
            ->assertSet('healPreview.extracted.price', '48.95');
    }

    public function test_preview_self_heal_populates_heal_preview(): void
    {
        $store = Store::factory()->create(['settings' => ['scraper_service' => 'http']]);

        $this->mock(AiConfigHealer::class, fn ($m) => $m->shouldReceive('previewForUrl')
            ->once()
            ->andReturn($this->preview()));

        Livewire::test(EditStore::class, ['record' => $store->getKey()])
            ->call('previewSelfHeal', 'https://shop.test/p')
            ->assertSet('healPreview.extracted.price', '48.95');
    }

    public function test_preview_self_heal_notifies_when_ai_returns_nothing(): void
    {
        $store = Store::factory()->create(['settings' => ['scraper_service' => 'http']]);

        $this->mock(AiConfigHealer::class, fn ($m) => $m->shouldReceive('previewForUrl')
            ->once()
            ->andReturnNull());

        Livewire::test(EditStore::class, ['record' => $store->getKey()])
            ->call('previewSelfHeal', 'https://shop.test/p')
            ->assertNotified()
            ->assertSet('healPreview', null);
    }

    public function test_apply_self_heal_writes_form_state_without_persisting(): void
    {
        $store = Store::factory()->create([
            'scrape_strategy' => [],
            'settings' => ['scraper_service' => 'http'],
        ]);

        Livewire::test(EditStore::class, ['record' => $store->getKey()])
            ->set('healPreview', $this->preview())
            ->call('applySelfHeal')
            ->assertSet('data.scrape_strategy.price.value', '"price":([0-9.]+)')
            ->assertSet('data.settings.scraper_service', 'api')
            ->assertSet('healPreview', null);

        // Nothing persisted until the user clicks Save.
        $fresh = $store->fresh()->scrape_strategy;
        $this->assertNull($fresh->price);
        $this->assertNull($fresh->title);
        $this->assertNull($fresh->availability);
        $this->assertSame('http', data_get($store->fresh()->settings, 'scraper_service'));
    }

    public function test_discard_self_heal_clears_preview(): void
    {
        $store = Store::factory()->create();

        Livewire::test(EditStore::class, ['record' => $store->getKey()])
            ->set('healPreview', $this->preview())
            ->call('discardSelfHeal')
            ->assertSet('healPreview', null);
    }

    private function enableHealing(): void
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

    public function test_heal_action_hidden_when_healing_disabled(): void
    {
        $store = Store::factory()->create();

        Livewire::test(EditStore::class, ['record' => $store->getKey()])
            ->mountAction('test')
            ->set('testScrapeResult', ['title' => 'X', 'price' => '1', 'body' => '<html></html>'])
            ->assertDontSee('Heal with AI');
    }

    public function test_heal_action_and_proposal_render_when_enabled(): void
    {
        $this->enableHealing();
        $store = Store::factory()->create();

        Livewire::test(EditStore::class, ['record' => $store->getKey()])
            ->mountAction('test')
            ->set('testScrapeResult', ['title' => 'X', 'price' => '1', 'body' => '<html></html>'])
            ->assertSee('Heal with AI')
            ->set('healPreview', $this->preview())
            ->assertSee('48.95')
            ->assertSee('Apply to form')
            ->assertSee('Browser scraping required');
    }
}

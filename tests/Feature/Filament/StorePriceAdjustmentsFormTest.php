<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\StoreResource\Pages\EditStore;
use App\Filament\Resources\StoreResource\Pages\ListStores;
use App\Jobs\RecalculateStorePrices;
use App\Models\Store;
use App\Models\Url;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class StorePriceAdjustmentsFormTest extends TestCase
{
    use RefreshDatabase;

    protected Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->actingAs(User::factory()->create());
        $this->store = Store::factory()->create(['settings' => [
            'scraper_service' => 'http',
            'locale_settings' => ['locale' => 'en_US', 'currency' => 'EUR'],
        ]]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function editStore(array $data): Testable
    {
        return Livewire::test(EditStore::class, ['record' => $this->store->getKey()])
            ->set('data.domains', null)
            ->fillForm(array_merge(['domains' => [['domain' => 'example.com']]], $data));
    }

    public function test_sections_are_rendered(): void
    {
        Livewire::test(EditStore::class, ['record' => $this->store->getKey()])
            ->assertSee('Price adjustments')
            ->assertSee('Shipping strategy')
            ->assertFormFieldIsHidden('price_adjustments_apply');
    }

    public function test_adjustments_and_shipping_strategy_persist(): void
    {
        $this->editStore([
            'settings.price_adjustments.tax_included_percent' => 19,
            'settings.price_adjustments.tax_percent' => 23,
            'settings.price_adjustments.shipping_cost' => 7.65,
            'settings.price_adjustments.free_shipping_from' => 39,
            'settings.price_adjustments.tax_on_shipping' => false,
            'scrape_strategy.shipping.type' => 'selector',
            'scrape_strategy.shipping.value' => '#delivery|data-price',
        ])
            ->assertFormFieldIsVisible('price_adjustments_apply')
            ->call('save')
            ->assertHasNoFormErrors();

        $store = $this->store->fresh();
        $this->assertSame(19.0, $store->price_adjustments->taxIncludedPercent);
        $this->assertSame(23.0, $store->price_adjustments->taxPercent);
        $this->assertSame(7.65, $store->price_adjustments->shippingCost);
        $this->assertSame(39.0, $store->price_adjustments->freeShippingFrom);
        $this->assertTrue($store->hasShippingStrategy());
        $this->assertSame('http', $store->settings['scraper_service'], 'other settings are kept');
    }

    public function test_changed_adjustments_recalculate_all_past_prices_by_default(): void
    {
        $this->editStore(['settings.price_adjustments.tax_percent' => 23])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('Recalculating 0 prices, usually a few seconds.');

        Queue::assertPushed(RecalculateStorePrices::class, fn (RecalculateStorePrices $job): bool => $job->storeId === $this->store->id
            && $job->since === null
            && $job->runId === $this->store->fresh()->price_recalculation_run);
    }

    public function test_recalculates_prices_since_a_date(): void
    {
        $this->editStore([
            'settings.price_adjustments.tax_percent' => 23,
            'price_adjustments_apply' => 'since',
            'price_adjustments_since' => '2026-11-01',
        ])
            ->call('save')
            ->assertHasNoFormErrors();

        Queue::assertPushed(RecalculateStorePrices::class, fn (RecalculateStorePrices $job): bool => $job->since === '2026-11-01 00:00:00');
    }

    public function test_a_future_date_is_rejected(): void
    {
        $this->editStore([
            'settings.price_adjustments.tax_percent' => 23,
            'price_adjustments_apply' => 'since',
            'price_adjustments_since' => now()->addDay()->toDateString(),
        ])
            ->call('save')
            ->assertHasFormErrors(['price_adjustments_since']);

        Queue::assertNothingPushed();
    }

    public function test_new_prices_only_leaves_past_prices_alone(): void
    {
        $this->editStore([
            'settings.price_adjustments.tax_percent' => 23,
            'price_adjustments_apply' => 'new',
        ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(23.0, $this->store->fresh()->price_adjustments->taxPercent);
        Queue::assertNothingPushed();
    }

    public function test_changing_only_the_threshold_asks_about_past_prices(): void
    {
        $this->store->update(['settings' => array_merge($this->store->settings, ['price_adjustments' => ['shipping_cost' => 5]])]);

        $this->editStore(['settings.price_adjustments.free_shipping_from' => 50])
            ->assertFormFieldIsVisible('price_adjustments_apply')
            ->call('save')
            ->assertHasNoFormErrors();

        Queue::assertPushed(RecalculateStorePrices::class);
    }

    public function test_saving_unchanged_adjustments_recalculates_nothing(): void
    {
        $this->store->update(['settings' => array_merge($this->store->settings, ['price_adjustments' => ['tax_percent' => 23]])]);

        $this->editStore(['name' => 'Renamed'])
            ->assertFormFieldIsHidden('price_adjustments_apply')
            ->call('save')
            ->assertHasNoFormErrors();

        Queue::assertNothingPushed();
    }

    public function test_changed_adjustments_are_not_saved_during_a_recalculation(): void
    {
        $this->freezeSecond();
        $this->store->queuePriceRecalculation();
        $this->travel(4)->seconds();

        $this->editStore(['settings.price_adjustments.tax_percent' => 23])
            ->call('save')
            ->assertNotified(Notification::make()->title('Not saved')->body('Still recalculating (started 4 s ago). Try again in a moment.')->warning())
            ->assertFormSet(['settings.price_adjustments.tax_percent' => 23]);

        $this->assertTrue($this->store->fresh()->price_adjustments->isEmpty());
        Queue::assertPushed(RecalculateStorePrices::class, 1);
    }

    public function test_other_changes_are_saved_during_a_recalculation(): void
    {
        $this->store->queuePriceRecalculation();

        $this->editStore(['name' => 'Renamed'])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotNotified('Not saved');

        $this->assertSame('Renamed', $this->store->fresh()->name);
    }

    public function test_the_recalculate_button_is_refused_during_a_recalculation(): void
    {
        $this->freezeSecond();
        $this->store->queuePriceRecalculation();
        $this->travel(12)->minutes();

        Livewire::test(EditStore::class, ['record' => $this->store->getKey()])
            ->callAction('recalculatePrices', data: ['price_adjustments_apply' => 'all'])
            ->assertNotified(Notification::make()->title('Prices not recalculated')->body('Still recalculating, no progress for 12 min. Is the queue worker running?')->warning());

        Queue::assertPushed(RecalculateStorePrices::class, 1);
    }

    public function test_the_recalculate_button_starts_a_run_with_the_chosen_scope(): void
    {
        $url = Url::factory()->for($this->store)->create();
        $url->prices()->create(['price' => 100, 'store_id' => $this->store->id, 'created_at' => '2026-10-31 23:59:59']);
        $url->prices()->create(['price' => 100, 'store_id' => $this->store->id, 'created_at' => '2026-11-01 00:00:00']);

        Livewire::test(EditStore::class, ['record' => $this->store->getKey()])
            ->callAction('recalculatePrices', data: ['price_adjustments_apply' => 'since', 'price_adjustments_since' => '2026-11-01'])
            ->assertHasNoActionErrors()
            ->assertNotified('Recalculating 1 price, usually a few seconds.');

        $run = $this->store->fresh()->price_recalculation_run;
        Queue::assertPushed(RecalculateStorePrices::class, fn (RecalculateStorePrices $job): bool => $job->runId === $run
            && $job->since === '2026-11-01 00:00:00');
    }

    public function test_tax_rates_are_percentages(): void
    {
        $this->editStore([
            'settings.price_adjustments.tax_percent' => 150,
            'settings.price_adjustments.shipping_cost' => -1,
            'settings.price_adjustments.free_shipping_from' => -1,
        ])
            ->call('save')
            ->assertHasFormErrors([
                'settings.price_adjustments.tax_percent',
                'settings.price_adjustments.shipping_cost',
                'settings.price_adjustments.free_shipping_from',
            ]);
    }

    public function test_shipping_strategy_cannot_use_schema_org(): void
    {
        $this->editStore(['scrape_strategy.shipping.type' => 'schema_org'])
            ->call('save')
            ->assertHasFormErrors(['scrape_strategy.shipping.type']);
    }

    public function test_the_test_scrape_shows_the_shipping_found(): void
    {
        Http::fake(['*' => Http::response('<html><head><meta property="og:title" content="Widget"><meta property="og:price:amount" content="19.99"></head>'
            .'<body><div id="delivery" data-price="€7.65">€7.65 delivery</div></body></html>')]);

        $component = Livewire::test(EditStore::class, ['record' => $this->store->getKey()])
            ->set('data.scrape_strategy.shipping', ['type' => 'selector', 'value' => '#delivery|data-price'])
            ->call('runScrape', 'https://example.com/p');

        $this->assertSame('€7.65', data_get($component->get('testScrapeResult'), 'shipping'));

        $html = view('filament.resources.store-resource.test-results', [
            'scrape' => $component->get('testScrapeResult'),
            'ai' => null,
            'record' => $this->store,
        ])->render();
        $this->assertMatchesRegularExpression('/Shipping<\/td>\s*<td[^>]*>\s*<span class="break-words">€7\.65<\/span>/', $html);
    }

    public function test_sharing_leaves_out_price_adjustments(): void
    {
        $this->store->update(['settings' => array_merge($this->store->settings, ['price_adjustments' => ['tax_percent' => 23]])]);

        Livewire::test(EditStore::class, ['record' => $this->store->getKey()])
            ->mountAction('share_store')
            // JSON keys in the export, which the form's own field names do not match.
            ->assertSee('"scraper_service"')
            ->assertDontSee('"price_adjustments"');
    }

    public function test_import_ignores_price_adjustments(): void
    {
        $json = json_encode([
            'name' => 'Imported',
            'domains' => [['domain' => 'imported.example']],
            'scrape_strategy' => [
                'title' => ['type' => 'selector', 'value' => 'h1'],
                'price' => ['type' => 'selector', 'value' => '.price'],
                'shipping' => ['type' => 'selector', 'value' => '.shipping'],
            ],
            'settings' => ['scraper_service' => 'http', 'price_adjustments' => ['tax_percent' => 23]],
        ]);

        Livewire::test(ListStores::class)
            ->callAction('import_store', data: ['json' => $json])
            ->assertHasNoActionErrors();

        $store = Store::query()->where('name', 'Imported')->firstOrFail();
        $this->assertTrue($store->price_adjustments->isEmpty());
        $this->assertTrue($store->hasShippingStrategy(), 'the shipping strategy is part of the store');
    }
}

<?php

namespace Tests\Feature\Filament;

use App\Enums\StockStatus;
use App\Events\AvailabilityChangedEvent;
use App\Filament\Forms\VariantFilterFields;
use App\Filament\Resources\ProductResource\Pages\CreateProduct;
use App\Filament\Resources\ProductResource\Pages\ViewProduct;
use App\Filament\Resources\ProductResource\Widgets\ProductUrlStats;
use App\Filament\Resources\ProductResource\Widgets\UrlsTableWidget;
use App\Filament\Resources\StoreResource\Pages\EditStore;
use App\Models\Product;
use App\Models\Store;
use App\Models\Url;
use App\Models\User;
use App\Rules\StoreUrl;
use App\Services\Helpers\SettingsHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Once;
use Livewire\Livewire;
use Tests\TestCase;
use Tests\Traits\VariantTrait;

class UrlVariantFormTest extends TestCase
{
    use RefreshDatabase;
    use VariantTrait;

    const string URL = 'https://example.com/trail-shorts';

    protected User $user;

    protected Store $store;

    protected Product $product;

    protected Url $url;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        $this->store = $this->createVariantStore();
        $this->mockVariantPage();

        $this->product = Product::factory()->for($this->user)->addUrlWithPrices(self::URL, [30, 30])->createOne();

        /** @var Url $url */
        $url = $this->product->urls()->first();
        $this->url = $url;
    }

    public function test_edit_page_widget_sets_the_variant()
    {
        Livewire::test(UrlsTableWidget::class, ['record' => $this->product])
            ->callTableAction('edit', $this->url, data: [
                'url' => self::URL,
                'price_factor' => 1,
                'variant' => ['values' => ['Small']],
            ])
            ->assertHasNoTableActionErrors();

        $this->url->refresh();

        $this->assertSame(['values' => ['Small']], $this->url->variant);
        $this->assertSame(StockStatus::OutOfStock, $this->url->availability);
    }

    public function test_edit_page_widget_shows_the_variant()
    {
        $this->url->update(['variant' => ['values' => ['Small', 'Black']]]);

        Livewire::test(UrlsTableWidget::class, ['record' => $this->product])
            ->assertSee('Small / Black');
    }

    public function test_show_page_widget_sets_and_clears_the_variant()
    {
        Livewire::test(ProductUrlStats::class, ['record' => $this->product])
            ->callAction('edit', data: [
                'url' => self::URL,
                'price_factor' => 1,
                'variant' => ['values' => ['Large'], 'id' => 'TEST100_Large'],
            ], arguments: ['url' => $this->url->getKey()])
            ->assertHasNoActionErrors();

        $this->assertSame(['values' => ['Large'], 'id' => 'TEST100_Large'], $this->url->fresh()->getVariantFilter()->toArray());

        Livewire::test(ProductUrlStats::class, ['record' => $this->product->fresh()])
            ->assertSee('Large')
            ->callAction('edit', data: [
                'url' => self::URL,
                'price_factor' => 1,
                'variant' => ['values' => []],
            ], arguments: ['url' => $this->url->getKey()])
            ->assertHasNoActionErrors();

        $this->assertNull($this->url->fresh()->variant);
    }

    public function test_view_page_add_url_action_sets_the_variant()
    {
        $this->url->delete();

        Livewire::test(ViewProduct::class, ['record' => $this->product->getRouteKey()])
            ->callAction('add_url', data: [
                'url' => self::URL,
                'price_factor' => 1,
                'variant' => ['values' => ['Medium']],
            ])
            ->assertHasNoActionErrors();

        /** @var Url $url */
        $url = $this->product->urls()->first();
        $this->assertSame(['values' => ['Medium']], $url->variant);
        $this->assertSame(StockStatus::OutOfStock, $url->availability);
    }

    public function test_create_page_sets_the_variant()
    {
        Livewire::test(CreateProduct::class)
            ->fillForm([
                'url' => 'https://example.com/other-shorts',
                'create_store' => false,
                'price_factor' => 1,
                'variant' => ['values' => ['X Small']],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        /** @var Url $url */
        $url = Url::query()->where('url', 'https://example.com/other-shorts')->firstOrFail();
        $this->assertSame(['values' => ['X Small']], $url->variant);
        $this->assertNull($url->availability);
    }

    public function test_editing_the_url_and_variant_together_sends_no_back_in_stock_alert()
    {
        $this->url->update(['variant' => ['values' => ['Small']], 'availability' => StockStatus::OutOfStock]);
        $this->product->update(['notify_in_stock' => true]);
        Event::fake([AvailabilityChangedEvent::class]);

        Livewire::test(ProductUrlStats::class, ['record' => $this->product])
            ->callAction('edit', data: [
                'url' => 'https://example.com/trail-shorts-2',
                'price_factor' => 1,
                'variant' => ['values' => ['X Small']],
            ], arguments: ['url' => $this->url->getKey()])
            ->assertHasNoActionErrors();

        Livewire::test(UrlsTableWidget::class, ['record' => $this->product->fresh()])
            ->callTableAction('edit', $this->url->fresh(), data: [
                'url' => 'https://example.com/trail-shorts-3',
                'price_factor' => 1,
                'variant' => ['values' => ['Medium']],
            ])
            ->assertHasNoTableActionErrors();

        $this->url->refresh();
        $this->assertSame('https://example.com/trail-shorts-3', $this->url->url);
        $this->assertSame(['values' => ['Medium']], $this->url->variant);
        $this->assertSame(StockStatus::OutOfStock, $this->url->availability);
        Event::assertNotDispatched(AvailabilityChangedEvent::class);
    }

    public function test_a_real_restock_still_sends_an_alert()
    {
        $this->url->update(['variant' => ['values' => ['Small']], 'availability' => StockStatus::OutOfStock]);
        Event::fake([AvailabilityChangedEvent::class]);

        $this->setSmallInStock(true);
        $this->url->fresh()->updatePrice();

        Event::assertDispatched(AvailabilityChangedEvent::class, fn (AvailabilityChangedEvent $event) => $event->isBackInStock());
    }

    public function test_changing_the_url_forgets_the_pinned_variant()
    {
        $this->url->update(['variant' => ['values' => ['Small'], 'id' => 'TEST100_Small']]);

        Livewire::test(ProductUrlStats::class, ['record' => $this->product])
            ->mountAction('edit', arguments: ['url' => $this->url->getKey()])
            ->assertActionDataSet(['variant.id' => 'TEST100_Small'])
            ->setActionData(['url' => 'https://example.com/other-shorts'])
            ->assertActionDataSet([
                'variant.id' => null,
                'variant.values' => ['Small'],
            ]);
    }

    public function test_detect_only_offers_variants_for_the_url_they_came_from()
    {
        Livewire::test(CreateProduct::class)
            ->fillForm(['url' => self::URL])
            ->callFormComponentAction('variant.values', 'detect_variants')
            ->assertFormSet(['variant_options_url' => self::URL])
            ->assertFormFieldIsVisible('variant_pick')
            // A new URL forgets the old page's variants.
            ->fillForm(['url' => 'https://example.com/other-shorts'])
            ->assertFormSet(['variant_options' => [], 'variant_options_url' => null])
            ->assertFormFieldIsHidden('variant_pick');
    }

    public function test_a_failed_detect_clears_the_previous_results()
    {
        Livewire::test(CreateProduct::class)
            ->fillForm(['url' => self::URL])
            ->callFormComponentAction('variant.values', 'detect_variants')
            ->assertFormFieldIsVisible('variant_pick')
            // Two URLs can't be detected, and the earlier results must not linger.
            ->set('data.url', self::URL.' https://example.com/b')
            ->callFormComponentAction('variant.values', 'detect_variants')
            ->assertFormSet(['variant_options' => [], 'variant_options_url' => null])
            ->assertFormFieldIsHidden('variant_pick');
    }

    /**
     * A store whose pages only have prices on their variants: the page price selector
     * matches nothing.
     */
    protected function useVariantOnlyPrices(): void
    {
        $this->store->update(['scrape_strategy' => array_merge($this->store->scrape_strategy->toArray(), [
            'price' => ['type' => 'selector', 'value' => '.no-page-price'],
        ])]);
        Cache::flush();
    }

    public function test_url_check_uses_the_submitted_variant()
    {
        $this->useVariantOnlyPrices();
        $fails = function (array $variant): array {
            $messages = [];
            StoreUrl::forVariant($variant)->validate('url', self::URL, function (string $message) use (&$messages): void {
                $messages[] = $message;
            });

            return $messages;
        };

        // The page as a whole has no price, but in-stock Large has one, and out of
        // stock Small needs none.
        $this->assertSame([], $fails(['values' => ['Large']]));
        $this->assertSame([], $fails(['values' => ['Small']]));
        $this->assertSame(['The url does not contain a valid title or price'], $fails([]));
    }

    public function test_url_check_rejects_a_variant_the_store_cannot_find()
    {
        $strategy = $this->store->scrape_strategy->toArray();
        unset($strategy['variants']);
        $this->store->update(['scrape_strategy' => $strategy]);
        Cache::flush();

        $messages = [];
        StoreUrl::forVariant(['values' => ['Small']])->validate('url', self::URL, function (string $message) use (&$messages): void {
            $messages[] = $message;
        });

        $this->assertSame(['The page lists no variants for "Small". Check the store\'s variants strategy.'], $messages);
    }

    public function test_edit_forms_accept_a_new_url_priced_only_by_its_variant()
    {
        $this->useVariantOnlyPrices();

        Livewire::test(ProductUrlStats::class, ['record' => $this->product])
            ->callAction('edit', data: [
                'url' => 'https://example.com/trail-shorts-2',
                'price_factor' => 1,
                'variant' => [],
            ], arguments: ['url' => $this->url->getKey()])
            ->assertHasActionErrors(['url']);

        Livewire::test(ProductUrlStats::class, ['record' => $this->product])
            ->callAction('edit', data: [
                'url' => 'https://example.com/trail-shorts-2',
                'price_factor' => 1,
                'variant' => ['values' => ['Large']],
            ], arguments: ['url' => $this->url->getKey()])
            ->assertHasNoActionErrors();

        Livewire::test(UrlsTableWidget::class, ['record' => $this->product->fresh()])
            ->callTableAction('edit', $this->url->fresh(), data: [
                'url' => 'https://example.com/trail-shorts-3',
                'price_factor' => 1,
                'variant' => ['values' => ['Large']],
            ])
            ->assertHasNoTableActionErrors();

        $this->url->refresh();
        $this->assertSame('https://example.com/trail-shorts-3', $this->url->url);
        $this->assertSame(32.5, (float) $this->url->prices()->latest('id')->first()->price);
    }

    public function test_a_failed_variant_change_keeps_the_edit_open()
    {
        // With AI healing on, the URL check leaves the page to the model, so the
        // variant change itself is what fails.
        SettingsHelper::setSetting('integrated_services', ['ai' => [
            'enabled' => true,
            'default_provider_id' => 'p1',
            'providers' => [['id' => 'p1', 'name' => 'Local', 'type' => 'ollama', 'base_url' => 'http://ai.example:11434', 'model' => 'm']],
        ]]);
        SettingsHelper::$settings = null;
        Once::flush();

        $strategy = $this->store->scrape_strategy->toArray();
        unset($strategy['variants']);
        $this->store->update(['scrape_strategy' => $strategy]);
        Cache::flush();

        Livewire::test(ProductUrlStats::class, ['record' => $this->product])
            ->callAction('edit', data: [
                'url' => self::URL,
                'price_factor' => 5,
                'variant' => ['values' => ['X Small']],
            ], arguments: ['url' => $this->url->getKey()])
            ->assertActionHalted('edit')
            ->assertNotified('Variant not changed');

        Livewire::test(UrlsTableWidget::class, ['record' => $this->product])
            ->callTableAction('edit', $this->url, data: [
                'url' => self::URL,
                'price_factor' => 5,
                'variant' => ['values' => ['X Small']],
            ])
            ->assertTableActionHalted('edit')
            ->assertNotified('Variant not changed');

        $this->url->refresh();
        $this->assertNull($this->url->variant);
        $this->assertSame(1.0, (float) $this->url->price_factor);
    }

    public function test_detect_lists_the_page_variants_for_picking()
    {
        $options = VariantFilterFields::detect(self::URL);

        $this->assertSame(['X Small', 'Small', 'Medium', 'Large'], array_column($options, 'label'));
        $this->assertSame(['Small'], $options[1]['options']);
        $this->assertSame('TEST100_Small', $options[1]['id']);
        $this->assertSame('Small · Out of Stock · 30', $options[1]['display']);
    }

    public function test_detect_returns_null_without_a_store()
    {
        $this->assertNull(VariantFilterFields::detect('https://unknown-store.test/product'));
    }

    public function test_store_test_modal_lists_variants()
    {
        $component = Livewire::test(EditStore::class, ['record' => $this->store->getKey()])
            ->mountAction('test')
            ->call('runScrape', self::URL)
            ->assertSee('Variants (4)')
            ->assertSee('TEST100_Medium');

        $this->assertSame(
            ['X Small', 'Small', 'Medium', 'Large'],
            collect(data_get($component->get('testScrapeResult'), 'variants'))->pluck('label')->all(),
        );
    }
}

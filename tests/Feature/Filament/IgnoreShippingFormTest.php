<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\ProductResource\Widgets\ProductUrlStats;
use App\Filament\Resources\ProductResource\Widgets\UrlsTableWidget;
use App\Models\Price;
use App\Models\Product;
use App\Models\Store;
use App\Models\Url;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;
use Tests\Traits\ScraperTrait;

/**
 * "Ignore shipping" on a listing, in the two forms that edit one.
 */
class IgnoreShippingFormTest extends TestCase
{
    use RefreshDatabase;
    use ScraperTrait;

    protected User $user;

    protected Product $product;

    protected Url $url;

    protected Price $older;

    protected Price $newer;

    protected function setUp(): void
    {
        parent::setUp();

        // The URL field checks the page has a title and a price.
        $this->mockScrape(100, 'Example Product');

        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        $store = Store::factory()->create(['settings' => [
            'scraper_service' => 'http',
            'locale_settings' => ['locale' => 'en_US', 'currency' => 'EUR'],
            'price_adjustments' => ['shipping_cost' => 5],
        ]]);
        $this->product = Product::factory()->for($this->user)->create();
        $this->url = Url::factory()->for($store)->for($this->product)->create(['url' => 'https://example.com/p']);

        $this->older = $this->url->prices()->create(['price' => 105, 'scraped_price' => 100, 'store_id' => $store->id, 'created_at' => now()->subDays(5)]);
        $this->newer = $this->url->prices()->create(['price' => 105, 'scraped_price' => 100, 'store_id' => $store->id, 'created_at' => now()->subDay()]);
        Price::saveRecalculated([$this->older, $this->newer], fn (): Store => $store, fn (): bool => false);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function editInTable(array $data): void
    {
        Livewire::test(UrlsTableWidget::class, ['record' => $this->product])
            ->callTableAction('edit', $this->url, data: array_merge(['url' => $this->url->url, 'price_factor' => 1], $data))
            ->assertHasNoTableActionErrors();
    }

    public function test_ignoring_shipping_recalculates_all_past_prices_by_default(): void
    {
        $this->editInTable(['ignore_shipping' => true]);

        $this->assertTrue($this->url->fresh()->ignore_shipping);
        $this->assertSame(100.0, (float) $this->older->fresh()->price);
        $this->assertSame(100.0, (float) $this->newer->fresh()->price);
    }

    public function test_ignoring_shipping_since_a_date(): void
    {
        $this->editInTable([
            'ignore_shipping' => true,
            'price_adjustments_apply' => 'since',
            'price_adjustments_since' => now()->subDays(2)->toDateString(),
        ]);

        $this->assertSame(105.0, (float) $this->older->fresh()->price);
        $this->assertSame(100.0, (float) $this->newer->fresh()->price);
    }

    public function test_ignoring_shipping_for_new_prices_only(): void
    {
        $this->editInTable(['ignore_shipping' => true, 'price_adjustments_apply' => 'new']);

        $this->assertTrue($this->url->fresh()->ignore_shipping);
        $this->assertSame(105.0, (float) $this->older->fresh()->price);
        $this->assertSame(105.0, (float) $this->newer->fresh()->price);
    }

    public function test_the_since_date_is_required(): void
    {
        Livewire::test(UrlsTableWidget::class, ['record' => $this->product])
            ->callTableAction('edit', $this->url, data: [
                'url' => $this->url->url,
                'price_factor' => 1,
                'ignore_shipping' => true,
                'price_adjustments_apply' => 'since',
            ])
            ->assertHasTableActionErrors(['price_adjustments_since']);
    }

    public function test_the_product_page_form_saves_it_too(): void
    {
        Livewire::test(ProductUrlStats::class, ['record' => $this->product])
            ->callAction('edit', data: ['url' => $this->url->url, 'price_factor' => 1, 'ignore_shipping' => true], arguments: ['url' => $this->url->getKey()])
            ->assertHasNoActionErrors();

        $this->assertTrue($this->url->fresh()->ignore_shipping);
        $this->assertSame(100.0, (float) $this->newer->fresh()->price);
    }

    public function test_the_switch_is_only_offered_when_the_store_adds_shipping(): void
    {
        $this->url->store->update(['settings' => array_merge($this->url->store->settings, ['price_adjustments' => ['tax_percent' => 23]])]);

        $this->editInTable(['ignore_shipping' => true]);

        $this->assertFalse($this->url->fresh()->ignore_shipping);
    }

    public function test_the_switch_can_be_turned_off_after_the_store_stops_adding_shipping(): void
    {
        $this->url->update(['ignore_shipping' => true]);
        $this->url->store->update(['settings' => array_merge($this->url->store->settings, ['price_adjustments' => []])]);

        $this->editInTable(['ignore_shipping' => false]);

        $this->assertFalse($this->url->fresh()->ignore_shipping);
    }

    public function test_the_product_page_edit_only_reaches_this_products_urls(): void
    {
        $theirs = Url::factory()->for(Product::factory()->for(User::factory()))->create(['url' => 'https://example.com/theirs', 'price_factor' => 1]);

        Livewire::test(ProductUrlStats::class, ['record' => $this->product])
            ->callAction('edit', data: ['url' => 'https://example.com/p', 'price_factor' => 5], arguments: ['url' => $theirs->getKey()]);

        $this->assertSame('https://example.com/theirs', $theirs->fresh()->url);
        $this->assertSame(1.0, (float) $theirs->fresh()->price_factor);
    }

    public function test_a_failed_recalculation_keeps_the_old_setting_and_prices(): void
    {
        // Fail after the prices were written, when the product's caches are saved.
        $written = false;
        DB::listen(function ($query) use (&$written): void {
            $written = $written || str_starts_with($query->sql, 'insert into `prices`');

            if ($written && str_starts_with($query->sql, 'update `products`')) {
                throw new \RuntimeException('database went away');
            }
        });

        try {
            $this->editInTable(['ignore_shipping' => true]);
            $this->fail('expected the edit to fail');
        } catch (\RuntimeException) {
        }

        $this->assertFalse($this->url->fresh()->ignore_shipping);
        $this->assertSame(105.0, (float) $this->older->fresh()->price);
        $this->assertSame(105.0, (float) $this->newer->fresh()->price);
    }

    public function test_new_prices_only_applies_to_the_price_a_url_change_records(): void
    {
        $other = Store::factory()->create([
            'domains' => [['domain' => 'example2.com']],
            'settings' => ['scraper_service' => 'http', 'price_adjustments' => ['shipping_cost' => 5]],
        ]);

        $this->editInTable([
            'url' => 'https://example2.com/p',
            'ignore_shipping' => true,
            'price_adjustments_apply' => 'new',
        ]);

        $url = $this->url->fresh();
        $this->assertSame($other->id, $url->store_id);
        $this->assertTrue($url->ignore_shipping);
        $this->assertSame(100.0, (float) $url->prices()->reorder('id', 'desc')->first()->price, 'the new URL\'s first price leaves shipping out');
        $this->assertSame(105.0, (float) $this->older->fresh()->price, 'older prices are left alone');
    }

    public function test_the_listings_table_shows_a_badge(): void
    {
        Livewire::test(UrlsTableWidget::class, ['record' => $this->product])->assertDontSee('No shipping');

        $this->url->update(['ignore_shipping' => true]);

        Livewire::test(UrlsTableWidget::class, ['record' => $this->product])->assertSee('No shipping');
    }
}

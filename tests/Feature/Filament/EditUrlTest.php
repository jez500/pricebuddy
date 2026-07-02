<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\ProductResource\Widgets\ProductUrlStats;
use App\Filament\Resources\ProductResource\Widgets\UrlsTableWidget;
use App\Models\Product;
use App\Models\Store;
use App\Models\Url;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;
use Tests\Traits\ScraperTrait;

class EditUrlTest extends TestCase
{
    use RefreshDatabase;
    use ScraperTrait;

    protected User $user;

    protected Product $product;

    protected Url $url;

    protected Store $newStore;

    protected string $newUrl = 'https://example2.com/product';

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockScrape(100, 'Example Product');

        $this->user = User::factory()->create();

        $this->product = Product::factory()
            ->for($this->user)
            ->addUrlWithPrices('https://example.com/product', [10, 20, 30])
            ->createOne();

        /** @var Url $url */
        $url = $this->product->urls()->first();
        $this->url = $url;

        $this->newStore = Store::factory()->createOne([
            'domains' => [['domain' => 'example2.com']],
        ]);

        $this->actingAs($this->user);
    }

    public function test_edit_page_widget_can_change_url_and_keeps_history()
    {
        $historyPriceIds = $this->url->prices()->pluck('id')->all();

        Livewire::test(UrlsTableWidget::class, ['record' => $this->product])
            ->callTableAction('edit', $this->url, data: [
                'url' => $this->newUrl,
                'price_factor' => 1,
            ])
            ->assertHasNoTableActionErrors();

        $this->url->refresh();

        $this->assertSame($this->newUrl, $this->url->url);
        $this->assertSame($this->newStore->getKey(), $this->url->store_id);

        // Existing history rows survive, plus one freshly scraped price.
        $this->assertSame(count($historyPriceIds) + 1, $this->url->prices()->count());
        foreach ($historyPriceIds as $id) {
            $this->assertDatabaseHas('prices', ['id' => $id]);
        }
    }

    public function test_edit_page_widget_rejects_invalid_url_and_leaves_record_unchanged()
    {
        Livewire::test(UrlsTableWidget::class, ['record' => $this->product])
            ->callTableAction('edit', $this->url, data: [
                'url' => 'not-a-valid-url',
                'price_factor' => 5,
            ])
            ->assertHasTableActionErrors(['url']);

        $this->url->refresh();

        // Neither the URL nor the price factor is persisted when the URL is rejected.
        $this->assertSame('https://example.com/product', $this->url->url);
        $this->assertSame(1.0, (float) $this->url->price_factor);
    }

    public function test_show_page_widget_can_change_url_and_keeps_history()
    {
        $historyPriceIds = $this->url->prices()->pluck('id')->all();

        Livewire::test(ProductUrlStats::class, ['record' => $this->product])
            ->callAction('edit', data: [
                'url' => $this->newUrl,
                'price_factor' => 1,
            ], arguments: ['url' => $this->url->getKey()])
            ->assertHasNoActionErrors();

        $this->url->refresh();

        $this->assertSame($this->newUrl, $this->url->url);
        $this->assertSame($this->newStore->getKey(), $this->url->store_id);

        $this->assertSame(count($historyPriceIds) + 1, $this->url->prices()->count());
        foreach ($historyPriceIds as $id) {
            $this->assertDatabaseHas('prices', ['id' => $id]);
        }
    }

    public function test_show_page_widget_rejects_invalid_url_and_leaves_record_unchanged()
    {
        Livewire::test(ProductUrlStats::class, ['record' => $this->product])
            ->callAction('edit', data: [
                'url' => 'not-a-valid-url',
                'price_factor' => 5,
            ], arguments: ['url' => $this->url->getKey()])
            ->assertHasActionErrors(['url']);

        $this->url->refresh();

        // Neither the URL nor the price factor is persisted when the URL is rejected.
        $this->assertSame('https://example.com/product', $this->url->url);
        $this->assertSame(1.0, (float) $this->url->price_factor);
    }
}

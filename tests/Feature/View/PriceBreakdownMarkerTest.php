<?php

namespace Tests\Feature\View;

use App\Filament\Resources\ProductResource;
use App\Models\Product;
use App\Models\Store;
use App\Models\Url;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A price that is not the store's page price carries a "*" that shows its breakdown.
 */
class PriceBreakdownMarkerTest extends TestCase
{
    use RefreshDatabase;

    private const string MARKER = 'aria-label="€324.80 + €10.92 tax + €7.65 shipping"';

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    /**
     * @param  array<string, mixed>  $adjustments
     */
    protected function makeProduct(array $adjustments): Product
    {
        $store = Store::factory()->create([
            'name' => 'Amazon.de',
            'settings' => [
                'locale_settings' => ['locale' => 'en_US', 'currency' => 'EUR'],
                'price_adjustments' => $adjustments,
            ],
        ]);
        $product = Product::factory()->create(['user_id' => $this->user->id]);
        $url = Url::factory()->for($store)->for($product)->create();

        $this->travel(-1)->days();
        $url->updatePrice(330);
        $this->travelBack();
        $url->updatePrice(324.80);

        $product = $product->fresh();
        $product->updateInsightsCache();

        return $product;
    }

    public function test_the_product_page_marks_adjusted_prices(): void
    {
        $product = $this->makeProduct(['tax_included_percent' => 19, 'tax_percent' => 23, 'shipping_cost' => 7.65]);

        $html = $this->get(ProductResource::getUrl('view', ['record' => $product]))->assertOk()->getContent();

        // The store's price card and the insights' best price.
        $this->assertSame(2, substr_count($html, self::MARKER));
        // Right after the price: a space would render as "€343.37 *". Livewire's block
        // markers are comments and do not render.
        $this->assertMatchesRegularExpression('/€343\.37(<!--\[if BLOCK\]><!\[endif\]-->)?<span\s+x-data/', $html);
    }

    public function test_the_dashboard_card_and_its_store_list_mark_adjusted_prices(): void
    {
        $product = $this->makeProduct(['tax_included_percent' => 19, 'tax_percent' => 23, 'shipping_cost' => 7.65]);

        $this->blade('<x-product-card :product="$product" />', ['product' => $product])->assertSee(self::MARKER, escape: false);
        $this->assertStringContainsString(self::MARKER, view('components.prices-column', ['items' => $product->price_cache])->render());
    }

    public function test_page_prices_are_not_marked(): void
    {
        $product = $this->makeProduct([]);

        $this->get(ProductResource::getUrl('view', ['record' => $product]))->assertOk()->assertDontSee('pb-price-breakdown');
        $this->blade('<x-product-card :product="$product" />', ['product' => $product])->assertDontSee('pb-price-breakdown');
        $this->assertStringNotContainsString('pb-price-breakdown', view('components.prices-column', ['items' => $product->price_cache])->render());
    }
}

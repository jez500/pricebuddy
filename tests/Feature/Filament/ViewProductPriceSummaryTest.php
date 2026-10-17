<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\ProductResource;
use App\Models\Product;
use App\Models\Store;
use App\Models\Url;
use App\Models\User;
use App\Services\Helpers\CurrencyHelper;
use App\Services\Helpers\SettingsHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ViewProductPriceSummaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_view_shows_price_min_avg_max(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $product = Product::factory()->create(['user_id' => $user->id]);
        Url::factory()->withPrices([30, 20, 25])->createOne(['product_id' => $product->id]);

        $aggregates = $product->refresh()->price_aggregates;
        $this->assertNotEmpty($aggregates);

        $response = $this->get(ProductResource::getUrl('view', ['record' => $product]));
        $response->assertOk();

        // The summary block is rendered.
        $response->assertSee('product-price-summary');

        // And it shows each aggregate value.
        foreach (['min', 'avg', 'max'] as $method) {
            $response->assertSee($aggregates[$method]);
        }
    }

    public function test_view_hides_summary_when_no_price_history(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $product = Product::factory()->create(['user_id' => $user->id]);

        $this->get(ProductResource::getUrl('view', ['record' => $product]))
            ->assertOk()
            ->assertDontSee('product-price-summary');
    }

    public function test_summary_uses_store_currency_and_locale_instead_of_app_defaults(): void
    {
        SettingsHelper::setSetting('default_locale_settings', ['locale' => 'en_US', 'currency' => 'USD']);

        $user = User::factory()->create();
        $this->actingAs($user);

        $store = Store::factory()->create([
            'settings' => ['locale_settings' => ['locale' => 'en_ZA', 'currency' => 'ZAR']],
        ]);
        $product = Product::factory()->create(['user_id' => $user->id]);
        Url::factory()->withPrices([16999, 14999, 15999])->createOne([
            'product_id' => $product->id,
            'store_id' => $store->id,
        ]);

        $aggregates = $product->refresh()->price_aggregates;
        $response = $this->get(ProductResource::getUrl('view', ['record' => $product]));
        $response->assertOk()->assertSee('product-price-summary');

        foreach (['min' => 14999, 'avg' => 15999, 'max' => 16999] as $method => $value) {
            $expected = CurrencyHelper::toString($value, locale: 'en_ZA', iso: 'ZAR');
            $this->assertSame($expected, $aggregates[$method]);
            $response->assertSee($expected);
        }

        $this->assertSame('USD', CurrencyHelper::getCurrency());
        $this->assertSame('en_US', CurrencyHelper::getLocale());
    }
}

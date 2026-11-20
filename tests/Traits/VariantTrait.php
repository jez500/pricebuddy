<?php

namespace Tests\Traits;

use App\Models\Store;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * A product page whose sizes have their own stock (tests/Fixtures/Variants/next-data.html):
 * X Small in stock, Small and Medium out of stock, Large in stock at a higher price. The
 * page as a whole says it is in stock, like many real stores.
 */
trait VariantTrait
{
    protected bool $smallInStock = false;

    protected bool $variantPageFails = false;

    /**
     * @return array<string, mixed>
     */
    protected function nextDataVariantStrategy(): array
    {
        return [
            'type' => 'selector',
            'value' => 'script#__NEXT_DATA__',
            'path' => 'props.pageProps.product.variants',
            'options' => ['options.*.value.*.label'],
            'availability' => 'inventory.isInStock',
            'price' => 'prices.price',
            'id' => 'sku',
        ];
    }

    /**
     * A store for example.com that reads the fixture's title and price, and optionally
     * its variants.
     *
     * @param  array<string, mixed>  $strategy
     */
    protected function createVariantStore(bool $withVariants = true, array $strategy = []): Store
    {
        Store::query()->delete();

        return Store::factory()->createOne([
            'domains' => [['domain' => 'example.com']],
            'scrape_strategy' => array_merge([
                'title' => ['type' => 'selector', 'value' => 'meta[property=og:title]|content'],
                'price' => ['type' => 'selector', 'value' => 'meta[property=og:price:amount]|content'],
                'image' => ['type' => 'selector', 'value' => 'meta[property=og:image]|content'],
            ], $withVariants ? ['variants' => $this->nextDataVariantStrategy()] : [], $strategy),
        ]);
    }

    protected function variantPageHtml(bool $smallInStock = false): string
    {
        $html = (string) file_get_contents(base_path('tests/Fixtures/Variants/next-data.html'));

        if ($smallInStock) {
            $html = str_replace(
                '"sku":"TEST100_Small","inventory":{"count":null,"isInStock":false}',
                '"sku":"TEST100_Small","inventory":{"count":null,"isInStock":true}',
                $html,
            );
        }

        return $html;
    }

    /**
     * Fake the page. Call setSmallInStock() to change it later in a test: the scrape cache
     * is cleared so the next scrape sees the change.
     */
    protected function mockVariantPage(bool $smallInStock = false): void
    {
        $this->smallInStock = $smallInStock;

        Http::fake(fn () => $this->variantPageFails
            ? Http::response('Service unavailable', 503)
            : Http::response($this->variantPageHtml($this->smallInStock)));
    }

    /**
     * Make the faked page fail to load (or load again), eg a network or server error.
     */
    protected function setVariantPageFails(bool $fails): void
    {
        $this->variantPageFails = $fails;

        Cache::flush();
    }

    protected function setSmallInStock(bool $smallInStock): void
    {
        $this->smallInStock = $smallInStock;

        Cache::flush();
    }
}

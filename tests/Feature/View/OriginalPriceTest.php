<?php

namespace Tests\Feature\View;

use App\Dto\PriceCacheDto;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class OriginalPriceTest extends TestCase
{
    protected function cache(array $overrides = []): PriceCacheDto
    {
        return PriceCacheDto::fromArray($this->cacheArray($overrides));
    }

    /**
     * A price cache row as Product::buildPriceCache() stores it.
     */
    protected function cacheArray(array $overrides = []): array
    {
        return array_merge([
            'price' => 150,
            'unit_price' => 150,
            'store_name' => 'Frank Webb',
            'history' => ['2026-11-18' => 150],
            'locale' => 'en_US',
            'currency' => 'CAD',
            'original_price' => 100,
            'original_unit_price' => 100,
            'original_currency' => 'USD',
            'original_locale' => 'en_US',
            'exchange_rate' => 1.5,
        ], $overrides);
    }

    public function test_it_shows_the_original_price_with_the_rate_as_a_tooltip(): void
    {
        $html = Blade::render('<x-original-price :cache="$cache" brackets />', ['cache' => $this->cache()]);

        $this->assertStringContainsString('($100.00)', $html);
        $this->assertStringContainsString('title="Converted from USD at 1 USD = 1.5 CAD"', $html);
    }

    public function test_it_renders_nothing_for_an_unconverted_price(): void
    {
        $cache = $this->cache(['currency' => 'USD', 'original_currency' => null]);

        $this->assertSame('', trim(Blade::render('<x-original-price :cache="$cache" />', ['cache' => $cache])));
    }

    public function test_the_prices_list_shows_both_currencies(): void
    {
        $html = view('components.prices-column', ['items' => [$this->cacheArray()]])->render();

        $this->assertStringContainsString('CA$150.00', $html);
        $this->assertStringContainsString('($100.00)', $html);
    }
}

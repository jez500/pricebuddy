<?php

namespace Tests\Unit\Services;

use App\Dto\VariantDto;
use App\Dto\VariantFilterDto;
use App\Enums\StockStatus;
use App\Services\VariantMatcher;
use Illuminate\Support\Collection;
use Tests\TestCase;

class VariantMatcherTest extends TestCase
{
    /**
     * @return Collection<int, VariantDto>
     */
    protected function sizes(): Collection
    {
        return collect([
            new VariantDto('X Small', ['X Small'], StockStatus::InStock, '30', 'SKU-XS'),
            new VariantDto('Small', ['Small'], StockStatus::OutOfStock, '30', 'SKU-S'),
            new VariantDto('X-Large', ['X-Large'], StockStatus::InStock, '32', 'SKU-XL'),
        ]);
    }

    /**
     * @return Collection<int, VariantDto>
     */
    protected function sizesAndColours(): Collection
    {
        return collect([
            new VariantDto('Small / Black', ['Small', 'Black'], StockStatus::OutOfStock, '45', 'S-BLK'),
            new VariantDto('Small / Grey', ['Small', 'Grey'], StockStatus::InStock, '47', 'S-GRY'),
            new VariantDto('Small / Red', ['Small', 'Red'], StockStatus::InStock, '44', 'S-RED'),
            new VariantDto('Large / Black', ['Large', 'Black'], StockStatus::InStock, '45', 'L-BLK'),
        ]);
    }

    protected function filter(array $values, ?string $id = null): VariantFilterDto
    {
        return VariantFilterDto::fromArray(['values' => $values, 'id' => $id]);
    }

    public function test_small_does_not_match_x_small()
    {
        $matched = VariantMatcher::match($this->sizes(), $this->filter(['Small']));

        $this->assertSame(['SKU-S'], $matched->pluck('id')->all());
    }

    public function test_matching_ignores_case_hyphens_and_spacing()
    {
        $this->assertSame(['SKU-XL'], VariantMatcher::match($this->sizes(), $this->filter(['x large']))->pluck('id')->all());
        $this->assertSame(['SKU-XS'], VariantMatcher::match($this->sizes(), $this->filter(['  X-SMALL ']))->pluck('id')->all());
        $this->assertSame(['SKU-XS'], VariantMatcher::match($this->sizes(), $this->filter(['XSmall']))->pluck('id')->all());
        $this->assertSame(['SKU-XL'], VariantMatcher::match($this->sizes(), $this->filter(['x_large']))->pluck('id')->all());
    }

    public function test_every_value_must_match()
    {
        $matched = VariantMatcher::match($this->sizesAndColours(), $this->filter(['small', 'black']));

        $this->assertSame(['S-BLK'], $matched->pluck('id')->all());
    }

    public function test_pinned_id_narrows_the_matching_values_to_one_variant()
    {
        $matched = VariantMatcher::match($this->sizesAndColours(), $this->filter(['Small'], 'S-GRY'));

        $this->assertSame(['S-GRY'], $matched->pluck('id')->all());
    }

    public function test_pinned_id_that_contradicts_the_values_is_ignored()
    {
        // Eg an id picked on another page that this page uses for a different variant.
        $matched = VariantMatcher::match($this->sizesAndColours(), $this->filter(['Large'], 'S-GRY'));

        $this->assertSame(['L-BLK'], $matched->pluck('id')->all());
    }

    public function test_pinned_id_alone_matches_that_variant()
    {
        $this->assertSame(['S-GRY'], VariantMatcher::match($this->sizesAndColours(), $this->filter([], 'S-GRY'))->pluck('id')->all());
        $this->assertTrue(VariantMatcher::match($this->sizesAndColours(), $this->filter([], 'GONE'))->isEmpty());
    }

    public function test_falls_back_to_values_when_pinned_id_is_gone()
    {
        $matched = VariantMatcher::match($this->sizes(), $this->filter(['Small'], 'OLD-SKU'));

        $this->assertSame(['SKU-S'], $matched->pluck('id')->all());
    }

    public function test_label_segments_are_used_when_a_variant_has_no_options()
    {
        $variants = collect([
            new VariantDto('Trail Shorts - Small / Black', [], StockStatus::InStock),
            new VariantDto('Trail Shorts - X Small / Black', [], StockStatus::InStock),
        ]);

        $matched = VariantMatcher::match($variants, $this->filter(['Small', 'Black']));

        $this->assertSame(['Trail Shorts - Small / Black'], $matched->pluck('label')->all());
    }

    public function test_resolve_any_in_stock_match_is_in_stock_at_lowest_in_stock_price()
    {
        $result = VariantMatcher::resolve($this->sizesAndColours(), $this->filter(['Small']));

        $this->assertSame(StockStatus::InStock, $result['status']);
        $this->assertSame('44', $result['price']);
        $this->assertCount(3, $result['matched']);
    }

    public function test_resolve_unavailable_match_keeps_its_status_and_price()
    {
        $result = VariantMatcher::resolve($this->sizes(), $this->filter(['Small']));

        $this->assertSame(StockStatus::OutOfStock, $result['status']);
        $this->assertSame('30', $result['price']);
    }

    public function test_resolve_uses_the_best_status_when_none_are_in_stock()
    {
        $variants = collect([
            new VariantDto('Red', ['Red'], StockStatus::OutOfStock, '10'),
            new VariantDto('Red', ['Red'], StockStatus::PreOrder, '12'),
        ]);

        $this->assertSame(StockStatus::PreOrder, VariantMatcher::resolve($variants, $this->filter(['Red']))['status']);
    }

    public function test_resolve_reads_price_text_with_the_store_locale()
    {
        $variants = collect([
            new VariantDto('Small / Black', ['Small', 'Black'], StockStatus::InStock, '£20.00'),
            new VariantDto('Small / Red', ['Small', 'Red'], StockStatus::InStock, '£10.00'),
            new VariantDto('Small / Grey', ['Small', 'Grey'], StockStatus::InStock, '£1,050.00'),
        ]);

        $this->assertSame('£10.00', VariantMatcher::resolve($variants, $this->filter(['Small']), 'en_GB', 'GBP')['price']);

        $german = collect([
            new VariantDto('Klein / Schwarz', ['Klein', 'Schwarz'], StockStatus::InStock, '1.050,00 €'),
            new VariantDto('Klein / Rot', ['Klein', 'Rot'], StockStatus::InStock, '999,00 €'),
        ]);

        $this->assertSame('999,00 €', VariantMatcher::resolve($german, $this->filter(['Klein']), 'de_DE', 'EUR')['price']);
    }

    public function test_resolve_compares_numbers_and_text_prices()
    {
        $variants = collect([
            new VariantDto('Small / Black', ['Small', 'Black'], StockStatus::InStock, 45.5),
            new VariantDto('Small / Red', ['Small', 'Red'], StockStatus::InStock, '£44.00'),
        ]);

        $this->assertSame('£44.00', VariantMatcher::resolve($variants, $this->filter(['Small']), 'en_GB', 'GBP')['price']);
    }

    public function test_resolve_without_prices_returns_no_price()
    {
        $variants = collect([
            new VariantDto('Small', ['Small'], StockStatus::InStock),
            new VariantDto('Large', ['Large'], StockStatus::OutOfStock),
        ]);

        $result = VariantMatcher::resolve($variants, $this->filter(['Small']));

        $this->assertSame(StockStatus::InStock, $result['status']);
        $this->assertNull($result['price']);
    }

    public function test_resolve_no_match_is_out_of_stock_without_a_price()
    {
        $result = VariantMatcher::resolve($this->sizes(), $this->filter(['Medium']));

        $this->assertSame(StockStatus::OutOfStock, $result['status']);
        $this->assertNull($result['price']);
        $this->assertTrue($result['matched']->isEmpty());
    }

    public function test_filter_dto_cleans_values_and_drops_empty_filters()
    {
        $this->assertNull(VariantFilterDto::fromArray(null));
        $this->assertNull(VariantFilterDto::fromArray(['values' => ['', '  ']]));
        $this->assertSame(['values' => ['Small', 'Black']], VariantFilterDto::fromArray(['values' => 'Small, Black,Small'])->toArray());
        $this->assertSame(['values' => [], 'id' => 'SKU-1'], VariantFilterDto::fromArray(['id' => ' SKU-1 '])->toArray());
        $this->assertSame('Small / Black', VariantFilterDto::fromArray(['values' => ['Small', 'Black']])->label());
        // Values that only differ in case or separators are one value.
        $this->assertSame(['values' => ['Small', 'X Large']], VariantFilterDto::fromArray(['values' => ['Small', 'small', 'X Large', 'x-large']])->toArray());
    }

    public function test_filter_key_ignores_order_case_and_separators()
    {
        $key = fn (array $values, ?string $id = null): string => VariantFilterDto::fromArray(['values' => $values, 'id' => $id])->key();

        $this->assertSame($key(['Small', 'Black']), $key(['black', 'SMALL']));
        $this->assertSame($key(['Small']), $key(['Small', 'small']));
        $this->assertNotSame($key(['Small']), $key(['Small'], 'SKU-1'));
    }

    public function test_filter_key_keeps_values_apart()
    {
        $key = fn (array $values, ?string $id = null): string => VariantFilterDto::fromArray(['values' => $values, 'id' => $id])->key();

        // An option containing a separator is not the same as two options.
        $this->assertNotSame($key(['Black|Small']), $key(['Black', 'Small']));
        $this->assertNotSame($key(['Small#1']), $key(['Small'], '1'));
    }
}

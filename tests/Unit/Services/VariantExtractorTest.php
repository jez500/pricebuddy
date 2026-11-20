<?php

namespace Tests\Unit\Services;

use App\Dto\AvailabilityStrategyDto;
use App\Dto\VariantDto;
use App\Dto\VariantStrategyDto;
use App\Enums\StockStatus;
use App\Services\VariantExtractor;
use Tests\TestCase;

class VariantExtractorTest extends TestCase
{
    protected function fixture(string $name): string
    {
        return (string) file_get_contents(base_path('tests/Fixtures/Variants/'.$name));
    }

    /**
     * @param  array<int, VariantDto>|null  $variants
     * @return array<string, array{status: string, price: int|float|string|null, id: ?string}>
     */
    protected function summarise(?iterable $variants): array
    {
        $out = [];

        foreach ($variants ?? [] as $variant) {
            $out[$variant->label] = [
                'status' => $variant->status->value,
                'price' => $variant->price,
                'id' => $variant->id,
            ];
        }

        return $out;
    }

    public function test_extracts_variants_from_json_in_the_page_by_selector()
    {
        $variants = VariantExtractor::extract($this->fixture('next-data.html'), VariantStrategyDto::fromArray([
            'type' => 'selector',
            'value' => 'script#__NEXT_DATA__',
            'path' => 'props.pageProps.product.variants',
            'options' => ['options.*.value.*.label'],
            'availability' => 'inventory.isInStock',
            'price' => 'prices.price',
            'id' => 'sku',
        ]));

        $this->assertSame([
            'X Small' => ['status' => 'in_stock', 'price' => 30, 'id' => 'TEST100_X-Small'],
            'Small' => ['status' => 'out_of_stock', 'price' => 30, 'id' => 'TEST100_Small'],
            'Medium' => ['status' => 'out_of_stock', 'price' => 30, 'id' => 'TEST100_Medium'],
            'Large' => ['status' => 'in_stock', 'price' => 32.5, 'id' => 'TEST100_Large'],
        ], $this->summarise($variants));

        $this->assertSame(['Small'], $variants->get(1)->options);
    }

    public function test_extracts_variants_from_json_in_the_page_by_regex()
    {
        $variants = VariantExtractor::extract($this->fixture('next-data.html'), VariantStrategyDto::fromArray([
            'type' => 'regex',
            // A pattern wrapped in < > would be read as delimited, so start after the "<".
            'value' => 'id="__NEXT_DATA__" type="application/json">(.*?)</script>',
            'path' => 'props.pageProps.product.variants',
            'options' => 'options.*.value.*.label',
            'availability' => 'inventory.isInStock',
        ]));

        $this->assertSame(['X Small', 'Small', 'Medium', 'Large'], $variants->pluck('label')->all());
        $this->assertSame(StockStatus::OutOfStock, $variants->get(1)->status);
    }

    public function test_extracts_variants_from_an_html_encoded_json_attribute()
    {
        $variants = VariantExtractor::extract($this->fixture('woocommerce.html'), VariantStrategyDto::fromArray([
            'type' => 'selector',
            'value' => 'form.variations_form|data-product_variations',
            'options' => ['attributes.*'],
            'availability' => 'is_in_stock',
            'price' => 'display_price',
            'id' => 'sku',
        ]));

        $this->assertSame([
            'small / black' => ['status' => 'in_stock', 'price' => 45, 'id' => 'HOOD-S-BLK'],
            'small / grey' => ['status' => 'out_of_stock', 'price' => 45, 'id' => 'HOOD-S-GRY'],
            'x-large / black' => ['status' => 'in_stock', 'price' => 49, 'id' => 'HOOD-XL-BLK'],
        ], $this->summarise($variants));
    }

    public function test_extracts_variants_from_a_json_response_and_divides_prices()
    {
        $json = json_encode(['product' => ['variants' => [
            ['title' => 'Red', 'available' => true, 'price' => 1999, 'id' => 11],
            ['title' => 'Blue', 'available' => false, 'price' => 2499, 'id' => 12],
        ]]]);

        $variants = VariantExtractor::extract($json, VariantStrategyDto::fromArray([
            'type' => 'json',
            'path' => 'product.variants',
            'options' => ['title'],
            'availability' => 'available',
            'price' => 'price',
            'id' => 'id',
            'price_divisor' => 100,
        ]));

        $this->assertSame([
            'Red' => ['status' => 'in_stock', 'price' => 19.99, 'id' => '11'],
            'Blue' => ['status' => 'out_of_stock', 'price' => 24.99, 'id' => '12'],
        ], $this->summarise($variants));
    }

    public function test_extracts_variants_from_a_schema_org_product_group_without_config()
    {
        $variants = VariantExtractor::extract($this->fixture('schema-product-group.html'));

        $this->assertSame([
            '55"' => ['status' => 'in_stock', 'price' => 499.0, 'id' => 'TV4K-55'],
            '65"' => ['status' => 'out_of_stock', 'price' => 799.0, 'id' => 'TV4K-65'],
        ], $this->summarise($variants));
    }

    public function test_extracts_variants_from_a_schema_org_offers_list_without_config()
    {
        $variants = VariantExtractor::extract($this->fixture('schema-offers-array.html'));

        $this->assertSame([
            'Red' => ['status' => 'in_stock', 'price' => 39.99, 'id' => 'KET-RED'],
            'Brushed Steel' => ['status' => 'out_of_stock', 'price' => 44.99, 'id' => 'KET-STEEL'],
            'Black' => ['status' => 'pre_order', 'price' => 39.99, 'id' => 'KET-BLACK'],
        ], $this->summarise($variants));
    }

    public function test_extracts_variants_from_page_elements()
    {
        $variants = VariantExtractor::extract($this->fixture('dom-buttons.html'), VariantStrategyDto::fromArray([
            'type' => 'dom',
            'value' => '.size-selector button.size',
            'options' => ['|data-size'],
            'out_of_stock' => '[disabled], .is-sold-out',
            'id' => '|data-sku',
        ]));

        $this->assertSame([
            'UK 8' => ['status' => 'in_stock', 'price' => null, 'id' => 'SHOE-8'],
            'UK 9' => ['status' => 'out_of_stock', 'price' => null, 'id' => 'SHOE-9'],
            'UK 10' => ['status' => 'out_of_stock', 'price' => null, 'id' => 'SHOE-10'],
            'UK 11' => ['status' => 'in_stock', 'price' => null, 'id' => 'SHOE-11'],
        ], $this->summarise($variants));
    }

    public function test_dom_variants_use_element_text_and_attribute_flags()
    {
        $variants = VariantExtractor::extract($this->fixture('dom-buttons.html'), VariantStrategyDto::fromArray([
            'type' => 'dom',
            'value' => '.size-selector button.size',
            'out_of_stock' => '|disabled',
        ]));

        $this->assertSame('UK 8 Only 2 left', $variants->get(0)->label);
        $this->assertSame(StockStatus::OutOfStock, $variants->get(1)->status);
        // Only the disabled attribute counts here, not the sold out class.
        $this->assertSame(StockStatus::InStock, $variants->get(2)->status);
    }

    public function test_returns_null_when_no_variant_data_is_found()
    {
        // The page level schema.org offer is not a list of variants.
        $this->assertNull(VariantExtractor::extract($this->fixture('next-data.html')));

        // A selector that matches nothing.
        $this->assertNull(VariantExtractor::extract($this->fixture('next-data.html'), VariantStrategyDto::fromArray([
            'type' => 'selector',
            'value' => 'script#missing',
            'path' => 'variants',
        ])));

        // A path that matches nothing.
        $this->assertNull(VariantExtractor::extract($this->fixture('next-data.html'), VariantStrategyDto::fromArray([
            'type' => 'selector',
            'value' => 'script#__NEXT_DATA__',
            'path' => 'props.pageProps.missing',
        ])));

        // No page elements.
        $this->assertNull(VariantExtractor::extract($this->fixture('next-data.html'), VariantStrategyDto::fromArray([
            'type' => 'dom',
            'value' => '.size-selector button',
        ])));

        $this->assertNull(VariantExtractor::extract(''));
    }

    public function test_returns_null_for_invalid_json()
    {
        $this->assertNull(VariantExtractor::extract('<script id="data">{"variants": [</script>', VariantStrategyDto::fromArray([
            'type' => 'selector',
            'value' => 'script#data',
            'path' => 'variants',
        ])));

        $this->assertNull(VariantExtractor::extract('not json', VariantStrategyDto::fromArray([
            'type' => 'json',
            'path' => 'variants',
        ])));
    }

    public function test_status_from_value_reads_common_formats()
    {
        $this->assertSame(StockStatus::InStock, VariantExtractor::statusFromValue(true));
        $this->assertSame(StockStatus::OutOfStock, VariantExtractor::statusFromValue(false));
        $this->assertSame(StockStatus::InStock, VariantExtractor::statusFromValue(3));
        $this->assertSame(StockStatus::OutOfStock, VariantExtractor::statusFromValue(0));
        $this->assertSame(StockStatus::OutOfStock, VariantExtractor::statusFromValue('0'));
        $this->assertSame(StockStatus::InStock, VariantExtractor::statusFromValue('In stock'));
        $this->assertSame(StockStatus::OutOfStock, VariantExtractor::statusFromValue('Sold out'));
        $this->assertSame(StockStatus::OutOfStock, VariantExtractor::statusFromValue('https://schema.org/OutOfStock'));
        $this->assertSame(StockStatus::PreOrder, VariantExtractor::statusFromValue('PreOrder'));
        $this->assertSame(StockStatus::InStock, VariantExtractor::statusFromValue(null));
    }

    public function test_status_from_value_uses_the_store_availability_match_rules()
    {
        $availability = AvailabilityStrategyDto::fromArray([
            'type' => 'selector',
            'value' => '.stock',
            'match' => [
                'back_order' => ['type' => 'match', 'value' => 'Ships in 3 weeks'],
                'default' => 'in_stock',
            ],
        ]);

        $this->assertSame(StockStatus::BackOrder, VariantExtractor::statusFromValue('Ships in 3 weeks', $availability));
        $this->assertSame(StockStatus::InStock, VariantExtractor::statusFromValue('Ready to ship', $availability));
    }

    public function test_status_from_value_ignores_case_and_separators_in_common_words()
    {
        $this->assertSame(StockStatus::InStock, VariantExtractor::statusFromValue('in-stock'));
        $this->assertSame(StockStatus::OutOfStock, VariantExtractor::statusFromValue('Not available'));
        $this->assertSame(StockStatus::OutOfStock, VariantExtractor::statusFromValue('SOLD_OUT'));
    }

    /**
     * @param  array<int, array<string, mixed>>  $offers
     */
    protected function schemaOffersPage(array $offers): string
    {
        $json = json_encode(['@context' => 'https://schema.org', '@type' => 'Product', 'name' => 'Example Kettle', 'offers' => $offers]);

        return '<html><head><script type="application/ld+json">'.$json.'</script></head><body></body></html>';
    }

    public function test_a_single_schema_org_offer_counts_when_it_names_variant_properties()
    {
        // A list that has shrunk to one variant, eg the sold out sizes were removed.
        $variants = VariantExtractor::extract($this->schemaOffersPage([
            ['@type' => 'Offer', 'price' => '39.99', 'availability' => 'https://schema.org/InStock', 'itemOffered' => ['@type' => 'Product', 'size' => 'Small']],
        ]));

        $this->assertSame(['Small' => ['status' => 'in_stock', 'price' => 39.99, 'id' => null]], $this->summarise($variants));
    }

    public function test_a_single_schema_org_offer_with_only_a_name_is_not_a_variant_list()
    {
        // Usually the offer for the whole product.
        $this->assertNull(VariantExtractor::extract($this->schemaOffersPage([
            ['@type' => 'Offer', 'name' => 'Example Kettle', 'price' => '39.99', 'availability' => 'https://schema.org/InStock'],
        ])));
    }

    public function test_page_element_prices_stay_text_unless_a_divisor_is_set()
    {
        $html = '<div class="sizes">'
            .'<button class="size" data-price="3899">Small <span class="price">£38.99</span></button>'
            .'<button class="size" data-price="4099">Large <span class="price">£40.99</span></button>'
            .'</div>';

        $asText = VariantExtractor::extract($html, VariantStrategyDto::fromArray([
            'type' => 'dom',
            'value' => 'button.size',
            'price' => '.price',
        ]));

        $inCents = VariantExtractor::extract($html, VariantStrategyDto::fromArray([
            'type' => 'dom',
            'value' => 'button.size',
            'price' => '|data-price',
            'price_divisor' => 100,
        ]));

        $this->assertSame(['£38.99', '£40.99'], $asText->pluck('price')->all());
        $this->assertSame([38.99, 40.99], $inCents->pluck('price')->all());
    }

    public function test_numeric_json_text_prices_are_read_as_numbers()
    {
        $variants = VariantExtractor::extract(json_encode(['variants' => [['size' => 'S', 'price' => '32.5']]]), VariantStrategyDto::fromArray([
            'type' => 'json',
            'path' => 'variants',
            'options' => ['size'],
            'price' => 'price',
        ]));

        $this->assertSame(32.5, $variants->first()->price);
    }
}

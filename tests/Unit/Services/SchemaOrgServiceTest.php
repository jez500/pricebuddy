<?php

namespace Tests\Unit\Services;

use App\Services\SchemaOrgService;
use Tests\TestCase;

class SchemaOrgServiceTest extends TestCase
{
    public function test_parse_schema_org_extracts_correct_data()
    {
        $jsonLd = [
            [
                '@type' => 'Product',
                'name' => 'Test Product',
                'description' => 'Test Description',
                'image' => 'https://example.com/image.jpg',
                'offers' => [
                    'price' => '19.99',
                    'priceCurrency' => 'USD',
                ],
            ],
        ];

        $collection = collect($jsonLd);

        $this->assertEquals('Test Product', SchemaOrgService::parseSchemaOrg($collection, 'title'));
        $this->assertEquals('Test Description', SchemaOrgService::parseSchemaOrg($collection, 'description'));
        $this->assertEquals('19.99', SchemaOrgService::parseSchemaOrg($collection, 'price'));
        $this->assertEquals('USD', SchemaOrgService::parseSchemaOrg($collection, 'price_currency'));
        $this->assertEquals('https://example.com/image.jpg', SchemaOrgService::parseSchemaOrg($collection, 'image'));
    }

    public function test_parse_schema_org_handles_different_price_formats()
    {
        // lowPrice
        $jsonLd = [
            [
                '@type' => 'Product',
                'offers' => [
                    'lowPrice' => '15.00',
                ],
            ],
        ];
        $this->assertEquals('15.00', SchemaOrgService::parseSchemaOrg(collect($jsonLd), 'price'));

        // priceSpecification
        $jsonLd = [
            [
                '@type' => 'Product',
                'offers' => [
                    'priceSpecification' => [
                        'price' => '25.00',
                    ],
                ],
            ],
        ];
        $this->assertEquals('25.00', SchemaOrgService::parseSchemaOrg(collect($jsonLd), 'price'));
    }

    public function test_parse_schema_org_handles_image_array()
    {
        $jsonLd = [
            [
                '@type' => 'Product',
                'image' => [
                    'https://example.com/image1.jpg',
                    'https://example.com/image2.jpg',
                ],
            ],
        ];
        $this->assertEquals('https://example.com/image1.jpg', SchemaOrgService::parseSchemaOrg(collect($jsonLd), 'image'));
    }

    public function test_parse_schema_org_returns_null_if_no_product_found()
    {
        $jsonLd = [
            [
                '@type' => 'NewsArticle',
                'name' => 'Not a product',
            ],
        ];
        $this->assertNull(SchemaOrgService::parseSchemaOrg(collect($jsonLd), 'title'));
    }

    public function test_parse_schema_org_matches_product_type_case_insensitively()
    {
        // Some sites (e.g. danmurphys.com.au) use a lowercase "product" @type.
        $jsonLd = [
            [
                '@context' => 'https://www.schema.org',
                '@type' => 'product',
                'name' => 'Lowercase Widget',
                'image' => 'https://example.com/w.png',
                'offers' => [
                    '@type' => 'Offer',
                    'price' => '48.95',
                    'priceCurrency' => 'AUD',
                    'availability' => 'http://schema.org/InStock',
                ],
            ],
        ];

        $this->assertEquals('Lowercase Widget', SchemaOrgService::parseSchemaOrg(collect($jsonLd), 'title'));
        $this->assertEquals('48.95', SchemaOrgService::parseSchemaOrg(collect($jsonLd), 'price'));
        $this->assertEquals('https://example.com/w.png', SchemaOrgService::parseSchemaOrg(collect($jsonLd), 'image'));
        $this->assertEquals('http://schema.org/InStock', SchemaOrgService::parseSchemaOrg(collect($jsonLd), 'availability'));
    }

    public function test_parse_schema_org_matches_array_type_containing_product()
    {
        $jsonLd = [
            [
                '@type' => ['Product', 'Thing'],
                'name' => 'Array Type Widget',
                'offers' => ['@type' => 'Offer', 'price' => '5.00'],
            ],
        ];

        $this->assertEquals('Array Type Widget', SchemaOrgService::parseSchemaOrg(collect($jsonLd), 'title'));
    }

    private function microdataHtml(): string
    {
        return <<<'HTML'
<div itemscope itemtype="https://schema.org/Product">
  <h1 itemprop="name">Premium Leather Boots</h1>
  <img itemprop="image" src="https://example.com/boots.jpg" alt="Boots" />
  <p itemprop="description">Durable and stylish waterproof boots.</p>
  <div itemprop="offers" itemscope itemtype="https://schema.org/Offer">
    <span itemprop="priceCurrency" content="USD">$</span>
    <span itemprop="price" content="149.99">149.99</span>
    <link itemprop="availability" href="https://schema.org/InStock" />In Stock
  </div>
</div>
HTML;
    }

    public function test_parse_microdata_extracts_all_fields(): void
    {
        $html = $this->microdataHtml();

        $this->assertSame('Premium Leather Boots', SchemaOrgService::parseMicrodata($html, 'title'));
        $this->assertSame('Durable and stylish waterproof boots.', SchemaOrgService::parseMicrodata($html, 'description'));
        $this->assertSame('149.99', SchemaOrgService::parseMicrodata($html, 'price'));
        // content="USD" wins over the visible "$" text.
        $this->assertSame('USD', SchemaOrgService::parseMicrodata($html, 'price_currency'));
        // <img> -> src.
        $this->assertSame('https://example.com/boots.jpg', SchemaOrgService::parseMicrodata($html, 'image'));
        // <link> -> href (a full schema.org URL StockStatus can map).
        $this->assertSame('https://schema.org/InStock', SchemaOrgService::parseMicrodata($html, 'availability'));
    }

    public function test_parse_microdata_returns_null_without_product_itemscope(): void
    {
        $html = '<div itemscope itemtype="https://schema.org/Article"><span itemprop="name">Not a product</span></div>';

        $this->assertNull(SchemaOrgService::parseMicrodata($html, 'title'));
    }

    public function test_parse_microdata_returns_null_when_field_missing(): void
    {
        $html = '<div itemscope itemtype="https://schema.org/Product"><span itemprop="name">Widget</span></div>';

        $this->assertSame('Widget', SchemaOrgService::parseMicrodata($html, 'title'));
        $this->assertNull(SchemaOrgService::parseMicrodata($html, 'price'));
    }

    public function test_parse_microdata_returns_null_for_blank_html_or_unknown_field(): void
    {
        $this->assertNull(SchemaOrgService::parseMicrodata(null, 'title'));
        $this->assertNull(SchemaOrgService::parseMicrodata('', 'title'));
        $this->assertNull(SchemaOrgService::parseMicrodata(
            '<div itemscope itemtype="https://schema.org/Product"><span itemprop="name">X</span></div>',
            'unknown_field',
        ));
    }

    public function test_parse_microdata_does_not_throw_on_malformed_html(): void
    {
        $html = '<div itemscope itemtype="https://schema.org/Product"><span itemprop="name">Broken';

        // Must not throw; the libxml parser usually recovers the value, but null is also acceptable.
        $value = SchemaOrgService::parseMicrodata($html, 'title');

        $this->assertTrue($value === null || $value === 'Broken');
    }

    public function test_parse_schema_org_handles_availability_formats()
    {
        // Simple string availability
        $jsonLd = [
            [
                '@type' => 'Product',
                'offers' => [
                    'availability' => 'https://schema.org/InStock',
                ],
            ],
        ];
        $this->assertEquals('https://schema.org/InStock', SchemaOrgService::parseSchemaOrg(collect($jsonLd), 'availability'));

        // Array availability
        $jsonLd = [
            [
                '@type' => 'Product',
                'offers' => [
                    '0' => [
                        'availability' => 'https://schema.org/OutOfStock',
                    ],
                ],
            ],
        ];
        $this->assertEquals('https://schema.org/OutOfStock', SchemaOrgService::parseSchemaOrg(collect($jsonLd), 'availability'));

        // Array of strings availability
        $jsonLd = [
            [
                '@type' => 'Product',
                'offers' => [
                    'availability' => ['https://schema.org/InStock', 'something else'],
                ],
            ],
        ];
        $this->assertEquals('https://schema.org/InStock', SchemaOrgService::parseSchemaOrg(collect($jsonLd), 'availability'));

        // Non-string availability (e.g., an object/array that is not normalized)
        $jsonLd = [
            [
                '@type' => 'Product',
                'offers' => [
                    'availability' => ['type' => 'ItemAvailability', 'value' => 'InStock'],
                ],
            ],
        ];
        // Currently it would return the array if we just use data_get directly.
        // We want it to return null if it's not a string or first element is not a string.
        $this->assertNull(SchemaOrgService::parseSchemaOrg(collect($jsonLd), 'availability'));
    }
}

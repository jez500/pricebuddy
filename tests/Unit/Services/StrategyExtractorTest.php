<?php

namespace Tests\Unit\Services;

use App\Dto\StandardStrategyDto;
use App\Enums\ScraperStrategyType;
use App\Services\StrategyExtractor;
use Jez500\WebScraperForLaravel\WebScraperFake;
use PHPUnit\Framework\TestCase;

class StrategyExtractorTest extends TestCase
{
    private function scraper(string $html): WebScraperFake
    {
        return (new WebScraperFake)->setBody($html);
    }

    public function test_extracts_via_css_selector_text(): void
    {
        $scraper = $this->scraper('<html><body><span id="p">$12.99</span></body></html>');

        $value = StrategyExtractor::extract($scraper, StandardStrategyDto::fromArray(['type' => 'selector', 'value' => '#p']), 'price');

        $this->assertSame('$12.99', $value);
    }

    public function test_extracts_attribute_via_pipe_syntax(): void
    {
        $scraper = $this->scraper('<html><head><meta property="og:title" content="Widget"></head></html>');

        $value = StrategyExtractor::extract($scraper, StandardStrategyDto::fromArray(['type' => 'selector', 'value' => 'meta[property=og:title]|content']), 'title');

        $this->assertSame('Widget', $value);
    }

    public function test_extracts_via_regex(): void
    {
        $scraper = $this->scraper('<html><body><script>{"price": 42.50}</script></body></html>');

        $value = StrategyExtractor::extract($scraper, StandardStrategyDto::fromArray(['type' => 'regex', 'value' => '"price":\s*([0-9.]+)']), 'price');

        $this->assertSame('42.50', $value);
    }

    public function test_applies_prepend_and_append(): void
    {
        $scraper = $this->scraper('<html><body><span id="p">10</span></body></html>');

        $value = StrategyExtractor::extract($scraper, StandardStrategyDto::fromArray(['type' => 'selector', 'value' => '#p', 'prepend' => '$', 'append' => '0']), 'price');

        $this->assertSame('$100', $value);
    }

    public function test_returns_null_when_value_missing_for_non_schema_org(): void
    {
        $scraper = $this->scraper('<html></html>');

        $value = StrategyExtractor::extract($scraper, new StandardStrategyDto(ScraperStrategyType::Selector, null), 'price');

        $this->assertNull($value);
    }

    public function test_returns_null_when_selector_matches_nothing_even_with_prepend(): void
    {
        $scraper = $this->scraper('<html><body></body></html>');

        $value = StrategyExtractor::extract($scraper, StandardStrategyDto::fromArray(['type' => 'selector', 'value' => '#missing', 'prepend' => 'https://example.com', 'append' => '/x']), 'url');

        $this->assertNull($value);
    }

    public function test_applies_prepend_append_only_when_value_present(): void
    {
        $scraper = $this->scraper('<html><body><span id="p">42</span></body></html>');

        $value = StrategyExtractor::extract($scraper, StandardStrategyDto::fromArray(['type' => 'selector', 'value' => '#p', 'prepend' => '$', 'append' => '.00']), 'price');

        $this->assertSame('$42.00', $value);
    }

    public function test_json_ld_takes_precedence_over_microdata_for_the_same_field(): void
    {
        $html = '<html><head>'
            .'<script type="application/ld+json">{"@type":"Product","name":"JSON-LD Name"}</script>'
            .'</head><body><div itemscope itemtype="https://schema.org/Product">'
            .'<h1 itemprop="name">Microdata Name</h1>'
            .'</div></body></html>';
        $scraper = $this->scraper($html);

        $title = StrategyExtractor::extract($scraper, StandardStrategyDto::fromArray(['type' => 'schema_org', 'value' => null]), 'title');

        $this->assertSame('JSON-LD Name', $title);
    }

    public function test_schema_org_strategy_falls_back_to_microdata(): void
    {
        $html = '<html><body><div itemscope itemtype="https://schema.org/Product">'
            .'<h1 itemprop="name">Microdata Widget</h1>'
            .'<div itemprop="offers" itemscope itemtype="https://schema.org/Offer">'
            .'<span itemprop="price" content="29.95">29.95</span>'
            .'</div></div></body></html>';
        $scraper = $this->scraper($html);

        $title = StrategyExtractor::extract($scraper, StandardStrategyDto::fromArray(['type' => 'schema_org', 'value' => null]), 'title');
        $price = StrategyExtractor::extract($scraper, StandardStrategyDto::fromArray(['type' => 'schema_org', 'value' => null]), 'price');

        $this->assertSame('Microdata Widget', $title);
        $this->assertSame('29.95', $price);
    }
}

<?php

namespace Tests\Unit\Services;

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

        $value = StrategyExtractor::extract($scraper, ['type' => 'selector', 'value' => '#p'], 'price');

        $this->assertSame('$12.99', $value);
    }

    public function test_extracts_attribute_via_pipe_syntax(): void
    {
        $scraper = $this->scraper('<html><head><meta property="og:title" content="Widget"></head></html>');

        $value = StrategyExtractor::extract($scraper, ['type' => 'selector', 'value' => 'meta[property=og:title]|content'], 'title');

        $this->assertSame('Widget', $value);
    }

    public function test_extracts_via_regex(): void
    {
        $scraper = $this->scraper('<html><body><script>{"price": 42.50}</script></body></html>');

        $value = StrategyExtractor::extract($scraper, ['type' => 'regex', 'value' => '"price":\s*([0-9.]+)'], 'price');

        $this->assertSame('42.50', $value);
    }

    public function test_applies_prepend_and_append(): void
    {
        $scraper = $this->scraper('<html><body><span id="p">10</span></body></html>');

        $value = StrategyExtractor::extract($scraper, ['type' => 'selector', 'value' => '#p', 'prepend' => '$', 'append' => '0'], 'price');

        $this->assertSame('$100', $value);
    }

    public function test_returns_null_for_blank_type(): void
    {
        $scraper = $this->scraper('<html></html>');

        $this->assertNull(StrategyExtractor::extract($scraper, ['type' => '', 'value' => 'x'], 'price'));
    }
}

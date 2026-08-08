<?php

namespace Tests\Unit\Services;

use App\Services\ScrapeUrl;
use PHPUnit\Framework\TestCase;

class ScrapeUrlCleanTitleTest extends TestCase
{
    public function test_strips_a_trailing_domain_segment(): void
    {
        $this->assertSame(
            'DAREU A950GM Wireless Gaming Mouse (Black): Mice',
            ScrapeUrl::preSaveCleanTitle('DAREU A950GM Wireless Gaming Mouse (Black): Mice: Amazon.com.au')
        );
    }

    public function test_strips_a_trailing_domain_segment_after_a_pipe(): void
    {
        $this->assertSame(
            'Logitech MX Master 3S',
            ScrapeUrl::preSaveCleanTitle('Logitech MX Master 3S | Kogan.com')
        );
    }

    public function test_strips_a_trailing_segment_matching_the_store_name(): void
    {
        $this->assertSame(
            'Sony WH-1000XM5 Wireless Headphones',
            ScrapeUrl::preSaveCleanTitle('Sony WH-1000XM5 Wireless Headphones | JB Hi-Fi', 'JB Hi-Fi')
        );
    }

    public function test_strips_repeated_trailing_noise_segments(): void
    {
        $this->assertSame(
            'Anker 737 Power Bank',
            ScrapeUrl::preSaveCleanTitle('Anker 737 Power Bank | Amazon AU | amazon.com.au', 'Amazon AU')
        );
    }

    public function test_keeps_a_trailing_segment_that_is_product_information(): void
    {
        $this->assertSame(
            'Dyson V15 Detect | 2024 Model',
            ScrapeUrl::preSaveCleanTitle('Dyson V15 Detect | 2024 Model')
        );
    }

    public function test_does_not_strip_hyphenated_words(): void
    {
        $this->assertSame(
            'DAREU A950GM 60g ultra-lightweight ergonomic design',
            ScrapeUrl::preSaveCleanTitle('DAREU A950GM 60g ultra-lightweight ergonomic design')
        );
    }

    public function test_strips_a_trailing_segment_after_a_spaced_dash(): void
    {
        $this->assertSame(
            'Samsung 990 Pro 2TB SSD',
            ScrapeUrl::preSaveCleanTitle('Samsung 990 Pro 2TB SSD - Scorptec.com.au')
        );
    }

    public function test_leaves_a_title_that_is_only_a_domain_alone(): void
    {
        $this->assertSame('Amazon.com.au', ScrapeUrl::preSaveCleanTitle('Amazon.com.au'));
    }

    public function test_collapses_whitespace_and_trims(): void
    {
        $this->assertSame('Razer Basilisk V3', ScrapeUrl::preSaveCleanTitle("  Razer \n Basilisk V3  | Razer.com"));
    }

    public function test_leaves_null_and_empty_values_alone(): void
    {
        $this->assertNull(ScrapeUrl::preSaveCleanTitle(null));
        $this->assertSame('', ScrapeUrl::preSaveCleanTitle(''));
    }
}

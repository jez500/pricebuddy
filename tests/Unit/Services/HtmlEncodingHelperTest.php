<?php

namespace Tests\Unit\Services;

use App\Services\Helpers\HtmlEncodingHelper;
use App\Services\Scrapers\Utf8WebScraperHttp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Jez500\WebScraperForLaravel\Facades\WebScraper;
use Tests\TestCase;

class HtmlEncodingHelperTest extends TestCase
{
    use RefreshDatabase;

    protected function latin1Page(string $head = ''): string
    {
        return mb_convert_encoding(
            "<html><head>{$head}<title>Gioco più bello</title></head><body><h1>Città €12,50</h1></body></html>",
            'Windows-1252',
            'UTF-8'
        );
    }

    public function test_converts_using_the_content_type_charset(): void
    {
        $html = HtmlEncodingHelper::toUtf8($this->latin1Page(), 'text/html; Charset=iso-8859-1');

        $this->assertTrue(mb_check_encoding($html, 'UTF-8'));
        $this->assertStringContainsString('Gioco più bello', $html);
        $this->assertStringContainsString('Città €12,50', $html);
    }

    public function test_converts_using_the_meta_charset_and_rewrites_it(): void
    {
        $html = HtmlEncodingHelper::toUtf8(
            $this->latin1Page('<meta http-equiv="Content-Type" content="text/html; charset=ISO-8859-1">'),
            'text/html'
        );

        $this->assertStringContainsString('Gioco più bello', $html);
        $this->assertStringContainsString('charset=utf-8"', $html);
        $this->assertStringNotContainsString('ISO-8859-1', $html);
    }

    public function test_undeclared_invalid_utf8_is_read_as_windows_1252(): void
    {
        $html = HtmlEncodingHelper::toUtf8($this->latin1Page());

        $this->assertStringContainsString('Gioco più bello', $html);
    }

    public function test_valid_utf8_is_unchanged(): void
    {
        $page = '<html><head><meta charset="utf-8"></head><body>più</body></html>';

        $this->assertSame($page, HtmlEncodingHelper::toUtf8($page, 'text/html; charset=UTF-8'));
        $this->assertSame($page, HtmlEncodingHelper::toUtf8($page));
    }

    public function test_declared_utf8_with_invalid_bytes_is_scrubbed(): void
    {
        $html = HtmlEncodingHelper::toUtf8("più \xF9 bello", 'text/html; charset=utf-8');

        $this->assertTrue(mb_check_encoding($html, 'UTF-8'));
        $this->assertStringStartsWith('più ', $html);
    }

    public function test_unknown_charset_is_scrubbed(): void
    {
        $html = HtmlEncodingHelper::toUtf8("abc \xF9", 'text/html; charset=x-not-a-charset');

        $this->assertTrue(mb_check_encoding($html, 'UTF-8'));
    }

    /**
     * The database cache store rejected the raw ISO-8859-1 bytes (#203).
     */
    public function test_http_scraper_caches_a_latin1_page_in_the_database(): void
    {
        config(['cache.default' => 'database']);

        Http::fake([
            'latin1.test/*' => Http::response($this->latin1Page(), 200, ['Content-Type' => 'text/html; Charset=iso-8859-1']),
        ]);

        $scraper = WebScraper::make('http')->from('https://latin1.test/product')->get();

        $this->assertInstanceOf(Utf8WebScraperHttp::class, $scraper);
        $this->assertStringContainsString('Gioco più bello', $scraper->getBody());
        $this->assertSame('Gioco più bello', $scraper->getSelector('title')->first());
        $this->assertDatabaseCount('cache', 1);
    }
}

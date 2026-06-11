<?php

namespace Tests\Feature\Services;

use App\Models\ProductSource;
use App\Services\ProductSourceSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Jez500\WebScraperForLaravel\Facades\WebScraper;
use Jez500\WebScraperForLaravel\WebScraperFake;
use Tests\TestCase;

class ProductSourceSearchServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Fake the search-result page scrape and the per-item scrapers.
     *
     * make() returns a fake whose body is the full results page; http() returns
     * a fresh fake each call so each item's HTML can be re-parsed offline.
     *
     * AbstractWebScraper::from() resolves a new instance via the container, so we
     * also bind WebScraperFake in the container so that resolved instance carries
     * the same body (the body is set after from() by the service's own setBody call
     * on the per-item scraper; for the list scraper the body must survive from()).
     */
    private function fakeSearchPage(string $html): void
    {
        $fake = (new WebScraperFake)->setBody($html);
        app()->bind(WebScraperFake::class, fn () => (new WebScraperFake)->setBody($html));
        WebScraper::shouldReceive('make')->andReturn($fake);
        WebScraper::shouldReceive('http')->andReturnUsing(fn () => new WebScraperFake);
    }

    public function test_product_url_honors_prepend_for_relative_urls()
    {
        $this->fakeSearchPage(
            '<div class="product-item">'
            .'<h2 class="title">Laptop</h2>'
            .'<a class="product-link" href="/de/product/96343-some-product">link</a>'
            .'</div>'
        );

        $source = ProductSource::factory()->make([
            'extraction_strategy' => [
                'list_container' => ['type' => 'selector', 'value' => '!.product-item'],
                'product_title' => ['type' => 'selector', 'value' => 'h2.title'],
                'product_url' => [
                    'type' => 'selector',
                    'value' => 'a.product-link|href',
                    'prepend' => 'https://example.com',
                ],
            ],
        ]);

        $results = ProductSourceSearchService::new($source)->search('laptop');

        $this->assertCount(1, $results);
        $this->assertSame('https://example.com/de/product/96343-some-product', $results->first()['url']);
    }

    public function test_replaces_search_term_placeholder_in_url()
    {
        $source = ProductSource::factory()->make([
            'search_url' => 'https://example.com/search?q=:search_term',
        ]);

        $service = ProductSourceSearchService::new($source);
        $url = $service->buildSearchUrl('laptop');

        $this->assertSame('https://example.com/search?q=laptop', $url);
    }

    public function test_url_encodes_the_search_term()
    {
        $source = ProductSource::factory()->make([
            'search_url' => 'https://example.com/search?q=:search_term',
        ]);

        $service = ProductSourceSearchService::new($source);
        $url = $service->buildSearchUrl('gaming laptop');

        $this->assertSame('https://example.com/search?q=gaming+laptop', $url);
    }
}

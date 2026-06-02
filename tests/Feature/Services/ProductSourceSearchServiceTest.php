<?php

namespace Tests\Feature\Services;

use App\Models\ProductSource;
use App\Services\ProductSourceSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductSourceSearchServiceTest extends TestCase
{
    use RefreshDatabase;

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

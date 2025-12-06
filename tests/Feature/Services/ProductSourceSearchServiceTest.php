<?php

use App\Models\ProductSource;
use App\Services\ProductSourceSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(RefreshDatabase::class, TestCase::class);

it('replaces search_term placeholder in url', function () {
    $source = ProductSource::factory()->make([
        'search_url' => 'https://example.com/search?q=:search_term',
    ]);

    $service = ProductSourceSearchService::new($source);
    $url = $service->buildSearchUrl('laptop');

    expect($url)->toBe('https://example.com/search?q=laptop');
});

it('url encodes the search term', function () {
    $source = ProductSource::factory()->make([
        'search_url' => 'https://example.com/search?q=:search_term',
    ]);

    $service = ProductSourceSearchService::new($source);
    $url = $service->buildSearchUrl('gaming laptop');

    expect($url)->toBe('https://example.com/search?q=gaming+laptop');
});

<?php

use App\Models\ProductSource;
use App\Services\ProductSourceSearchService;

it('replaces search_term placeholder in url', function () {
    $source = ProductSource::factory()->make([
        'search_url' => 'https://example.com/search?q=:search_term',
    ]);

    $service = new ProductSourceSearchService;
    $url = $service->buildSearchUrl($source, 'laptop');

    expect($url)->toBe('https://example.com/search?q=laptop');
});

it('url encodes the search term', function () {
    $source = ProductSource::factory()->make([
        'search_url' => 'https://example.com/search?q=:search_term',
    ]);

    $service = new ProductSourceSearchService;
    $url = $service->buildSearchUrl($source, 'gaming laptop');

    expect($url)->toBe('https://example.com/search?q=gaming+laptop');
});

it('extracts products using css selector strategy', function () {
    $html = <<<'HTML'
    <div class="results">
        <div class="product-item">
            <h2 class="title">Product 1</h2>
            <a class="product-link" href="/product1">Link 1</a>
        </div>
        <div class="product-item">
            <h2 class="title">Product 2</h2>
            <a class="product-link" href="/product2">Link 2</a>
        </div>
    </div>
    HTML;

    $strategy = [
        'list_container' => [
            'type' => 'selector',
            'value' => '.product-item',
        ],
        'product_title' => [
            'type' => 'selector',
            'value' => 'h2.title',
        ],
        'product_url' => [
            'type' => 'selector',
            'value' => 'a.product-link|href',
        ],
    ];

    $service = new ProductSourceSearchService;
    $results = $service->extractProducts($html, $strategy);

    expect($results)->toHaveCount(2);
    expect($results->first()->title)->toBe('Product 1');
    expect($results->first()->url)->toBe('/product1');
    expect($results->last()->title)->toBe('Product 2');
    expect($results->last()->url)->toBe('/product2');
});

it('handles empty search results', function () {
    $html = '<div class="results"></div>';

    $strategy = [
        'list_container' => [
            'type' => 'selector',
            'value' => '.product-item',
        ],
        'product_title' => [
            'type' => 'selector',
            'value' => 'h2.title',
        ],
        'product_url' => [
            'type' => 'selector',
            'value' => 'a.product-link|href',
        ],
    ];

    $service = new ProductSourceSearchService;
    $results = $service->extractProducts($html, $strategy);

    expect($results)->toBeEmpty();
});

it('extracts price when provided in strategy', function () {
    $html = <<<'HTML'
    <div class="results">
        <div class="product-item">
            <h2 class="title">Product 1</h2>
            <a class="product-link" href="/product1">Link 1</a>
            <span class="price">$99.99</span>
        </div>
    </div>
    HTML;

    $strategy = [
        'list_container' => [
            'type' => 'selector',
            'value' => '.product-item',
        ],
        'product_title' => [
            'type' => 'selector',
            'value' => 'h2.title',
        ],
        'product_url' => [
            'type' => 'selector',
            'value' => 'a.product-link|href',
        ],
        'product_price' => [
            'type' => 'selector',
            'value' => '.price',
        ],
    ];

    $service = new ProductSourceSearchService;
    $results = $service->extractProducts($html, $strategy);

    expect($results->first()->price)->toBe('$99.99');
});

it('returns collection of ProductSearchResultDto', function () {
    $html = <<<'HTML'
    <div class="results">
        <div class="product-item">
            <h2 class="title">Product 1</h2>
            <a class="product-link" href="/product1">Link 1</a>
        </div>
    </div>
    HTML;

    $strategy = [
        'list_container' => [
            'type' => 'selector',
            'value' => '.product-item',
        ],
        'product_title' => [
            'type' => 'selector',
            'value' => 'h2.title',
        ],
        'product_url' => [
            'type' => 'selector',
            'value' => 'a.product-link|href',
        ],
    ];

    $service = new ProductSourceSearchService;
    $results = $service->extractProducts($html, $strategy);

    expect($results->first())->toBeInstanceOf(\App\DataTransferObjects\ProductSearchResultDto::class);
});

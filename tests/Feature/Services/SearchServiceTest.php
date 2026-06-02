<?php

use App\Models\UrlResearch;
use App\Services\SearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(RefreshDatabase::class, TestCase::class);

it('stops hydrating after the configured number of priced results', function () {
    $service = new class('laptop') extends SearchService
    {
        public function getMaxPricedResults(): int
        {
            return 2;
        }

        protected function getHydratedResultData(array $result): array
        {
            $price = match ($result['url']) {
                'https://example.com/priced-1' => 100.0,
                'https://example.com/priced-2' => 200.0,
                'https://example.com/priced-3' => 300.0,
                default => null,
            };

            return [
                'price' => $price,
                'image' => null,
                'strategies' => [],
                'is_product_page' => null,
                'html' => null,
            ];
        }
    };

    $service->results = collect([
        ['title' => 'One', 'url' => 'https://example.com/priced-1', 'domain' => 'example.com'],
        ['title' => 'Two', 'url' => 'https://example.com/no-price', 'domain' => 'example.com'],
        ['title' => 'Three', 'url' => 'https://example.com/priced-2', 'domain' => 'example.com'],
        ['title' => 'Four', 'url' => 'https://example.com/priced-3', 'domain' => 'example.com'],
    ]);

    $savedUrls = [];
    UrlResearch::saved(function (UrlResearch $research) use (&$savedUrls) {
        $savedUrls[] = $research->url;
    });

    $service->hydrateWithScrapedData();

    // Each processed result must be persisted exactly once (guards against double hydration/persistence).
    expect($savedUrls)->toBe([
        'https://example.com/priced-1',
        'https://example.com/no-price',
        'https://example.com/priced-2',
    ]);

    expect($service->results->pluck('url')->all())->toBe([
        'https://example.com/priced-1',
        'https://example.com/no-price',
        'https://example.com/priced-2',
    ]);

    expect(UrlResearch::query()->orderBy('id')->pluck('url')->all())->toBe([
        'https://example.com/priced-1',
        'https://example.com/no-price',
        'https://example.com/priced-2',
    ]);

    expect(UrlResearch::query()->whereNotNull('price')->count())->toBe(2);
});

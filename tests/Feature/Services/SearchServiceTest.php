<?php

use App\Models\UrlResearch;
use App\Services\Helpers\IntegrationHelper;
use App\Services\SearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(RefreshDatabase::class, TestCase::class);

it('stops hydrating after the configured number of priced results', function () {
    IntegrationHelper::setSettings([
        'searxng' => [
            'max_priced_results' => 2,
        ],
    ]);

    $service = new class('laptop') extends SearchService
    {
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

    $service->hydrateWithScrapedData();

    expect($service->results->pluck('url')->all())->toBe([
        'https://example.com/priced-1',
        'https://example.com/no-price',
        'https://example.com/priced-2',
    ]);

    expect(UrlResearch::query()->pluck('url')->all())->toBe([
        'https://example.com/priced-1',
        'https://example.com/no-price',
        'https://example.com/priced-2',
    ]);

    expect(UrlResearch::query()->whereNotNull('price')->count())->toBe(2);
});

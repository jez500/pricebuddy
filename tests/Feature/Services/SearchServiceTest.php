<?php

namespace Tests\Feature\Services;

use App\Models\UrlResearch;
use App\Services\SearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SearchServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_stops_hydrating_after_the_configured_number_of_priced_results()
    {
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
        $this->assertSame([
            'https://example.com/priced-1',
            'https://example.com/no-price',
            'https://example.com/priced-2',
        ], $savedUrls);

        $this->assertSame([
            'https://example.com/priced-1',
            'https://example.com/no-price',
            'https://example.com/priced-2',
        ], $service->results->pluck('url')->all());

        $this->assertSame([
            'https://example.com/priced-1',
            'https://example.com/no-price',
            'https://example.com/priced-2',
        ], UrlResearch::query()->orderBy('id')->pluck('url')->all());

        $this->assertSame(2, UrlResearch::query()->whereNotNull('price')->count());
    }
}

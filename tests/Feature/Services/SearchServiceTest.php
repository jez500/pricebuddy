<?php

namespace Tests\Feature\Services;

use App\Enums\Icons;
use App\Models\UrlResearch;
use App\Services\Helpers\IntegrationHelper;
use App\Services\SearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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

    public function test_logs_unresponsive_engines_when_search_backend_returns_none()
    {
        IntegrationHelper::setSettings([
            'searxng' => [
                'enabled' => true,
                'url' => 'https://searxng.example.com/search',
            ],
        ]);

        Http::fake([
            'searxng.example.com/*' => Http::response([
                'query' => 'gateway max',
                'number_of_results' => 0,
                'results' => [],
                'unresponsive_engines' => [
                    ['brave', 'Suspended: too many requests'],
                    ['duckduckgo', 'CAPTCHA'],
                ],
            ]),
        ]);

        $service = new SearchService('gateway max');
        $service->getRawResults();

        $messages = collect($service->getLog())->pluck('message');

        $this->assertTrue(
            $messages->contains(fn ($message) => str_contains($message, 'engines unavailable')
                && str_contains($message, 'brave: Suspended: too many requests')
                && str_contains($message, 'duckduckgo: CAPTCHA')),
            'Expected the search log to surface the unresponsive engines. Got: '.$messages->implode(' | ')
        );
    }

    public function test_marks_fetch_errors_with_a_warning_icon()
    {
        IntegrationHelper::setSettings([
            'searxng' => [
                'enabled' => true,
                'url' => 'https://searxng.example.com/search',
            ],
        ]);

        Http::fake([
            'searxng.example.com/*' => Http::response('404 page not found', 404),
        ]);

        $service = new SearchService('gateway max');
        $service->getRawResults();

        $errorEntry = collect($service->getLog())
            ->first(fn ($entry) => str_contains($entry['message'], 'Error fetching results via SearchXNG'));

        $this->assertNotNull($errorEntry, 'Expected a fetch error to be logged.');
        $this->assertSame(Icons::Warning->value, data_get($errorEntry, 'data.icon'));
    }

    public function test_completion_headline_warns_when_no_results_are_found()
    {
        IntegrationHelper::setSettings([
            'searxng' => [
                'enabled' => true,
                'url' => 'https://searxng.example.com/search',
            ],
        ]);

        Http::fake([
            'searxng.example.com/*' => Http::response('404 page not found', 404),
        ]);

        $service = new SearchService;
        $service->build('gateway max');

        // The final log entry becomes the collapsed-log headline the user sees.
        $headline = collect($service->getLog())->last();

        $this->assertStringContainsString('No results', $headline['message']);
        $this->assertSame(Icons::Warning->value, data_get($headline, 'data.icon'));
    }

    public function test_generic_progress_entries_use_the_search_icon_by_default()
    {
        $service = new SearchService('laptop');
        $service->log('Filtering incompatible results');

        $entry = collect($service->getLog())->last();

        $this->assertSame(Icons::Search->value, data_get($entry, 'data.icon'));
    }

    public function test_priced_results_keep_the_success_icon_in_the_log()
    {
        $service = new class('laptop') extends SearchService
        {
            protected function getHydratedResultData(array $result): array
            {
                return [
                    'price' => 100.0,
                    'image' => null,
                    'strategies' => [],
                    'is_product_page' => null,
                    'html' => null,
                ];
            }
        };

        $service->results = collect([
            ['title' => 'One', 'url' => 'https://example.com/priced-1', 'domain' => 'example.com'],
        ]);

        $service->hydrateWithScrapedData();

        $priceEntry = collect($service->getLog())
            ->first(fn ($entry) => str_contains($entry['message'], 'Price found'));

        $this->assertNotNull($priceEntry, 'Expected a "Price found" log entry.');
        $this->assertSame(Icons::Success->value, data_get($priceEntry, 'data.icon'));
    }

    public function test_product_source_count_is_interpolated_in_the_log()
    {
        $service = new SearchService('laptop');
        $service->getProductSourceResults();

        $messages = collect($service->getLog())->pluck('message');

        $this->assertTrue($messages->contains('Using 0 product sources'));
        $this->assertFalse(
            $messages->contains(fn ($message) => str_contains($message, ':count')),
            'The :count placeholder should be replaced with the actual count.'
        );
    }
}

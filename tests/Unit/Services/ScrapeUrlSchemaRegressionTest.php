<?php

namespace Tests\Unit\Services;

use App\Models\Store;
use App\Services\ScrapeUrl;
use Tests\TestCase;
use Tests\Traits\ScraperTrait;

class ScrapeUrlSchemaRegressionTest extends TestCase
{
    use ScraperTrait;

    public function test_scrape_ignores_an_invalid_schema_field_and_keeps_other_fields(): void
    {
        $store = new Store([
            'name' => 'Example Store',
            'domains' => [
                ['domain' => parse_url('https://example.com/product', PHP_URL_HOST)],
            ],
            'scrape_strategy' => [
                'title' => ['type' => 'selector', 'value' => 'meta[property=og:title]|content'],
                'price' => ['type' => 'selector', 'value' => 'meta[property=og:price:amount]|content'],
                'description' => ['type' => 'regex', 'value' => '[broken'],
            ],
            'settings' => [
                'scraper_service' => 'http',
                'scraper_service_settings' => '',
            ],
        ]);

        $this->mockScrape('$12.34', 'Example Title');

        $result = ScrapeUrl::new('https://example.com/product')
            ->setMaxAttempts(1)
            ->setLogErrors(false)
            ->setSendUiNotifications(false)
            ->scrape(['store' => $store, 'use_cache' => false]);

        $this->assertSame('Example Title', $result['title']);
        $this->assertSame('$12.34', $result['price']);
        $this->assertNull($result['description']);
    }
}

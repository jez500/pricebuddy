<?php

use App\Enums\ScraperService;

return [
    [
        'name' => 'Flipkart',
        'slug' => 'flipkart',
        'initials' => 'FK',
        'domains' => [
            ['domain' => 'flipkart.com'],
            ['domain' => 'www.flipkart.com'],
        ],
        'scrape_strategy' => [
            'title' => [
                'value' => 'meta[property="og:title"]|content',
                'type' => 'selector',
            ],
            'price' => [
                'value' => 'schema_org',
                'type' => 'schema_org',
            ],
            'image' => [
                'value' => 'meta[property="og:image"]|content',
                'type' => 'selector',
            ],
        ],
        'settings' => [
            'scraper_service' => ScraperService::Api->value,
            'scraper_service_settings' => '',
            'locale_settings' => [
                'locale' => 'en_IN',
                'currency' => 'INR',
            ],
        ],
    ],
    [
        'name' => 'Amazon India',
        'slug' => 'amazon-india',
        'initials' => 'AI',
        'domains' => [
            ['domain' => 'amazon.in'],
            ['domain' => 'www.amazon.in'],
        ],
        'scrape_strategy' => [
            'title' => [
                'value' => 'title',
                'type' => 'selector',
            ],
            'price' => [
                'value' => '.a-price > .a-offscreen',
                'type' => 'selector',
            ],
            'image' => [
                'value' => '~"hiRes":"(.+?)"~',
                'type' => 'regex',
            ],
        ],
        'settings' => [
            'scraper_service' => ScraperService::Http->value,
            'scraper_service_settings' => '',
            'locale_settings' => [
                'locale' => 'en_IN',
                'currency' => 'INR',
            ],
        ],
    ],
];

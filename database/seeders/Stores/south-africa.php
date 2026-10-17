<?php

use App\Enums\ScraperService;

return [
    [
        'name' => 'Takealot',
        'slug' => 'takealot',
        'domains' => [
            ['domain' => 'takealot.com'],
            ['domain' => 'www.takealot.com'],
        ],
        'scrape_strategy' => [
            'title' => [
                'value' => '[data-ref="product-title"] h1',
                'type' => 'selector',
            ],
            'price' => [
                'value' => '[data-ref="buybox-offer"] [data-ref="price"] > .currency',
                'type' => 'selector',
            ],
            'image' => [
                'value' => 'img[data-ref="main-gallery-photo-0"], img[data-ref="main-gallery-photo-single"]|src',
                'type' => 'selector',
            ],
            'availability' => [
                'value' => '[data-ref="stock-availability-status"]',
                'type' => 'selector',
                'match' => [
                    'out_of_stock' => ['type' => 'regex', 'value' => 'out of stock|sold out|currently unavailable|not available'],
                    'pre_order' => ['type' => 'regex', 'value' => 'pre[- ]?order'],
                    'back_order' => ['type' => 'regex', 'value' => 'back[- ]?order'],
                    'default' => 'in_stock',
                ],
            ],
        ],
        'settings' => [
            'scraper_service' => ScraperService::Api->value,
            'scraper_service_settings' => "wait-until=domcontentloaded\nsleep=5000",
            'test_url' => 'https://www.takealot.com/bosch-series-8-14-place-freestanding-dishwasher-a-silver-inox/PLID93203543',
            'locale_settings' => [
                'locale' => 'en_ZA',
                'currency' => 'ZAR',
            ],
        ],
    ],
];

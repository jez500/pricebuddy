<?php

return [
    [
        'name' => 'Amazon US',
        'slug' => 'amazon-us',
        'domains' => [
            ['domain' => 'amazon.com'],
            ['domain' => 'www.amazon.com'],
        ],
        'scrape_strategy' => [
            'title' => [
                'value' => 'title',
                'type' => 'selector',
            ],
            'price' => [
                'value' => '.apex-pricetopay-value > .a-offscreen',
                'type' => 'selector',
            ],
            'image' => [
                'value' => '~"hiRes":"(.+?)"~',
                'type' => 'regex',
            ],
            'availability' => [
                'type' => 'selector',
                'value' => '#outOfStock',
            ],
        ],
        'settings' => [
            'locale_settings' => [
                'locale' => 'en_US',
                'currency' => 'USD',
            ],
        ],
    ],
    [
        'name' => 'eBay US',
        'slug' => 'ebay-us',
        'domains' => [
            ['domain' => 'ebay.com'],
            ['domain' => 'www.ebay.com'],
        ],
        'scrape_strategy' => [
            'title' => [
                'value' => 'meta[property=og:title]|content',
                'type' => 'selector',
            ],
            'price' => [
                'value' => '.x-price-primary',
                'type' => 'selector',
            ],
            'image' => [
                'value' => 'meta[property=og:image]|content',
                'type' => 'selector',
            ],
        ],
    ],
];

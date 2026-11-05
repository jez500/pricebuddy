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
                'value' => '.a-price > .a-offscreen',
                'type' => 'selector',
            ],
            'image' => [
                'value' => '~"hiRes":"(.+?)"~',
                'type' => 'regex',
            ],
        ],
    ],
    [
        'name' => 'BestBuy',
        'slug' => 'bestbuy',
        'domains' => [
            ['domain' => 'bestbuy.com'],
            ['domain' => 'www.bestbuy.com'],
        ],
        'scrape_strategy' => [
            'title' => [
                'value' => 'meta[property=og:title]|content',
                'type' => 'selector',
            ],
            'price' => [
                'value' => '~>\$((?:\d{1,3}(?:,\d{3})+|\d+)(\.\d{2})?)<~',
                'type' => 'regex',
            ],
            'image' => [
                'value' => '~"primaryImage":\{"__typename":"ProductImage","piscesHref":"([^"]{1,2048})"\}~',
                'type' => 'regex',
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

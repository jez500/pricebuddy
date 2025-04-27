<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Help link in sidebar.
    |--------------------------------------------------------------------------
    */
    'help_url' => env('HELP_URL', 'https://pricebuddy.jez.me?ref=pb-app'),

    /*
    |--------------------------------------------------------------------------
    | How many products to scrape at a time.
    |--------------------------------------------------------------------------
    */
    'chunk_size' => 10,

    /*
    |--------------------------------------------------------------------------
    | The url to the scraper service.
    |--------------------------------------------------------------------------
    */
    'scraper_api_url' => env('SCRAPER_BASE_URL', 'http://scraper:3000'),

    /*
    |--------------------------------------------------------------------------
    | Strategies to attempt for auto store creation.
    |
    | For each strategy, you can specify a selector and/or regex to attempt to
    | extract the data from the page. Selectors will be attempted first
    | with the first working match being used to create the store.
    |--------------------------------------------------------------------------
    */
    'auto_create_store_strategies' => [
        'title' => [
            'selector' => [
                'meta[property="og:title"]|content',
                'title',
                'h1',
            ],
            'xpath' => [],
            'regex' => [],
        ],
        'price' => [
            'selector' => [
                'meta[property="product:price:amount"]|content',
                '.a-price .a-offscreen',            // Amazon
                '[itemProp="price"]|content',
                '.price',
                '[class^="price"]',
                '[class*="price"]',
            ],
            'xpath' => [],
            'regex' => [
                '~\"price\"\:\s?\"(.*?)\"~',        // Something that looks like a price, in a json object, eg "price": "99.99"
                '~>\$(\d+(\.\d{2})?)<~',            // Something that looks like a price, in a tag, eg >$99.99<
                '~\$(\d+(\.\d{2})?)~',              // Something that looks like a price, not in a tag
            ],
        ],
        'image' => [
            'selector' => [
                'meta[property="og:image"]|content',
                'meta[property="og:image:secure_url"]|content',
            ],
            'xpath' => [],
            'regex' => [
                '~\"hiRes\":\"(.+?)\"~',            // Amazon
                '~\"image\"\:\s?\"(.*?\.jpg)\"~',   // Something that looks like an image, in a json object, eg "price": "99.99"
                '~\"image\"\:\s?\"(.*?\.png)\"~',   // Something that looks like an image, in a json object, eg "price": "99.99"
            ],
        ],
    ],
];

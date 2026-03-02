<?php

use App\Enums\ScraperStrategyType;

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
            ScraperStrategyType::SchemaOrg->value => [],
            ScraperStrategyType::Selector->value => [
                'meta[property="og:title"]|content',
                'title',
                'h1',
            ],
            ScraperStrategyType::xPath->value => [],
            ScraperStrategyType::Regex->value => [],
        ],
        'price' => [
            ScraperStrategyType::SchemaOrg->value => [],
            ScraperStrategyType::Selector->value => [
                'meta[property="product:price:amount"]|content',
                'meta[property="og:price:amount"]|content',
                '.a-price .a-offscreen',            // Amazon
                '[itemProp="price"]|content',
                '.price',
                '.product-price, .product-price-value',
                '[class^="price"]',
                '[class*="price"]',
            ],
            ScraperStrategyType::xPath->value => [],
            ScraperStrategyType::Regex->value => [
                '~\"price\"\:\s?\"(.*?)\"~',        // Something that looks like a price, in a json object, eg "price": "99.99"
                '~>\$(\d+(\.\d{2})?)<~',            // Something that looks like a price, in a tag, eg >$99.99<
                '~\$(\d+(\.\d{2})?)~',              // Something that looks like a price, not in a tag
            ],
        ],
        'image' => [
            ScraperStrategyType::SchemaOrg->value => [],
            ScraperStrategyType::Selector->value => [
                'meta[property="og:image"]|content',
                'meta[property="og:image:secure_url"]|content',
            ],
            ScraperStrategyType::xPath->value => [],
            ScraperStrategyType::Regex->value => [
                '~\"hiRes\":\"(.+?)\"~',            // Amazon
                '~\"image\"\:\s?\"(.*?\.jpg)\"~',   // Something that looks like an image, in a json object, eg "price": "99.99"
                '~\"image\"\:\s?\"(.*?\.png)\"~',   // Something that looks like an image, in a json object, eg "price": "99.99"
            ],
        ],
    ],
];

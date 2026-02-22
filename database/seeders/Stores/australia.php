<?php

use App\Enums\ScraperService;

return [
    // Retail.
    [
        'name' => 'Amazon AU',
        'slug' => 'amazon-au',
        'domains' => [
            ['domain' => 'amzn.asia'],
            ['domain' => 'amazon.com.au'],
            ['domain' => 'www.amazon.com.au'],
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
        'name' => 'Good Guys',
        'slug' => 'good-guys',
        'domains' => [
            ['domain' => 'thegoodguys.com.au'],
            ['domain' => 'www.thegoodguys.com.au'],
        ],
        'scrape_strategy' => [
            'title' => [
                'value' => 'meta[property=og:title]|content',
                'type' => 'selector',
            ],
            'price' => [
                'value' => 'meta[property=og:price:amount]|content',
                'type' => 'selector',
            ],
            'image' => [
                'value' => 'meta[property=og:image]|content',
                'type' => 'selector',
            ],
        ],
        'user_id' => 1,
    ],
    [
        'name' => 'JB Hi-Fi',
        'initials' => 'JB',
        'slug' => 'jb-hi-fi',
        'domains' => [
            ['domain' => 'jbhifi.com.au'],
            ['domain' => 'www.jbhifi.com.au'],
        ],
        'scrape_strategy' => [
            'title' => [
                'value' => 'meta[property=og:title]|content',
                'type' => 'selector',
            ],
            'price' => [
                'value' => 'meta[property=og:price:amount]|content',
                'type' => 'selector',
            ],
            'image' => [
                'value' => 'meta[property=og:image]|content',
                'type' => 'selector',
            ],
        ],
        'user_id' => 1,
    ],
    [
        'name' => 'eBay AU',
        'slug' => 'ebay-au',
        'domains' => [
            ['domain' => 'ebay.com.au'],
            ['domain' => 'www.ebay.com.au'],
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
    [
        'name' => 'K-Mart AU',
        'initials' => 'KM',
        'slug' => 'kmart-au',
        'domains' => [
            ['domain' => 'kmart.com.au'],
            ['domain' => 'www.kmart.com.au'],
        ],
        'scrape_strategy' => [
            'title' => [
                'value' => 'title',
                'type' => 'selector',
            ],
            'price' => [
                'value' => '.product-price-large',
                'type' => 'selector',
            ],
            'image' => [
                'value' => '~"image":"(http.*\.jpg)~',
                'type' => 'regex',
            ],
        ],
        'user_id' => 1,
    ],
    [
        'name' => 'Officeworks',
        'initials' => 'OW',
        'slug' => 'officeworks-au',
        'domains' => [
            ['domain' => 'officeworks.com.au'],
            ['domain' => 'www.officeworks.com.au'],
            ['domain' => 'api.officeworks.com.au'],
        ],
        'scrape_strategy' => [
            'title' => [
                'value' => 'title',
                'type' => 'selector',
            ],
            'price' => [
                'value' => '~\"edlpPrice\":\"(.*?)\"~',
                'type' => 'regex',
            ],
            'image' => [
                'value' => '~\"image\":\"(.*?)\"~',
                'type' => 'regex',
            ],
        ],
        'user_id' => 1,
    ],
    [
        'name' => 'Target AU',
        'initials' => 'TA',
        'slug' => 'target-au',
        'domains' => [
            ['domain' => 'target.com.au'],
            ['domain' => 'www.target.com.au'],
        ],
        'scrape_strategy' => [
            'title' => [
                'value' => 'meta[property=og:title]|content',
                'type' => 'selector',
            ],
            'price' => [
                'value' => '~\"valueString\"\:\"(.*?)\"~',
                'type' => 'regex',
            ],
            'image' => [
                'value' => 'meta[property=og:image]|content',
                'type' => 'selector',
            ],
        ],
        'user_id' => 1,
    ],
    [
        'name' => 'Bunnings',
        'initials' => 'BU',
        'slug' => 'bunnings-au',
        'domains' => [
            ['domain' => 'bunnings.com.au'],
            ['domain' => 'www.bunnings.com.au'],
        ],
        'scrape_strategy' => [
            'title' => [
                'value' => 'meta[property=og:title]|content',
                'type' => 'selector',
            ],
            'price' => [
                'value' => '[data-locator="product-price"]',
                'type' => 'selector',
            ],
            'image' => [
                'value' => 'meta[property=og:image]|content',
                'type' => 'selector',
            ],
        ],
        'user_id' => 1,
    ],
    [
        'name' => 'Big W',
        'initials' => 'BW',
        'slug' => 'big-w-au',
        'domains' => [
            ['domain' => 'bigw.com.au'],
            ['domain' => 'www.bigw.com.au'],
        ],
        'scrape_strategy' => [
            'title' => [
                'value' => 'meta[property=og:title]|content',
                'type' => 'selector',
            ],
            'price' => [
                'value' => '[data-testid="price-value"]',
                'type' => 'selector',
            ],
            'image' => [
                'value' => 'meta[property=og:image]|content',
                'type' => 'selector',
            ],
        ],
        'user_id' => 1,
    ],
    [
        'name' => 'Coles AU',
        'initials' => 'CO',
        'slug' => 'coles-au',
        'domains' => [
            ['domain' => 'coles.com.au'],
            ['domain' => 'www.coles.com.au'],
        ],
        'scrape_strategy' => [
            'title' => [
                'value' => 'meta[property=og:title]|content',
                'type' => 'selector',
            ],
            'price' => [
                'value' => '.price__value',
                'type' => 'selector',
            ],
            'image' => [
                'value' => 'meta[property=og:image]|content',
                'type' => 'selector',
            ],
            'availability' => [
                'value' => '~"availability":"https?://schema.org/(\w+)"~',
                'type' => 'regex',
                'match' => [
                    'out_of_stock' => ['type' => 'match', 'value' => 'OutOfStock'],
                    'pre_order' => ['type' => 'match', 'value' => 'PreOrder'],
                    'discontinued' => ['type' => 'match', 'value' => 'Discontinued'],
                    'back_order' => ['type' => 'match', 'value' => 'BackOrder'],
                    'default' => 'in_stock',
                ],
            ],
        ],
        'user_id' => 1,
    ],
    [
        'name' => 'Woolworths AU',
        'initials' => 'WW',
        'slug' => 'woolworths-au',
        'domains' => [
            ['domain' => 'woolworths.com.au'],
            ['domain' => 'www.woolworths.com.au'],
        ],
        'scrape_strategy' => [
            'title' => [
                'value' => 'meta[property=og:title]|content',
                'type' => 'selector',
            ],
            'price' => [
                'value' => '~"price":(\d+\.?\d*),"priceCurrency":"AUD"~',
                'type' => 'regex',
            ],
            'image' => [
                'value' => '~"image":"(https://cdn0\.woolworths\.media/content/wowproductimages/large/[^"]+)"~',
                'type' => 'regex',
            ],
            'availability' => [
                'value' => '~"availability":"https?://schema.org/(\w+)"~',
                'type' => 'regex',
                'match' => [
                    'out_of_stock' => ['type' => 'match', 'value' => 'OutOfStock'],
                    'pre_order' => ['type' => 'match', 'value' => 'PreOrder'],
                    'discontinued' => ['type' => 'match', 'value' => 'Discontinued'],
                    'back_order' => ['type' => 'match', 'value' => 'BackOrder'],
                    'default' => 'in_stock',
                ],
            ],
        ],
        'settings' => [
            'scraper_service' => ScraperService::Api->value,
            'scraper_service_settings' => '',
        ],
    ],
    [
        'name' => 'Harvey Norman',
        'initials' => 'HN',
        'slug' => 'harvey-norman',
        'domains' => [
            ['domain' => 'harveynorman.com.au'],
            ['domain' => 'www.harveynorman.com.au'],
        ],
        'scrape_strategy' => [
            'title' => [
                'value' => 'h1.title',
                'type' => 'selector',
            ],
            'price' => [
                'value' => '[class^="PriceCard_sf-price-card__price"]',
                'type' => 'selector',
            ],
            'image' => [
                'value' => '.img|src',
                'type' => 'selector',
            ],
        ],
        'settings' => [
            'scraper_service' => ScraperService::Api->value,
            'scraper_service_settings' => '',
        ],
    ],

    // Tech.
    [
        'name' => 'The Tech Geeks',
        'initials' => 'TG',
        'slug' => 'the-tech-geeks',
        'domains' => [
            ['domain' => 'thetechgeeks.com'],
            ['domain' => 'www.thetechgeeks.com'],
        ],
        'scrape_strategy' => [
            'title' => [
                'value' => 'meta[property=og:title]|content',
                'type' => 'selector',
            ],
            'price' => [
                'value' => 'meta[property=og:price:amount]|content',
                'type' => 'selector',
            ],
            'image' => [
                'value' => 'meta[property=og:image]|content',
                'type' => 'selector',
            ],
            'availability' => [
                'value' => 'button[name="add"]|value',
                'type' => 'selector',
                'match' => [
                    'pre_order' => ['type' => 'regex', 'value' => 'Pre.?Order'],
                    'special_order' => ['type' => 'regex', 'value' => 'Special.?Order'],
                    'back_order' => ['type' => 'regex', 'value' => 'Back.?Order'],
                    'out_of_stock' => ['type' => 'match', 'value' => 'Sold out'],
                    'default' => 'in_stock',
                ],
            ],
        ],
        'settings' => [
            'scraper_service' => ScraperService::Api->value,
            'scraper_service_settings' => '',
        ],
    ],

    // Liquor.
    [
        'name' => 'BWS API',
        'slug' => 'bws-api',
        'domains' => [
            ['domain' => 'api.bws.com.au'],
        ],
        'scrape_strategy' => [
            'title' => [
                'value' => 'Products.0.Name',
                'type' => 'json',
            ],
            'price' => [
                'value' => 'Products.0.Price',
                'type' => 'json',
            ],
            'image' => [
                'value' => 'PackDefaultStockCode',
                'type' => 'json',
                'prepend' => 'https://edgmedia.bws.com.au/bws/media/products/',
                'append' => '-1.png',
            ],
        ],
    ],
    [
        'name' => 'BWS',
        'slug' => 'bws',
        'domains' => [
            ['domain' => 'bws.com.au'],
            ['domain' => 'www.bws.com.au'],
        ],
        'scrape_strategy' => [
            'title' => [
                'value' => '.detail-item_brand',
                'type' => 'selector',
            ],
            'price' => [
                'value' => '.trolley-controls_volume_price--dollars',
                'type' => 'selector',
            ],
            'image' => [
                'value' => '.product-image',
                'type' => 'selector',
            ],
        ],
        'settings' => [
            'scraper_service' => ScraperService::Api->value,
            'scraper_service_settings' => '',
        ],
    ],
    [
        'name' => 'Liquorland',
        'initials' => 'LL',
        'slug' => 'liquorland',
        'domains' => [
            ['domain' => 'liquorland.com.au'],
            ['domain' => 'www.liquorland.com.au'],
        ],
        'scrape_strategy' => [
            'title' => [
                'value' => 'meta[property=og:title]|content',
                'type' => 'selector',
            ],
            'price' => [
                'value' => '.FormattedAmount',
                'type' => 'selector',
            ],
            'image' => [
                'value' => 'meta[property=og:image]|content',
                'type' => 'selector',
            ],
        ],
        'settings' => [
            'scraper_service' => ScraperService::Api->value,
            'scraper_service_settings' => '',
        ],
    ],
    [
        'name' => 'Dan Murphys',
        'initials' => 'DM',
        'slug' => 'dan-murphys',
        'domains' => [
            ['domain' => 'www.danmurphys.com.au', 'danmurphys.com.au'],
        ],
        'scrape_strategy' => [
            'title' => [
                'value' => 'meta[property=og:title]|content',
                'type' => 'selector',
            ],
            'price' => [
                'value' => '.pack__price-info',
                'type' => 'selector',
            ],
            'image' => [
                'value' => 'meta[property=og:image]|content',
                'type' => 'selector',
            ],
        ],
        'user_id' => 1,
        'settings' => [
            'scraper_service' => ScraperService::Api->value,
            'scraper_service_settings' => '',
        ],
    ],

];

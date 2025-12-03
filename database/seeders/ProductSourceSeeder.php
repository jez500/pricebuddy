<?php

namespace Database\Seeders;

use App\Enums\ProductSourceStatus;
use App\Enums\ProductSourceType;
use App\Models\ProductSource;
use App\Models\Store;
use Illuminate\Database\Seeder;

class ProductSourceSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // OzBargain (Deals Site)
        ProductSource::create([
            'name' => 'OzBargain',
            'type' => ProductSourceType::DealsSite,
            'status' => ProductSourceStatus::Active,
            'search_url' => 'https://www.ozbargain.com.au/search/node/:search_term',
            'extraction_strategy' => [
                'list_container' => [
                    'type' => 'selector',
                    'value' => '.node.node-ozbdeal',
                ],
                'product_title' => [
                    'type' => 'selector',
                    'value' => 'h2.title a',
                ],
                'product_url' => [
                    'type' => 'selector',
                    'value' => 'h2.title a|href',
                ],
            ],
            'settings' => [
                'scraper_service' => 'http',
            ],
        ]);

        // Amazon AU (Online Store) - only create if an Amazon store exists
        $amazonStore = Store::where('name', 'like', '%Amazon%')->first();
        if ($amazonStore) {
            ProductSource::create([
                'name' => 'Amazon Australia',
                'type' => ProductSourceType::OnlineStore,
                'status' => ProductSourceStatus::Active,
                'store_id' => $amazonStore->id,
                'search_url' => 'https://www.amazon.com.au/s?k=:search_term',
                'extraction_strategy' => [
                    'list_container' => [
                        'type' => 'selector',
                        'value' => 'div[data-component-type="s-search-result"]',
                    ],
                    'product_title' => [
                        'type' => 'selector',
                        'value' => 'h2 a span',
                    ],
                    'product_url' => [
                        'type' => 'selector',
                        'value' => 'h2 a|href',
                    ],
                    'product_price' => [
                        'type' => 'selector',
                        'value' => '.a-price-whole',
                    ],
                ],
                'settings' => [
                    'scraper_service' => 'http',
                ],
            ]);
        }
    }
}

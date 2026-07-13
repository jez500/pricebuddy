<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Store;
use App\Models\Url;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Support\Collection;

/**
 * Builds an in-memory (non-persisted) Url wired to a dummy Product and Store so
 * PriceAlertNotification / StockAlertNotification can be dispatched as a realistic
 * preview. This lets users verify their notification channels end-to-end without
 * needing a real tracked product or a genuine price-drop / back-in-stock event.
 *
 * The accessors that would normally hit the database or generate routes
 * (latest_price_formatted, buy_url, product_url, price_aggregates) are overridden
 * with sample values so nothing is queried or written.
 */
class DummyNotificationFactory
{
    public const SAMPLE_PRODUCT_TITLE = 'Sample Wireless Headphones (Test)';

    public const SAMPLE_STORE_NAME = 'Demo Store';

    public const SAMPLE_PRICE = '$39.99';

    public const SAMPLE_AVERAGE_PRICE = '$59.99';

    public static function makeUrl(): Url
    {
        $store = new Store(['name' => self::SAMPLE_STORE_NAME]);

        $product = self::makeProduct();

        $url = new class extends Url
        {
            protected function latestPriceFormatted(): Attribute
            {
                return Attribute::make(get: fn (): string => DummyNotificationFactory::SAMPLE_PRICE);
            }

            protected function buyUrl(): Attribute
            {
                return Attribute::make(get: fn (): string => url('/'));
            }

            protected function productUrl(): Attribute
            {
                return Attribute::make(get: fn (): string => url('/'));
            }
        };

        $url->forceFill(['url' => url('/')]);
        $url->setRelation('product', $product);
        $url->setRelation('store', $store);

        return $url;
    }

    protected static function makeProduct(): Product
    {
        $product = new class extends Product
        {
            public function priceAggregates(): Attribute
            {
                return Attribute::make(get: fn (): Collection => collect([
                    'max' => '$79.99',
                    'avg' => DummyNotificationFactory::SAMPLE_AVERAGE_PRICE,
                    'min' => DummyNotificationFactory::SAMPLE_PRICE,
                ]));
            }
        };

        $product->forceFill([
            'title' => self::SAMPLE_PRODUCT_TITLE,
            'image' => null,
        ]);

        return $product;
    }
}

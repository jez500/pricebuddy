<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\Tag;
use App\Models\Url;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Arr;
use Illuminate\Support\Sleep;

class ProductSeeder extends Seeder
{
    /**
     * Dummy products to create. No scraping.
     */
    protected array $dummy = [
        [
            'title' => 'Apple Ipad WiFi',
            'urls' => [
                'https://www.amazon.com.au/Apple-2025-iPad-Wi-Fi-128GB/dp/B0DZ8JZRXK' => ['615', '615', '599', '599', '597'],
                'https://www.bigw.com.au/product/apple-ipad-a16-wi-fi-128gb-yellow-2025-/p/6016877' => ['649', '649', '589', '589', '599'],
            ],
            'image' => 'https://m.media-amazon.com/images/I/61aPY8odPSL._AC_SX679_.jpg',
            'tag' => 'Tech',
        ],
        [
            'title' => 'Amazon Echo',
            'urls' => [
                'https://www.amazon.com.au/All-new-Echo-4th-Gen-Premium-Sound-Smart-Home-Hub-Alexa-Twilight-Blue/dp/B085HKT3TB' => ['89', '89', '89', '94', '94', '94', '99'],
                'https://www.jbhifi.com.au/products/amazon-echo-dot-smart-speaker-alexa-5th-gen-glacier-white' => ['75', '75', '69.99', '69.99', '69.99', '69', '69'],
            ],
            'image' => 'https://m.media-amazon.com/images/I/71nOFvpDeZL._AC_SY450_.jpg',
            'tag' => 'Tech',
        ],
        [
            'title' => 'Logitech MX Master 3S',
            'urls' => [
                'https://www.amazon.com.au/Logitech-Master-Performance-Ultra-Fast-Scrolling/dp/B0FNV6GP6K' => ['149', '149', '149', '135', '149', '95', '95'],
                'https://www.jbhifi.com.au/products/logitech-mx-master-3s-performance-wireless-mouse-graphite' => ['169', '169', '149', '149', '169', '169', '169'],
            ],
            'image' => 'https://m.media-amazon.com/images/I/61GKl6jshFL._AC_SL1500_.jpg',
            'tag' => 'Tech',
        ],
        [
            'title' => 'Paper towels',
            'urls' => [
                'https://www.amazon.com.au/Bounty-Select-Towels-Triple-Sheets/dp/B08V4D8YBC' => ['45', '45', '42', '42', '39'],
            ],
            'image' => 'https://m.media-amazon.com/images/I/812-y3MIhmL._AC_SX679_PIbundle-2,TopRight,0,0_SH20_.jpg',
            'tag' => 'Household',
        ],
        [
            'title' => 'Finish Ultimate Lemon Dishwasher Tablets',
            'urls' => [
                'https://www.woolworths.com.au/shop/productdetails/618643/finish-ultimate-lemon-dishwasher-tablets' => ['22', '20', '22', '18', '20', '20'],
                'https://www.coles.com.au/product/finish-ultimate-dishwashing-tablets-lemon-sparkle-34-pack-7752503' => ['24', '22', '20', '22', '21', '20'],
                'https://www.woolworths.com.au/shop/productdetails/78637/finish-ultimate-lemon-dishwasher-tablets' => ['12', '11', '11.50', '12', '11', '10.50'],
                'https://www.coles.com.au/product/finish-ultimate-lemon-dishwasher-tablets-16-pack-3679128' => ['23', '22', '23', '22.50', '22', '21'],
                'https://www.woolworths.com.au/shop/productdetails/148368/finish-ultimate-lemon-dishwasher-tablets' => ['27', '26', '25', '26', '25.50', '24.50'],
                'https://www.coles.com.au/product/finish-ultimate-dishwasher-tablets-lemon-46-pack-3967235' => ['52', '50', '51', '50', '50', '49'],
                'https://www.woolworths.com.au/shop/productdetails/675840/finish-ultimate-lemon-dishwasher-tablets' => ['33', '32', '31', '32', '31', '30'],
                'https://www.coles.com.au/product/finish-ultimate-dishwashing-tablets-lemon-sparkle-62-pack-7752489' => ['34', '33', '31', '32', '31', '30'],
                'https://www.woolworths.com.au/shop/productdetails/6019017/finish-ultimate-dishwasher-tablets-lemon' => ['35', '34', '33', '34', '33', '32'],
            ],
            'image' => 'https://assets.woolworths.com.au/images/1005/618643.jpg?impolicy=wowsmkqiema&w=600&h=600',
            'tag' => 'Household',
            'unit_of_measure' => 'tablets',
            'price_factors' => [
                'https://www.woolworths.com.au/shop/productdetails/618643/finish-ultimate-lemon-dishwasher-tablets' => 34,
                'https://www.coles.com.au/product/finish-ultimate-dishwashing-tablets-lemon-sparkle-34-pack-7752503' => 34,
                'https://www.woolworths.com.au/shop/productdetails/78637/finish-ultimate-lemon-dishwasher-tablets' => 16,
                'https://www.coles.com.au/product/finish-ultimate-lemon-dishwasher-tablets-16-pack-3679128' => 16,
                'https://www.woolworths.com.au/shop/productdetails/148368/finish-ultimate-lemon-dishwasher-tablets' => 46,
                'https://www.coles.com.au/product/finish-ultimate-dishwasher-tablets-lemon-46-pack-3967235' => 46,
                'https://www.woolworths.com.au/shop/productdetails/675840/finish-ultimate-lemon-dishwasher-tablets' => 62,
                'https://www.coles.com.au/product/finish-ultimate-dishwashing-tablets-lemon-sparkle-62-pack-7752489' => 62,
                'https://www.woolworths.com.au/shop/productdetails/6019017/finish-ultimate-dishwasher-tablets-lemon' => 70,
            ],
        ],
        [
            'title' => 'Lavazza Napoli Premium Coffee Beans 500g',
            'urls' => [
                'https://www.coles.com.au/product/lavazza-tales-of-italy-alluring-napoli-premium-coffee-beans-500g-6277834' => ['22', '24', '22', '19', '22'],
                'https://www.woolworths.com.au/shop/productdetails/309483/lavazza-tales-of-italy-alluring-napoli-coffee-beans' => ['23', '21', '23', '20', '21'],
                'https://www.amazon.com.au/Lavazza-Espresso-Chocolate-Intensity-Australia/dp/B0C1JWVRG5?tag=pricebuddy-22' => ['66', '63', '69', '57', '63'],
            ],
            'image' => 'https://cdn.productimages.coles.com.au/productimages/6/6277834.jpg',
            'tag' => 'Household',
            'unit_of_measure' => 'bags',
            'price_factors' => [
                'https://www.amazon.com.au/Lavazza-Espresso-Chocolate-Intensity-Australia/dp/B0C1JWVRG5?tag=pricebuddy-22' => 3,
            ],
        ],
        [
            'title' => 'Ubiquiti UniFi Protect Camera G6 180 (Black)',
            'urls' => [
                'https://thetechgeeks.com/products/uvc-g6-180-b' => ['prices' => ['731.50'], 'availability' => 'special_order'],
            ],
            'image' => 'https://thetechgeeks.com/cdn/shop/files/UVC-G6-180-B_grande.png',
            'tag' => 'Tech',
        ],
        [
            'title' => 'Ubiquiti UniFi Travel Router',
            'urls' => [
                'https://thetechgeeks.com/products/utr' => ['prices' => ['225.50'], 'availability' => 'special_order'],
            ],
            'image' => 'https://thetechgeeks.com/cdn/shop/files/UTR_grande.png',
            'tag' => 'Tech',
        ],
        [
            'title' => 'Ubiquiti UniFi Edge AI Appliance',
            'urls' => [
                'https://thetechgeeks.com/products/ai-key' => ['prices' => ['1815'], 'availability' => 'back_order'],
            ],
            'image' => 'https://thetechgeeks.com/cdn/shop/files/AI-Key_grande.png',
            'tag' => 'Tech',
        ],
        [
            'title' => 'Ubiquiti Pro Max 16 Rack Mount Kit',
            'urls' => [
                'https://thetechgeeks.com/products/uacc-pro-max-16-rm' => ['prices' => ['126.50'], 'availability' => 'pre_order'],
            ],
            'image' => 'https://thetechgeeks.com/cdn/shop/files/UACC-Pro-Max-16-RM_grande.png',
            'tag' => 'Tech',
        ],
    ];

    /**
     * Real urls to create with scraping.
     *
     * If item is array then it will be treated as a list of URLs for the same product.
     */
    protected array $urls = [
        [
            'https://api.bws.com.au/apis/ui/Product/971412',
            'https://www.liquorland.com.au/api/products/ll/vic/spirits/2614025',
            'https://www.danmurphys.com.au/product/DM_971412/sailor-jerry-the-original-spiced-rum-1l',
        ],
        'https://www.thegoodguys.com.au/dji-neo-fly-more-combo-6292316',
        [
            'https://www.amazon.com.au/DJI-QuickShots-Stabilized-Propeller-Controller-Free/dp/B07FTPX71F?th=1',
            'https://www.thegoodguys.com.au/dji-neo-6292315',
            'https://www.jbhifi.com.au/products/dji-neo-drone',
            'https://www.ebay.com.au/itm/405209468795',
        ],
    ];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->runDummy();
    }

    public function runDummy(): void
    {
        $userId = User::oldest('id')->first()?->id ?? User::factory()->create()->id;

        foreach ($this->dummy as $productData) {
            $factory = Product::factory();
            $priceFactors = $productData['price_factors'] ?? [];

            foreach ($productData['urls'] as $url => $urlData) {
                $priceFactor = $priceFactors[$url] ?? 1;

                if (is_array($urlData) && array_key_exists('prices', $urlData)) {
                    $factory = $factory->addUrlWithPrices($url, $urlData['prices'], $priceFactor, $urlData['availability'] ?? null);
                } else {
                    $factory = $factory->addUrlWithPrices($url, $urlData, $priceFactor);
                }
            }

            /** @var Product $product */
            $product = $factory->createOne([
                'title' => $productData['title'],
                'image' => $productData['image'],
                'unit_of_measure' => $productData['unit_of_measure'] ?? null,
                'user_id' => $userId,
            ]);

            $product->updatePriceCache();

            if ($tag = Tag::where('name', $productData['tag'])->first()) {
                $product->tags()->sync([$tag->id]);
                $product->save();
            }
        }
    }

    public function runReal(): void
    {
        $productModel = null;
        $userId = User::oldest('id')->first()?->id ?? User::factory()->create()->id;

        foreach ($this->urls as $urlList) {
            $urls = Arr::wrap($urlList);
            foreach ($urls as $idx => $url) {
                $productId = $idx > 0 ? $productModel?->id : null;

                $urlModel = Url::createFromUrl($url, $productId, $userId);

                if (! $urlModel) {
                    dump('Failed to scrape URL: '.$url);

                    continue;
                }

                $productModel = $urlModel->product;

                $this->createRandomPriceHistory($urlModel);

                $productModel->updatePriceCache();

                // Try to avoid getting blocked.
                Sleep::for(10)->seconds();
            }
        }
    }

    /**
     * Create random price history for the given URL.
     */
    protected function createRandomPriceHistory(Url $urlModel): void
    {
        $priceModel = $urlModel->prices()->first();

        if (empty($priceModel)) {
            dump('Url missing price, using $100: '.$urlModel->url);
            $price = 100;
        } else {
            $price = $priceModel->price;
        }

        // Set last 10 days of prices.
        for ($i = 1; $i <= 10; $i++) {
            $randOffset = rand(-20, 10);
            $randPrice = $price + $randOffset;
            $randPrice = $randPrice <= 0 ? 2.0 : $randPrice;
            $fakePriceModel = $urlModel->updatePrice($randPrice);
            $fakePriceModel->created_at = now()->subDays($i)->toDateTimeString();
            $fakePriceModel->updated_at = $fakePriceModel->created_at;
            $fakePriceModel->save();
        }
    }
}

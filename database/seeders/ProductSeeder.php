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
                'https://www.woolworths.com.au/shop/productdetails/618643/finish-ultimate-lemon-dishwasher-tablets' => ['24', '24', '22', '23', '24'],
                'https://www.coles.com.au/product/finish-ultimate-dishwashing-tablets-lemon-sparkle-34-pack-7752503' => ['22', '22', '21', '21', '22'],
                'https://www.woolworths.com.au/shop/productdetails/148368/finish-ultimate-lemon-dishwasher-tablets' => ['39', '39', '37', '38', '39'],
                'https://www.coles.com.au/product/finish-ultimate-dishwasher-tablets-lemon-46-pack-3967235' => ['35', '35', '33', '34', '35'],
            ],
            'image' => 'https://assets.woolworths.com.au/images/1005/618643.jpg?impolicy=wowsmkqiema&w=600&h=600',
            'tag' => 'Household',
        ],
        [
            'title' => 'Ubiquiti UniFi Protect Camera G6 180 (Black)',
            'urls' => [
                'https://thetechgeeks.com/products/uvc-g6-180-b' => ['prices' => ['731.50'], 'availability' => 'Special Order'],
            ],
            'image' => 'https://thetechgeeks.com/cdn/shop/files/UVC-G6-180-B_grande.png',
            'tag' => 'Tech',
        ],
        [
            'title' => 'Ubiquiti UniFi Travel Router',
            'urls' => [
                'https://thetechgeeks.com/products/utr' => ['prices' => ['225.50'], 'availability' => 'Special Order'],
            ],
            'image' => 'https://thetechgeeks.com/cdn/shop/files/UTR_grande.png',
            'tag' => 'Tech',
        ],
        [
            'title' => 'Ubiquiti UniFi Edge AI Appliance',
            'urls' => [
                'https://thetechgeeks.com/products/ai-key' => ['prices' => ['1815'], 'availability' => 'Back Order'],
            ],
            'image' => 'https://thetechgeeks.com/cdn/shop/files/AI-Key_grande.png',
            'tag' => 'Tech',
        ],
        [
            'title' => 'Ubiquiti Pro Max 16 Rack Mount Kit',
            'urls' => [
                'https://thetechgeeks.com/products/uacc-pro-max-16-rm' => ['prices' => ['126.50'], 'availability' => 'Pre-Order Now'],
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

            foreach ($productData['urls'] as $url => $urlData) {
                // Support both simple ['price1', 'price2'] and extended ['prices' => [...], 'availability' => '...'] formats.
                if (is_array($urlData) && array_key_exists('prices', $urlData)) {
                    $factory = $factory->addUrlWithPrices($url, $urlData['prices'], $urlData['availability'] ?? null);
                } else {
                    $factory = $factory->addUrlWithPrices($url, $urlData);
                }
            }

            /** @var Product $product */
            $product = $factory->createOne([
                'title' => $productData['title'],
                'image' => $productData['image'],
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

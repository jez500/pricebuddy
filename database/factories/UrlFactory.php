<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\Store;
use App\Models\Url;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Url>
 */
class UrlFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'url' => $this->faker->url,
            'product_id' => Product::factory(),
            'store_id' => Store::factory(),
            'price_factor' => 1,
        ];
    }

    public function withPrices(array $prices): self
    {
        return $this->afterCreating(function (Url $url) use ($prices) {

            $priceFactor = $url->price_factor ?: 1;

            foreach ($prices as $idx => $price) {
                $url->prices()->create([
                    'price' => $price,
                    'unit_price' => $price / $priceFactor,
                    'price_factor' => $priceFactor,
                    'store_id' => $url->store_id,
                    'created_at' => Carbon::now()->subDays(count($prices) - $idx)->setTime(6, 0)->toDateTimeString(),
                ]);
            }

            $url->product->updatePriceCache();
        });
    }
}

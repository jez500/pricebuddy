<?php

namespace Database\Factories;

use App\Models\Store;
use App\Models\Tag;
use App\Models\Url;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tag>
 */
class PriceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'price' => 10.00,
            'unit_price' => 10.00,
            'price_factor' => 1,
            'url_id' => Url::factory(),
            'store_id' => Store::factory(),
        ];
    }
}

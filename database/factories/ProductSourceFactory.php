<?php

namespace Database\Factories;

use App\Enums\ProductSourceStatus;
use App\Enums\ProductSourceType;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ProductSource>
 */
class ProductSourceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company().' Store',
            'search_url' => fake()->url().'/search?q=:search_term',
            'type' => ProductSourceType::OnlineStore,
            'store_id' => null,
            'extraction_strategy' => [
                'list_container' => [
                    'type' => 'selector',
                    'value' => '.product-item',
                ],
                'product_title' => [
                    'type' => 'selector',
                    'value' => 'h2.title',
                ],
                'product_url' => [
                    'type' => 'selector',
                    'value' => 'a.product-link|href',
                ],
            ],
            'settings' => [
                'scraper_service' => 'http',
            ],
            'status' => ProductSourceStatus::Active,
            'user_id' => null,
            'notes' => fake()->optional()->paragraph(),
        ];
    }

    public function user(User|int $user): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => is_int($user) ? $user : $user->getKey(),
        ]);
    }

    public function dealsSite(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => fake()->company().' Deals',
            'type' => ProductSourceType::DealsSite,
            'store_id' => null,
        ]);
    }

    public function onlineStore(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => fake()->company().' Store',
            'type' => ProductSourceType::OnlineStore,
            'store_id' => Store::factory(),
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ProductSourceStatus::Inactive,
        ]);
    }

    public function draft(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ProductSourceStatus::Draft,
        ]);
    }
}

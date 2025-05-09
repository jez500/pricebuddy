<?php

namespace Database\Factories;

use App\Enums\ScraperService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Illuminate\Support\Uri;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Store>
 */
class StoreFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->name,
            'initials' => '',
            'domains' => [
                ['domain' => 'example.com'],
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
            'settings' => [
                'scraper_service' => ScraperService::Http->value,
                'scraper_service_settings' => '',
            ],
        ];
    }

    public function forUrl(string $url): self
    {
        $host = strtolower(Uri::of($url)->host());
        $titleParts = explode('.', str_replace('www.', '', $host));

        return $this->state(fn (array $attributes) => [
            'name' => Str::title($titleParts[0] ?? $this->faker->word),
            'domains' => ['domain' => strtolower(Uri::of($url)->host())],
        ]);
    }
}

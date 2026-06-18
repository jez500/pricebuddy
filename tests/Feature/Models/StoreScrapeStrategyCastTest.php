<?php

namespace Tests\Feature\Models;

use App\Dto\StoreScraperStrategySetDto;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StoreScrapeStrategyCastTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_a_strategy_set_dto_from_the_scrape_strategy_attribute(): void
    {
        $store = Store::factory()->create([
            'scrape_strategy' => [
                'title' => ['type' => 'selector', 'value' => 'h1'],
                'price' => ['type' => 'selector', 'value' => '.price'],
            ],
        ]);

        $store->refresh();

        $this->assertInstanceOf(StoreScraperStrategySetDto::class, $store->scrape_strategy);
        $this->assertSame('h1', $store->scrape_strategy->title->value);
        $this->assertSame('.price', $store->scrape_strategy->price->value);
    }

    public function test_serializes_back_to_the_original_nested_array_shape(): void
    {
        $strategy = [
            'title' => ['type' => 'selector', 'value' => 'title'],
            'price' => ['type' => 'selector', 'value' => '.a-price > .a-offscreen'],
            'image' => ['type' => 'regex', 'value' => '~"hiRes":"(.+?)"~'],
        ];

        $store = Store::factory()->create(['scrape_strategy' => $strategy]);

        $this->assertEquals($strategy, $store->toArray()['scrape_strategy']);
    }

    public function test_stores_the_canonical_json_in_the_database_column(): void
    {
        $store = Store::factory()->create([
            'scrape_strategy' => ['title' => ['type' => 'selector', 'value' => 'h1']],
        ]);

        $raw = json_decode($store->getRawOriginal('scrape_strategy'), true);

        $this->assertEquals(['title' => ['type' => 'selector', 'value' => 'h1']], $raw);
    }
}

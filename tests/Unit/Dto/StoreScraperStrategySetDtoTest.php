<?php

namespace Tests\Unit\Dto;

use App\Dto\AvailabilityStrategyDto;
use App\Dto\StandardStrategyDto;
use App\Dto\StoreScraperStrategySetDto;
use PHPUnit\Framework\TestCase;

class StoreScraperStrategySetDtoTest extends TestCase
{
    public function test_from_array_builds_typed_slots(): void
    {
        $set = StoreScraperStrategySetDto::fromArray([
            'title' => ['type' => 'selector', 'value' => 'title'],
            'price' => ['type' => 'selector', 'value' => '.price'],
            'image' => ['type' => 'regex', 'value' => '~img~'],
            'description' => ['type' => 'selector', 'value' => '#desc'],
            'availability' => ['type' => 'selector', 'value' => '#avail'],
        ]);

        $this->assertInstanceOf(StandardStrategyDto::class, $set->title);
        $this->assertInstanceOf(StandardStrategyDto::class, $set->description);
        $this->assertInstanceOf(AvailabilityStrategyDto::class, $set->availability);
        $this->assertSame('title', $set->title->value);
    }

    public function test_empty_or_null_yields_all_null_slots(): void
    {
        foreach ([StoreScraperStrategySetDto::fromArray(null), StoreScraperStrategySetDto::fromArray([])] as $set) {
            $this->assertNull($set->title);
            $this->assertNull($set->price);
            $this->assertNull($set->image);
            $this->assertNull($set->description);
            $this->assertNull($set->availability);
        }
    }

    public function test_round_trips_a_seeded_strategy_unchanged(): void
    {
        // Mirrors database/seeders/Stores/usa.php (Amazon US).
        $input = [
            'title' => ['type' => 'selector', 'value' => 'title'],
            'price' => ['type' => 'selector', 'value' => '.a-price > .a-offscreen'],
            'image' => ['type' => 'regex', 'value' => '~"hiRes":"(.+?)"~'],
        ];

        $this->assertEquals($input, StoreScraperStrategySetDto::fromArray($input)->toArray());
    }

    public function test_round_trips_full_strategy_with_availability(): void
    {
        $input = [
            'title' => ['type' => 'selector', 'value' => 'title'],
            'price' => ['type' => 'selector', 'value' => '.price', 'prepend' => '$'],
            'image' => ['type' => 'regex', 'value' => '~img~'],
            'description' => ['type' => 'selector', 'value' => '#desc'],
            'availability' => [
                'type' => 'selector',
                'value' => '#avail',
                'match' => [
                    'out_of_stock' => ['type' => 'match', 'value' => 'Sold out'],
                    'default' => 'in_stock',
                ],
            ],
        ];

        $this->assertEquals($input, StoreScraperStrategySetDto::fromArray($input)->toArray());
    }
}

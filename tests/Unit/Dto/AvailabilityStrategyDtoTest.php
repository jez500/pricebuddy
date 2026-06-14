<?php

namespace Tests\Unit\Dto;

use App\Dto\AvailabilityMatchDto;
use App\Dto\AvailabilityStrategyDto;
use App\Enums\ScraperStrategyType;
use App\Enums\StockStatus;
use PHPUnit\Framework\TestCase;

class AvailabilityStrategyDtoTest extends TestCase
{
    public function test_from_array_splits_match_and_default(): void
    {
        $dto = AvailabilityStrategyDto::fromArray([
            'type' => 'selector',
            'value' => '#avail',
            'match' => [
                'out_of_stock' => ['type' => 'match', 'value' => 'Sold out'],
                'pre_order' => ['type' => 'regex', 'value' => 'pre.?order'],
                'default' => 'in_stock',
            ],
        ]);

        $this->assertSame(ScraperStrategyType::Selector, $dto->type);
        $this->assertSame('#avail', $dto->value);
        $this->assertSame(StockStatus::InStock, $dto->defaultStatus);
        $this->assertInstanceOf(AvailabilityMatchDto::class, $dto->match['out_of_stock']);
        $this->assertSame('Sold out', $dto->match['out_of_stock']->value);
        $this->assertArrayNotHasKey('default', $dto->match);
    }

    public function test_from_array_without_match(): void
    {
        $dto = AvailabilityStrategyDto::fromArray(['type' => 'selector', 'value' => '#avail']);

        $this->assertSame([], $dto->match);
        $this->assertSame(StockStatus::InStock, $dto->defaultStatus);
        $this->assertNull($dto->matchConfig());
    }

    public function test_match_config_rebuilds_legacy_array(): void
    {
        $dto = AvailabilityStrategyDto::fromArray([
            'type' => 'selector',
            'match' => [
                'out_of_stock' => ['type' => 'match', 'value' => 'Sold out'],
                'default' => 'in_stock',
            ],
        ]);

        $this->assertEquals([
            'out_of_stock' => ['type' => 'match', 'value' => 'Sold out'],
            'default' => 'in_stock',
        ], $dto->matchConfig());
    }

    public function test_to_array_round_trips(): void
    {
        $input = [
            'type' => 'selector',
            'value' => '#avail',
            'match' => [
                'out_of_stock' => ['type' => 'match', 'value' => 'Sold out'],
                'default' => 'in_stock',
            ],
        ];

        $this->assertEquals($input, AvailabilityStrategyDto::fromArray($input)->toArray());
    }

    public function test_to_array_omits_empty_match(): void
    {
        $this->assertEquals(
            ['type' => 'selector', 'value' => '#avail'],
            AvailabilityStrategyDto::fromArray(['type' => 'selector', 'value' => '#avail'])->toArray(),
        );
    }
}

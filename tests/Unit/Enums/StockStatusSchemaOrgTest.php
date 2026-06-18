<?php

namespace Tests\Unit\Enums;

use App\Dto\AvailabilityStrategyDto;
use App\Enums\StockStatus;
use PHPUnit\Framework\TestCase;

class StockStatusSchemaOrgTest extends TestCase
{
    public function test_maps_item_availability_urls_to_stock_status(): void
    {
        $map = [
            'https://schema.org/InStock' => StockStatus::InStock,
            'https://schema.org/OnlineOnly' => StockStatus::InStock,
            'https://schema.org/InStoreOnly' => StockStatus::InStock,
            'https://schema.org/LimitedAvailability' => StockStatus::InStock,
            'https://schema.org/OutOfStock' => StockStatus::OutOfStock,
            'https://schema.org/SoldOut' => StockStatus::OutOfStock,
            'https://schema.org/Reserved' => StockStatus::OutOfStock,
            'https://schema.org/PreOrder' => StockStatus::PreOrder,
            'https://schema.org/PreSale' => StockStatus::PreOrder,
            'https://schema.org/BackOrder' => StockStatus::BackOrder,
            'https://schema.org/MadeToOrder' => StockStatus::SpecialOrder,
            'https://schema.org/Discontinued' => StockStatus::Discontinued,
        ];

        foreach ($map as $url => $expected) {
            $this->assertSame($expected, StockStatus::fromSchemaOrgAvailability($url), $url);
            $this->assertSame($expected, StockStatus::fromSchemaOrgAvailability(substr($url, strlen('https://schema.org/'))));
        }
    }

    public function test_empty_availability_is_in_stock_and_unknown_is_out_of_stock(): void
    {
        $this->assertSame(StockStatus::InStock, StockStatus::fromSchemaOrgAvailability(null));
        $this->assertSame(StockStatus::InStock, StockStatus::fromSchemaOrgAvailability(''));
        $this->assertSame(StockStatus::OutOfStock, StockStatus::fromSchemaOrgAvailability('https://schema.org/Nonsense'));
    }

    public function test_resolve_availability_uses_schema_org_mapping_and_ignores_match_config(): void
    {
        $strategy = ['type' => 'schema_org', 'value' => null];

        $this->assertSame(StockStatus::InStock, StockStatus::resolveAvailability('https://schema.org/InStock', AvailabilityStrategyDto::fromArray($strategy)));
        $this->assertSame(StockStatus::OutOfStock, StockStatus::resolveAvailability('https://schema.org/OutOfStock', AvailabilityStrategyDto::fromArray($strategy)));

        $strategyWithMatch = ['type' => 'schema_org', 'match' => ['out_of_stock' => ['type' => 'match', 'value' => 'InStock']]];
        $this->assertSame(StockStatus::InStock, StockStatus::resolveAvailability('https://schema.org/InStock', AvailabilityStrategyDto::fromArray($strategyWithMatch)));
    }

    public function test_resolve_availability_delegates_to_match_for_non_schema_org(): void
    {
        $strategy = ['type' => 'selector', 'match' => ['out_of_stock' => ['type' => 'match', 'value' => 'Sold out']]];

        $this->assertSame(StockStatus::OutOfStock, StockStatus::resolveAvailability('Sold out', AvailabilityStrategyDto::fromArray($strategy)));
        $this->assertSame(StockStatus::InStock, StockStatus::resolveAvailability(null, AvailabilityStrategyDto::fromArray($strategy)));
        $this->assertSame(
            StockStatus::matchFromScrapedValue('Sold out', $strategy['match']),
            StockStatus::resolveAvailability('Sold out', AvailabilityStrategyDto::fromArray($strategy)),
        );
    }
}

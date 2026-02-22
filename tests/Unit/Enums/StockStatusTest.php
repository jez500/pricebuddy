<?php

namespace Tests\Unit\Enums;

use App\Enums\StockStatus;
use Tests\TestCase;

class StockStatusTest extends TestCase
{
    public function test_all_cases_have_correct_values()
    {
        $this->assertSame('in_stock', StockStatus::InStock->value);
        $this->assertSame('pre_order', StockStatus::PreOrder->value);
        $this->assertSame('back_order', StockStatus::BackOrder->value);
        $this->assertSame('special_order', StockStatus::SpecialOrder->value);
        $this->assertSame('out_of_stock', StockStatus::OutOfStock->value);
        $this->assertSame('discontinued', StockStatus::Discontinued->value);
    }

    public function test_all_cases_have_labels()
    {
        $this->assertSame('In Stock', StockStatus::InStock->getLabel());
        $this->assertSame('Pre-Order', StockStatus::PreOrder->getLabel());
        $this->assertSame('Back Order', StockStatus::BackOrder->getLabel());
        $this->assertSame('Special Order', StockStatus::SpecialOrder->getLabel());
        $this->assertSame('Out of Stock', StockStatus::OutOfStock->getLabel());
        $this->assertSame('Discontinued', StockStatus::Discontinued->getLabel());
    }

    public function test_all_cases_have_colors()
    {
        $this->assertSame('success', StockStatus::InStock->getColor());
        $this->assertSame('info', StockStatus::PreOrder->getColor());
        $this->assertSame('warning', StockStatus::BackOrder->getColor());
        $this->assertSame('warning', StockStatus::SpecialOrder->getColor());
        $this->assertSame('danger', StockStatus::OutOfStock->getColor());
        $this->assertSame('gray', StockStatus::Discontinued->getColor());
    }

    public function test_all_cases_have_icons()
    {
        $this->assertSame('heroicon-m-check-circle', StockStatus::InStock->getIcon());
        $this->assertSame('heroicon-m-clock', StockStatus::PreOrder->getIcon());
        $this->assertSame('heroicon-m-arrow-path', StockStatus::BackOrder->getIcon());
        $this->assertSame('heroicon-m-truck', StockStatus::SpecialOrder->getIcon());
        $this->assertSame('heroicon-m-x-circle', StockStatus::OutOfStock->getIcon());
        $this->assertSame('heroicon-m-archive-box-x-mark', StockStatus::Discontinued->getIcon());
    }

    public function test_all_cases_have_sort_orders()
    {
        $this->assertSame(0, StockStatus::InStock->getSortOrder());
        $this->assertSame(1, StockStatus::PreOrder->getSortOrder());
        $this->assertSame(2, StockStatus::BackOrder->getSortOrder());
        $this->assertSame(3, StockStatus::SpecialOrder->getSortOrder());
        $this->assertSame(4, StockStatus::OutOfStock->getSortOrder());
        $this->assertSame(5, StockStatus::Discontinued->getSortOrder());
    }

    public function test_is_unavailable_returns_false_for_in_stock()
    {
        $this->assertFalse(StockStatus::InStock->isUnavailable());
    }

    public function test_is_unavailable_returns_true_for_all_other_cases()
    {
        $this->assertTrue(StockStatus::PreOrder->isUnavailable());
        $this->assertTrue(StockStatus::BackOrder->isUnavailable());
        $this->assertTrue(StockStatus::SpecialOrder->isUnavailable());
        $this->assertTrue(StockStatus::OutOfStock->isUnavailable());
        $this->assertTrue(StockStatus::Discontinued->isUnavailable());
    }

    public function test_from_scraped_value_null_returns_in_stock()
    {
        $this->assertSame(StockStatus::InStock, StockStatus::fromScrapedValue(null));
    }

    public function test_from_scraped_value_empty_string_returns_in_stock()
    {
        $this->assertSame(StockStatus::InStock, StockStatus::fromScrapedValue(''));
    }

    public function test_from_scraped_value_maps_known_values()
    {
        $this->assertSame(StockStatus::OutOfStock, StockStatus::fromScrapedValue('OutOfStock'));
        $this->assertSame(StockStatus::PreOrder, StockStatus::fromScrapedValue('PreOrder'));
        $this->assertSame(StockStatus::BackOrder, StockStatus::fromScrapedValue('BackOrder'));
        $this->assertSame(StockStatus::SpecialOrder, StockStatus::fromScrapedValue('SpecialOrder'));
        $this->assertSame(StockStatus::Discontinued, StockStatus::fromScrapedValue('Discontinued'));
    }

    public function test_from_scraped_value_unknown_string_returns_out_of_stock()
    {
        $this->assertSame(StockStatus::OutOfStock, StockStatus::fromScrapedValue('SomeUnknownValue'));
        $this->assertSame(StockStatus::OutOfStock, StockStatus::fromScrapedValue('sold out'));
    }

    public function test_from_scraped_value_handles_enum_value_strings()
    {
        $this->assertSame(StockStatus::OutOfStock, StockStatus::fromScrapedValue('out_of_stock'));
        $this->assertSame(StockStatus::PreOrder, StockStatus::fromScrapedValue('pre_order'));
        $this->assertSame(StockStatus::BackOrder, StockStatus::fromScrapedValue('back_order'));
        $this->assertSame(StockStatus::SpecialOrder, StockStatus::fromScrapedValue('special_order'));
        $this->assertSame(StockStatus::Discontinued, StockStatus::fromScrapedValue('discontinued'));
        $this->assertSame(StockStatus::InStock, StockStatus::fromScrapedValue('in_stock'));
    }

    public function test_sort_order_is_sequential()
    {
        $cases = StockStatus::cases();
        $sortOrders = array_map(fn (StockStatus $case) => $case->getSortOrder(), $cases);

        $this->assertSame([0, 1, 2, 3, 4, 5], $sortOrders);
    }

    public function test_match_from_scraped_value_null_returns_in_stock()
    {
        $this->assertSame(StockStatus::InStock, StockStatus::matchFromScrapedValue(null, null));
        $this->assertSame(StockStatus::InStock, StockStatus::matchFromScrapedValue('', null));
        $this->assertSame(StockStatus::InStock, StockStatus::matchFromScrapedValue(null, 'OutOfStock'));
        $this->assertSame(StockStatus::InStock, StockStatus::matchFromScrapedValue('', ['out_of_stock' => 'OutOfStock']));
    }

    public function test_match_from_scraped_value_with_array_config()
    {
        $config = [
            'out_of_stock' => 'OutOfStock',
            'pre_order' => 'PreOrder',
            'discontinued' => 'Discontinued',
        ];

        $this->assertSame(StockStatus::OutOfStock, StockStatus::matchFromScrapedValue('OutOfStock', $config));
        $this->assertSame(StockStatus::PreOrder, StockStatus::matchFromScrapedValue('PreOrder', $config));
        $this->assertSame(StockStatus::Discontinued, StockStatus::matchFromScrapedValue('Discontinued', $config));
        $this->assertSame(StockStatus::InStock, StockStatus::matchFromScrapedValue('InStock', $config));
        $this->assertSame(StockStatus::InStock, StockStatus::matchFromScrapedValue('SomethingElse', $config));
    }

    public function test_match_from_scraped_value_with_legacy_string_config()
    {
        $this->assertSame(StockStatus::OutOfStock, StockStatus::matchFromScrapedValue('OutOfStock', 'OutOfStock'));
        $this->assertSame(StockStatus::InStock, StockStatus::matchFromScrapedValue('PreOrder', 'OutOfStock'));
    }

    public function test_match_from_scraped_value_with_null_config_fallback()
    {
        $this->assertSame(StockStatus::OutOfStock, StockStatus::matchFromScrapedValue('anything', null));
        $this->assertSame(StockStatus::OutOfStock, StockStatus::matchFromScrapedValue('OutOfStock', null));
    }

    public function test_match_from_scraped_value_skips_empty_entries()
    {
        $config = [
            'out_of_stock' => 'OutOfStock',
            'pre_order' => '',
            'discontinued' => null,
        ];

        $this->assertSame(StockStatus::OutOfStock, StockStatus::matchFromScrapedValue('OutOfStock', $config));
        $this->assertSame(StockStatus::InStock, StockStatus::matchFromScrapedValue('PreOrder', $config));
        $this->assertSame(StockStatus::InStock, StockStatus::matchFromScrapedValue('Discontinued', $config));
    }

    public function test_match_from_scraped_value_with_regex_match()
    {
        $config = [
            'out_of_stock' => ['type' => 'regex', 'value' => 'Sold out'],
        ];

        $this->assertSame(StockStatus::OutOfStock, StockStatus::matchFromScrapedValue('Sold out', $config));
        $this->assertSame(StockStatus::OutOfStock, StockStatus::matchFromScrapedValue('SOLD OUT', $config));
        $this->assertSame(StockStatus::OutOfStock, StockStatus::matchFromScrapedValue('This item is Sold out now', $config));
        $this->assertSame(StockStatus::InStock, StockStatus::matchFromScrapedValue('In Stock', $config));
    }

    public function test_match_from_scraped_value_regex_first_match_wins()
    {
        $config = [
            'special_order' => ['type' => 'regex', 'value' => 'Special Order'],
            'out_of_stock' => ['type' => 'regex', 'value' => 'Sold out'],
        ];

        // Both patterns match, but special_order is iterated first so it wins.
        $this->assertSame(
            StockStatus::SpecialOrder,
            StockStatus::matchFromScrapedValue('Sold out - We May Be Able To Special Order', $config)
        );
    }

    public function test_match_from_scraped_value_mixed_regex_and_exact()
    {
        $config = [
            'pre_order' => ['type' => 'match', 'value' => 'Pre-Order'],
            'out_of_stock' => ['type' => 'regex', 'value' => 'sold out'],
        ];

        $this->assertSame(StockStatus::PreOrder, StockStatus::matchFromScrapedValue('Pre-Order', $config));
        $this->assertSame(StockStatus::OutOfStock, StockStatus::matchFromScrapedValue('Sold Out', $config));
        $this->assertSame(StockStatus::InStock, StockStatus::matchFromScrapedValue('Available', $config));
    }

    public function test_match_from_scraped_value_invalid_regex_does_not_match()
    {
        $config = [
            'out_of_stock' => ['type' => 'regex', 'value' => '[invalid'],
        ];

        $this->assertSame(StockStatus::InStock, StockStatus::matchFromScrapedValue('anything', $config));
    }

    public function test_match_from_scraped_value_empty_config_uses_default()
    {
        // All entries empty → uses default (InStock when no default key).
        $config = [
            'out_of_stock' => ['type' => 'regex', 'value' => ''],
            'pre_order' => ['type' => 'match', 'value' => ''],
        ];

        $this->assertSame(StockStatus::InStock, StockStatus::matchFromScrapedValue('anything', $config));

        // Same for legacy empty entries.
        $this->assertSame(StockStatus::InStock, StockStatus::matchFromScrapedValue('anything', [
            'out_of_stock' => '',
            'pre_order' => null,
        ]));

        // With explicit default.
        $config['default'] = 'out_of_stock';
        $this->assertSame(StockStatus::OutOfStock, StockStatus::matchFromScrapedValue('anything', $config));
    }

    public function test_match_from_scraped_value_defaults_to_match_type()
    {
        $config = [
            'out_of_stock' => ['value' => 'OutOfStock'],
        ];

        $this->assertSame(StockStatus::OutOfStock, StockStatus::matchFromScrapedValue('OutOfStock', $config));
        $this->assertSame(StockStatus::InStock, StockStatus::matchFromScrapedValue('out of stock', $config));
    }

    public function test_match_from_scraped_value_uses_configurable_default()
    {
        // Default to OutOfStock when no match values match.
        $config = [
            'default' => 'out_of_stock',
            'pre_order' => ['type' => 'match', 'value' => 'Pre-Order'],
        ];

        $this->assertSame(StockStatus::PreOrder, StockStatus::matchFromScrapedValue('Pre-Order', $config));
        $this->assertSame(StockStatus::OutOfStock, StockStatus::matchFromScrapedValue('something else', $config));

        // Default to InStock (explicit).
        $config['default'] = 'in_stock';
        $this->assertSame(StockStatus::InStock, StockStatus::matchFromScrapedValue('something else', $config));

        // No default key → defaults to InStock.
        unset($config['default']);
        $this->assertSame(StockStatus::InStock, StockStatus::matchFromScrapedValue('something else', $config));
    }

    public function test_non_in_stock_cases_returns_five_cases()
    {
        $cases = StockStatus::nonInStockCases();

        $this->assertCount(5, $cases);
        $this->assertNotContains(StockStatus::InStock, $cases);
        $this->assertContains(StockStatus::PreOrder, $cases);
        $this->assertContains(StockStatus::BackOrder, $cases);
        $this->assertContains(StockStatus::SpecialOrder, $cases);
        $this->assertContains(StockStatus::OutOfStock, $cases);
        $this->assertContains(StockStatus::Discontinued, $cases);
    }
}

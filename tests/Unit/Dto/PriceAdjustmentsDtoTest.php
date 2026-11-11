<?php

namespace Tests\Unit\Dto;

use App\Dto\AdjustedPriceDto;
use App\Dto\PriceAdjustmentsDto;
use Tests\TestCase;

class PriceAdjustmentsDtoTest extends TestCase
{
    public function test_no_settings_leave_the_price_as_it_is(): void
    {
        $settings = PriceAdjustmentsDto::fromArray(null);

        $this->assertTrue($settings->isEmpty());
        $this->assertSame(324.80, $settings->apply(324.80)->total);
    }

    public function test_equal_tax_rates_change_nothing(): void
    {
        $this->assertTrue(PriceAdjustmentsDto::fromArray(['tax_included_percent' => 23, 'tax_percent' => 23])->isEmpty());
    }

    public function test_swaps_the_page_vat_for_the_buyers_vat_and_adds_shipping(): void
    {
        // Amazon.de shows German VAT (19%); delivered to Portugal, checkout charges 23%
        // (€335.72) plus €7.65 shipping.
        $adjusted = PriceAdjustmentsDto::fromArray([
            'tax_included_percent' => 19,
            'tax_percent' => 23,
            'shipping_cost' => 7.65,
        ])->apply(324.80);

        $this->assertSame(343.37, $adjusted->total);
        $this->assertSame(10.92, $adjusted->tax);
        $this->assertSame(7.65, $adjusted->shipping);
        $this->assertFalse($adjusted->shippingFromPage);
        $this->assertTrue($adjusted->isAdjusted());
    }

    public function test_tax_on_shipping_taxes_the_shipping_too(): void
    {
        $settings = ['tax_percent' => 23, 'shipping_cost' => 20];

        // Off: shipping is added as entered.
        $this->assertSame(389.00, PriceAdjustmentsDto::fromArray($settings)->apply(300)->total);

        // On: import VAT on the goods and the delivery.
        $adjusted = PriceAdjustmentsDto::fromArray($settings + ['tax_on_shipping' => true])->apply(300);

        $this->assertSame(393.60, $adjusted->total);
        $this->assertSame(73.60, $adjusted->tax);
        $this->assertSame(20.0, $adjusted->shipping);
    }

    public function test_removing_tax_gives_a_negative_tax_part(): void
    {
        $adjusted = PriceAdjustmentsDto::fromArray(['tax_included_percent' => 23])->apply(123);

        $this->assertSame(100.0, $adjusted->total);
        $this->assertSame(-23.0, $adjusted->tax);
    }

    public function test_shipping_from_the_page_replaces_the_flat_fee(): void
    {
        $settings = PriceAdjustmentsDto::fromArray(['shipping_cost' => 7.65]);

        $paid = $settings->apply(100, 4.99);
        $free = $settings->apply(100, 0.0);

        $this->assertSame(104.99, $paid->total);
        $this->assertTrue($paid->shippingFromPage);
        $this->assertSame(100.0, $free->total);
        $this->assertTrue($free->shippingFromPage);
        $this->assertFalse($free->isAdjusted());
    }

    public function test_parse_shipping(): void
    {
        $this->assertSame(7.65, PriceAdjustmentsDto::parseShipping('€7.65', 'en_US', 'EUR'));
        $this->assertSame(7.65, PriceAdjustmentsDto::parseShipping('7,65 €', 'pt_PT', 'EUR'));
        $this->assertSame(0.0, PriceAdjustmentsDto::parseShipping('FREE'));
        $this->assertSame(0.0, PriceAdjustmentsDto::parseShipping('Envío gratis'));
        $this->assertNull(PriceAdjustmentsDto::parseShipping(''));
        $this->assertNull(PriceAdjustmentsDto::parseShipping(null));
    }

    public function test_storage_round_trip(): void
    {
        $adjusted = PriceAdjustmentsDto::fromArray(['tax_included_percent' => 19, 'tax_percent' => 23])->apply(324.80, 7.65);

        $restored = AdjustedPriceDto::fromStorage($adjusted->toStorageArray(), 324.80, 343.37);

        $this->assertEquals($adjusted, $restored);
        $this->assertNull(AdjustedPriceDto::fromStorage(null, 10, 10));
    }

    public function test_describe_leaves_out_zero_parts(): void
    {
        $this->assertSame(
            '€324.80 + €10.92 tax + €7.65 shipping',
            AdjustedPriceDto::describeParts(324.80, 10.92, 7.65, 'en_US', 'EUR'),
        );
        $this->assertSame('€123.00 − €23.00 tax', AdjustedPriceDto::describeParts(123, -23, 0, 'en_US', 'EUR'));
        $this->assertSame('€100.00 + €4.99 shipping', AdjustedPriceDto::describeParts(100, 0, 4.99, 'en_US', 'EUR'));
    }
}

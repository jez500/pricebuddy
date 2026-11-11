<?php

namespace Tests\Unit\Dto;

use App\Dto\PriceCacheDto;
use App\Enums\StockStatus;
use App\Enums\Trend;
use Tests\TestCase;

class PriceCacheDtoTest extends TestCase
{
    public function test_unit_of_measure_getter()
    {
        $dto = new PriceCacheDto(
            price: 10.00,
            locale: 'en',
            currency: 'USD',
            unitPrice: 5.00,
            priceFactor: 2,
            unitOfMeasure: 'tablets',
        );

        $this->assertSame('tablets', $dto->getUnitOfMeasurePlural());
        $this->assertSame('tablet', $dto->getUnitOfMeasure());
    }

    public function test_unit_of_measure_unit_by_default()
    {
        $dto = new PriceCacheDto(
            price: 10.00,
            locale: 'en',
            currency: 'USD',
            unitPrice: 10.00,
        );

        $this->assertSame(PriceCacheDto::DEFAULT_UNIT_OF_MEASURE, $dto->getUnitOfMeasurePlural());
    }

    public function test_from_array_includes_unit_of_measure()
    {
        $dto = PriceCacheDto::fromArray([
            'price' => 10.00,
            'history' => [],
            'unit_of_measure' => 'items',
            'locale' => 'en',
            'currency' => 'USD',
        ]);

        $this->assertSame('item', $dto->getUnitOfMeasure());
        $this->assertSame('items', $dto->getUnitOfMeasurePlural());
    }

    public function test_to_array_includes_unit_of_measure()
    {
        $dto = new PriceCacheDto(
            price: 10.00,
            locale: 'en',
            currency: 'USD',
            unitOfMeasure: 'grams',
        );

        $array = $dto->toArray();
        $this->assertSame('gram', $array['unit_of_measure']);
    }

    public function test_from_array_without_unit_of_measure_defaults_to_unit()
    {
        $dto = PriceCacheDto::fromArray([
            'price' => 10.00,
            'history' => [],
            'locale' => 'en',
            'currency' => 'USD',
        ]);

        $this->assertSame('unit', $dto->getUnitOfMeasure());
    }

    public function test_availability_defaults_to_in_stock()
    {
        $dto = new PriceCacheDto(price: 10.0, locale: 'en', currency: 'USD');

        $this->assertFalse($dto->isUnavailable());
        $this->assertSame(StockStatus::InStock, $dto->getStockStatus());
    }

    public function test_availability_can_be_set_via_constructor()
    {
        $dto = new PriceCacheDto(price: 10.0, availability: StockStatus::OutOfStock, locale: 'en', currency: 'USD');

        $this->assertTrue($dto->isUnavailable());
        $this->assertSame(StockStatus::OutOfStock, $dto->getStockStatus());
    }

    public function test_from_array_includes_availability()
    {
        $dto = PriceCacheDto::fromArray([
            'price' => 10.0,
            'history' => [],
            'availability' => StockStatus::OutOfStock,
            'locale' => 'en',
            'currency' => 'USD',
        ]);

        $this->assertTrue($dto->isUnavailable());
        $this->assertSame(StockStatus::OutOfStock, $dto->getStockStatus());
    }

    public function test_from_array_defaults_availability_to_in_stock()
    {
        $dto = PriceCacheDto::fromArray([
            'price' => 10.0,
            'history' => [],
            'locale' => 'en',
            'currency' => 'USD',
        ]);

        $this->assertFalse($dto->isUnavailable());
        $this->assertSame(StockStatus::InStock, $dto->getStockStatus());
    }

    public function test_to_array_includes_availability()
    {
        $dto = new PriceCacheDto(price: 10.0, availability: StockStatus::OutOfStock, locale: 'en', currency: 'USD');
        $array = $dto->toArray();

        $this->assertArrayHasKey('availability', $array);
        $this->assertSame(StockStatus::OutOfStock->value, $array['availability']);
    }

    public function test_to_array_availability_null_by_default()
    {
        $dto = new PriceCacheDto(price: 10.0, locale: 'en', currency: 'USD');
        $array = $dto->toArray();

        $this->assertArrayHasKey('availability', $array);
        $this->assertNull($array['availability']);
    }

    public function test_from_array_to_array_preserves_availability()
    {
        $data = [
            'price' => 25.0,
            'store_id' => 1,
            'store_name' => 'Test Store',
            'url_id' => 1,
            'url' => 'https://example.com',
            'trend' => Trend::None->value,
            'history' => [10.0, 20.0, 25.0],
            'availability' => StockStatus::OutOfStock,
            'locale' => 'en',
            'currency' => 'USD',
        ];

        $dto = PriceCacheDto::fromArray($data);
        $array = $dto->toArray();

        $this->assertSame(StockStatus::OutOfStock->value, $array['availability']);
        $this->assertSame(25.0, $array['price']);
        $this->assertTrue($dto->isUnavailable());
    }

    public function test_stock_status_convenience_methods()
    {
        $dto = new PriceCacheDto(price: 10.0, availability: StockStatus::OutOfStock, locale: 'en', currency: 'USD');

        $this->assertSame('Out of Stock', $dto->getStockStatusLabel());
        $this->assertSame('danger', $dto->getStockStatusColor());
        $this->assertSame('heroicon-m-x-circle', $dto->getStockStatusIcon());
    }

    public function test_stock_status_convenience_methods_for_in_stock()
    {
        $dto = new PriceCacheDto(price: 10.0, locale: 'en', currency: 'USD');

        $this->assertSame('In Stock', $dto->getStockStatusLabel());
        $this->assertSame('success', $dto->getStockStatusColor());
        $this->assertSame('heroicon-m-check-circle', $dto->getStockStatusIcon());
    }

    public function test_pre_order_scraped_value_maps_correctly()
    {
        $dto = new PriceCacheDto(price: 10.0, availability: StockStatus::PreOrder, locale: 'en', currency: 'USD');

        $this->assertTrue($dto->isUnavailable());
        $this->assertSame(StockStatus::PreOrder, $dto->getStockStatus());
        $this->assertSame('Pre-Order', $dto->getStockStatusLabel());
        $this->assertSame('info', $dto->getStockStatusColor());
    }

    public function test_from_array_with_enum_value_string()
    {
        $dto = PriceCacheDto::fromArray([
            'price' => 10.0,
            'history' => [],
            'availability' => StockStatus::OutOfStock->value,
            'locale' => 'en',
            'currency' => 'USD',
        ]);

        $this->assertTrue($dto->isUnavailable());
        $this->assertSame(StockStatus::OutOfStock, $dto->getStockStatus());
    }

    public function test_unavailable_url_is_considered_successfully_scraped_despite_stale_price_row()
    {
        // Regression: OOS URLs don't get new price rows inserted by
        // Url::updatePrice, so last_scrape lags arbitrarily far behind.
        // The "Scrape error" badge in the product UI is gated on
        // isLastScrapeSuccessful(); without this guard an OOS product is
        // permanently flagged as a scrape failure.
        $dto = PriceCacheDto::fromArray([
            'price' => 10.0,
            'history' => [],
            'availability' => StockStatus::OutOfStock->value,
            'last_scrape' => now()->subMonths(3)->toDateTimeString(),
            'locale' => 'en',
            'currency' => 'USD',
        ]);

        $this->assertTrue($dto->isUnavailable());
        $this->assertTrue($dto->isLastScrapeSuccessful());
    }

    public function test_in_stock_url_with_stale_price_row_is_unsuccessful()
    {
        $dto = PriceCacheDto::fromArray([
            'price' => 10.0,
            'history' => [],
            'last_scrape' => now()->subDays(2)->toDateTimeString(),
            'locale' => 'en',
            'currency' => 'USD',
        ]);

        $this->assertFalse($dto->isUnavailable());
        $this->assertFalse($dto->isLastScrapeSuccessful());
    }

    public function test_in_stock_url_with_recent_price_row_is_successful()
    {
        $dto = PriceCacheDto::fromArray([
            'price' => 10.0,
            'history' => [],
            'last_scrape' => now()->subHours(2)->toDateTimeString(),
            'locale' => 'en',
            'currency' => 'USD',
        ]);

        $this->assertTrue($dto->isLastScrapeSuccessful());
    }

    public function test_price_breakdown_text(): void
    {
        $dto = new PriceCacheDto(price: 343.37, locale: 'en_US', currency: 'EUR', priceBreakdown: ['page' => 324.80, 'tax' => 10.92, 'shipping' => 7.65]);

        $this->assertSame('€324.80 + €10.92 tax + €7.65 shipping', $dto->getPriceBreakdownText());
        $this->assertNull((new PriceCacheDto(price: 10))->getPriceBreakdownText());
    }

    public function test_price_breakdown_with_a_price_factor_adds_up_to_the_pack_price(): void
    {
        // The shown price is €12 per tablet; the breakdown is for the pack.
        $dto = new PriceCacheDto(
            price: 36,
            locale: 'en_US',
            currency: 'EUR',
            unitPrice: 12,
            priceFactor: 3,
            unitOfMeasure: 'tablets',
            priceBreakdown: ['page' => 30, 'tax' => 0, 'shipping' => 6, 'shipping_not_counted' => false],
        );

        $this->assertSame('€30.00 + €6.00 shipping = €36.00 for 3 tablets', $dto->getPriceBreakdownText());

        $ignored = new PriceCacheDto(
            price: 36.9,
            locale: 'en_US',
            currency: 'EUR',
            unitPrice: 12.3,
            priceFactor: 3,
            unitOfMeasure: 'tablets',
            priceBreakdown: ['page' => 30, 'tax' => 6.9, 'shipping' => 0, 'shipping_not_counted' => true],
        );

        $this->assertSame('€30.00 + €6.90 tax = €36.90 for 3 tablets · shipping not counted', $ignored->getPriceBreakdownText());
    }
}

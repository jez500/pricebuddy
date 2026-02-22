<?php

namespace Tests\Unit\Dto;

use App\Dto\PriceCacheDto;
use App\Services\Helpers\SettingsHelper;
use Illuminate\Support\Facades\Artisan;
use App\Enums\StockStatus;
use App\Enums\Trend;
use Tests\TestCase;

class PriceCacheDtoTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate');
        SettingsHelper::setSetting('default_locale_settings', ['locale' => 'en', 'currency' => 'USD']);
    }

    public function test_unit_of_measure_getter()
    {
        $dto = new PriceCacheDto(
            price: 10.00,
            unitPrice: 5.00,
            priceFactor: 2,
            unitOfMeasure: 'tablets',
        );

        $this->assertSame('tablets', $dto->getUnitOfMeasure());
    }

    public function test_unit_of_measure_null_by_default()
    {
        $dto = new PriceCacheDto(
            price: 10.00,
            unitPrice: 10.00,
        );

        $this->assertNull($dto->getUnitOfMeasure());
    }

    public function test_from_array_includes_unit_of_measure()
    {
        $dto = PriceCacheDto::fromArray([
            'price' => 10.00,
            'history' => [],
            'unit_of_measure' => 'item',
        ]);

        $this->assertSame('item', $dto->getUnitOfMeasure());
    }

    public function test_to_array_includes_unit_of_measure()
    {
        $dto = new PriceCacheDto(
            price: 10.00,
            unitOfMeasure: 'grams',
        );

        $array = $dto->toArray();
        $this->assertSame('grams', $array['unit_of_measure']);
    }

    public function test_from_array_without_unit_of_measure_defaults_to_null()
    {
        $dto = PriceCacheDto::fromArray([
            'price' => 10.00,
            'history' => [],
        ]);

        $this->assertNull($dto->getUnitOfMeasure());
    }

    public function test_availability_defaults_to_in_stock()
    {
        $dto = new PriceCacheDto(price: 10.0);

        $this->assertFalse($dto->isOutOfStock());
        $this->assertSame(StockStatus::InStock, $dto->getStockStatus());
    }

    public function test_availability_can_be_set_via_constructor()
    {
        $dto = new PriceCacheDto(price: 10.0, availability: 'OutOfStock');

        $this->assertTrue($dto->isOutOfStock());
        $this->assertSame(StockStatus::OutOfStock, $dto->getStockStatus());
    }

    public function test_from_array_includes_availability()
    {
        $dto = PriceCacheDto::fromArray([
            'price' => 10.0,
            'history' => [],
            'availability' => 'OutOfStock',
        ]);

        $this->assertTrue($dto->isOutOfStock());
        $this->assertSame(StockStatus::OutOfStock, $dto->getStockStatus());
    }

    public function test_from_array_defaults_availability_to_in_stock()
    {
        $dto = PriceCacheDto::fromArray([
            'price' => 10.0,
            'history' => [],
        ]);

        $this->assertFalse($dto->isOutOfStock());
        $this->assertSame(StockStatus::InStock, $dto->getStockStatus());
    }

    public function test_to_array_includes_availability()
    {
        $dto = new PriceCacheDto(price: 10.0, availability: 'OutOfStock');
        $array = $dto->toArray();

        $this->assertArrayHasKey('availability', $array);
        $this->assertSame(StockStatus::OutOfStock->value, $array['availability']);
    }

    public function test_to_array_availability_null_by_default()
    {
        $dto = new PriceCacheDto(price: 10.0);
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
            'availability' => 'OutOfStock',
        ];

        $dto = PriceCacheDto::fromArray($data);
        $array = $dto->toArray();

        $this->assertSame(StockStatus::OutOfStock->value, $array['availability']);
        $this->assertSame(25.0, $array['price']);
        $this->assertTrue($dto->isOutOfStock());
    }

    public function test_stock_status_convenience_methods()
    {
        $dto = new PriceCacheDto(price: 10.0, availability: 'OutOfStock');

        $this->assertSame('Out of Stock', $dto->getStockStatusLabel());
        $this->assertSame('danger', $dto->getStockStatusColor());
        $this->assertSame('heroicon-m-x-circle', $dto->getStockStatusIcon());
    }

    public function test_stock_status_convenience_methods_for_in_stock()
    {
        $dto = new PriceCacheDto(price: 10.0);

        $this->assertSame('In Stock', $dto->getStockStatusLabel());
        $this->assertSame('success', $dto->getStockStatusColor());
        $this->assertSame('heroicon-m-check-circle', $dto->getStockStatusIcon());
    }

    public function test_pre_order_scraped_value_maps_correctly()
    {
        $dto = new PriceCacheDto(price: 10.0, availability: 'PreOrder');

        $this->assertTrue($dto->isOutOfStock());
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
        ]);

        $this->assertTrue($dto->isOutOfStock());
        $this->assertSame(StockStatus::OutOfStock, $dto->getStockStatus());
    }
}

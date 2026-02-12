<?php

namespace Tests\Unit\Dto;

use App\Dto\PriceCacheDto;
use App\Services\Helpers\SettingsHelper;
use Illuminate\Support\Facades\Artisan;
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
            factor: 2,
            unitOfMeasure: 'tablet',
        );

        $this->assertSame('tablet', $dto->getUnitOfMeasure());
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
}

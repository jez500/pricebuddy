<?php

namespace Tests\Unit\Enums;

use App\Enums\ProductSourceStatus;
use PHPUnit\Framework\TestCase;

class ProductSourceStatusTest extends TestCase
{
    public function test_has_correct_cases(): void
    {
        $cases = ProductSourceStatus::cases();

        $this->assertCount(3, $cases);
        $this->assertInstanceOf(ProductSourceStatus::class, ProductSourceStatus::Active);
        $this->assertInstanceOf(ProductSourceStatus::class, ProductSourceStatus::Inactive);
        $this->assertInstanceOf(ProductSourceStatus::class, ProductSourceStatus::Draft);
    }

    public function test_has_correct_values(): void
    {
        $this->assertSame('active', ProductSourceStatus::Active->value);
        $this->assertSame('inactive', ProductSourceStatus::Inactive->value);
        $this->assertSame('draft', ProductSourceStatus::Draft->value);
    }

    public function test_returns_correct_labels(): void
    {
        $this->assertSame('Active', ProductSourceStatus::Active->getLabel());
        $this->assertSame('Inactive', ProductSourceStatus::Inactive->getLabel());
        $this->assertSame('Draft', ProductSourceStatus::Draft->getLabel());
    }

    public function test_returns_correct_colors(): void
    {
        $this->assertSame('success', ProductSourceStatus::Active->getColor());
        $this->assertSame('danger', ProductSourceStatus::Inactive->getColor());
        $this->assertSame('gray', ProductSourceStatus::Draft->getColor());
    }
}

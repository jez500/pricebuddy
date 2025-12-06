<?php

namespace Tests\Unit\Enums;

use App\Enums\ProductSourceType;
use PHPUnit\Framework\TestCase;

class ProductSourceTypeTest extends TestCase
{
    public function test_has_correct_cases(): void
    {
        $cases = ProductSourceType::cases();

        $this->assertCount(2, $cases);
        $this->assertInstanceOf(ProductSourceType::class, ProductSourceType::DealsSite);
        $this->assertInstanceOf(ProductSourceType::class, ProductSourceType::OnlineStore);
    }

    public function test_has_correct_values(): void
    {
        $this->assertSame('deals_site', ProductSourceType::DealsSite->value);
        $this->assertSame('online_store', ProductSourceType::OnlineStore->value);
    }

    public function test_returns_correct_labels(): void
    {
        $this->assertSame('Deals Site (Aggregator)', ProductSourceType::DealsSite->getLabel());
        $this->assertSame('Online Store', ProductSourceType::OnlineStore->getLabel());
    }

    public function test_returns_correct_descriptions(): void
    {
        $this->assertSame('Site that aggregates deals/links (e.g. OzBargain)', ProductSourceType::DealsSite->getDescription());
        $this->assertSame('Site that sells products directly (e.g. Amazon)', ProductSourceType::OnlineStore->getDescription());
    }
}

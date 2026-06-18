<?php

namespace Tests\Unit\Services;

use App\Services\AutoCreateStore;
use Tests\TestCase;

class AutoCreateStoreMicrodataTest extends TestCase
{
    private function microdataHtml(): string
    {
        return '<html><body><div itemscope itemtype="https://schema.org/Product">'
            .'<h1 itemprop="name">Premium Leather Boots</h1>'
            .'<div itemprop="offers" itemscope itemtype="https://schema.org/Offer">'
            .'<span itemprop="price" content="149.99">149.99</span>'
            .'</div></div></body></html>';
    }

    public function test_detects_a_store_strategy_from_microdata(): void
    {
        $detected = AutoCreateStore::new('https://shop.test/boots', $this->microdataHtml())
            ->setLogErrors(false)
            ->detect();

        $this->assertNotNull($detected);
        $this->assertSame('schema_org', data_get($detected, 'fields.title.type'));
        $this->assertSame('schema_org', data_get($detected, 'fields.price.type'));
        $this->assertSame('Premium Leather Boots', data_get($detected, 'extracted.title'));
        $this->assertSame(149.99, data_get($detected, 'extracted.price'));
    }
}

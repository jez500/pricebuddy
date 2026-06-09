<?php

namespace Tests\Unit\Services;

use App\Services\AutoCreateStore;
use Tests\TestCase;

class AutoCreateStoreBuildAttributesTest extends TestCase
{
    public function test_builds_store_attributes_from_url_and_strategy(): void
    {
        $strategy = ['title' => ['type' => 'selector', 'value' => '.t']];

        $attrs = AutoCreateStore::buildAttributes('https://www.shop.test/product/123', $strategy);

        $this->assertSame([['domain' => 'shop.test'], ['domain' => 'www.shop.test']], $attrs['domains']);
        $this->assertSame('Shop.test', $attrs['name']);
        $this->assertSame($strategy, $attrs['scrape_strategy']);
        $this->assertSame('https://www.shop.test/product/123', $attrs['settings']['test_url']);
        $this->assertSame('http', $attrs['settings']['scraper_service']);
        $this->assertArrayHasKey('locale', $attrs['settings']['locale_settings']);
        $this->assertArrayHasKey('currency', $attrs['settings']['locale_settings']);
    }
}

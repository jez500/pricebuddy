<?php

namespace Tests\Unit\Services;

use App\Enums\ScraperService;
use App\Services\AutoCreateStore;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AutoCreateStoreTest extends TestCase
{
    protected string $testUrl = 'http://example.com?product=1';

    protected string $html = '';

    public function test_get_store_attributes()
    {
        $this->fakeResponse('basic-meta');
        $autoCreateStore = new AutoCreateStore($this->testUrl, $this->html);
        $attributes = $autoCreateStore->getStoreAttributes();

        $this->assertSame('Example.com', data_get($attributes, 'name'));
        $this->assertSame(ScraperService::Http->value, data_get($attributes, 'settings.scraper_service'));

        $this->assertCount(2, $attributes['domains']);
        $this->assertSame('example.com', data_get($attributes, 'domains.0.domain'));

        $this->assertCount(3, $attributes['scrape_strategy']);
        $this->assertSame('meta[property="og:title"]|content', data_get($attributes, 'scrape_strategy.title.value'));
        $this->assertArrayHasKey('price', $attributes['scrape_strategy']);
        $this->assertArrayHasKey('image', $attributes['scrape_strategy']);
    }

    public function test_rule_parse_basic_meta()
    {
        $this->fakeResponse('basic-meta');
        $autoCreateStore = new AutoCreateStore($this->testUrl, $this->html);

        $this->assertEquals([
            'title' => [
                'type' => 'selector',
                'value' => 'meta[property="og:title"]|content',
                'data' => 'My product',
            ],
            'price' => [
                'type' => 'selector',
                'value' => 'meta[property="product:price:amount"]|content',
                'data' => '35.00',
            ],
            'image' => [
                'type' => 'selector',
                'value' => 'meta[property="og:image"]|content',
                'data' => 'http://localhost/my-image.jpg',
            ],
        ], $autoCreateStore->strategyParse());
    }

    public function test_rule_parse_basic_meta_secure_image()
    {
        $this->fakeResponse('basic-meta-secure-image');
        $autoCreateStore = new AutoCreateStore($this->testUrl, $this->html);

        $this->assertEquals([
            'title' => [
                'type' => 'selector',
                'value' => 'meta[property="og:title"]|content',
                'data' => 'My product',
            ],
            'price' => [
                'type' => 'selector',
                'value' => 'meta[property="product:price:amount"]|content',
                'data' => '35.00',
            ],
            'image' => [
                'type' => 'selector',
                'value' => 'meta[property="og:image:secure_url"]|content',
                'data' => 'http://localhost/my-image.jpg',
            ],
        ], $autoCreateStore->strategyParse());
    }

    public function test_rule_parse_unstructured_selector_1()
    {
        $this->fakeResponse('unstructured-selector-1');
        $autoCreateStore = new AutoCreateStore($this->testUrl, $this->html);

        $this->assertEquals([
            'title' => [
                'type' => 'selector',
                'value' => 'h1',
                'data' => 'My product',
            ],
            'price' => [
                'type' => 'selector',
                'value' => '[class^="price"]',
                'data' => '35.00',
            ],
            'image' => [
                'type' => 'regex',
                'value' => '~\"hiRes\":\"(.+?)\"~',
                'data' => 'http://localhost/my-image.jpg',
            ],
        ], $autoCreateStore->strategyParse());
    }

    public function test_rule_parse_unstructured_regex_1()
    {
        $this->fakeResponse('unstructured-regex-1');
        $autoCreateStore = new AutoCreateStore($this->testUrl, $this->html);

        $this->assertEquals([
            'title' => [
                'type' => 'selector',
                'value' => 'h1',
                'data' => 'My product',
            ],
            'price' => [
                'type' => 'regex',
                'value' => '~>\$(\d+(\.\d{2})?)<~',
                'data' => '35.00',
            ],
            'image' => [
                'type' => 'selector',
                'value' => 'meta[property="og:image"]|content',
                'data' => 'http://localhost/my-image.jpg',
            ],
        ], $autoCreateStore->strategyParse());
    }

    public function test_rule_parse_unstructured_regex_2()
    {
        $this->fakeResponse('unstructured-regex-2');
        $autoCreateStore = new AutoCreateStore($this->testUrl, $this->html);

        $this->assertEquals([
            'title' => [
                'type' => 'selector',
                'value' => 'h1',
                'data' => 'My product',
            ],
            'price' => [
                'type' => 'regex',
                'value' => '~\$(\d+(\.\d{2})?)~',
                'data' => '35.00',
            ],
            'image' => [
                'type' => 'selector',
                'value' => 'meta[property="og:image"]|content',
                'data' => 'http://localhost/my-image.jpg',
            ],
        ], $autoCreateStore->strategyParse());
    }

    public function test_rule_parse_unstructured_regex_3()
    {
        $this->fakeResponse('unstructured-regex-3');
        $autoCreateStore = new AutoCreateStore($this->testUrl, $this->html);

        $this->assertEquals([
            'title' => [
                'type' => 'selector',
                'value' => 'h1',
                'data' => 'My product',
            ],
            'price' => [
                'type' => 'regex',
                'value' => '~\$(\d+(\.\d{2})?)~',
                'data' => '35.00',
            ],
            'image' => [
                'type' => 'selector',
                'value' => 'meta[property="og:image"]|content',
                'data' => 'http://localhost/my-image.jpg',
            ],
        ], $autoCreateStore->strategyParse());
    }

    public function test_rule_parse_unstructured_store_amazon()
    {
        $this->fakeResponse('unstructured-store-amazon');
        $autoCreateStore = new AutoCreateStore($this->testUrl, $this->html);

        $this->assertEquals([
            'title' => [
                'type' => 'selector',
                'value' => 'title',
                'data' => 'My amazon product',
            ],
            'price' => [
                'type' => 'selector',
                'value' => '.a-price .a-offscreen',
                'data' => '35.00',
            ],
            'image' => [
                'type' => 'regex',
                'value' => '~\"hiRes\":\"(.+?)\"~',
                'data' => 'http://localhost/my-image.jpg',
            ],
        ], $autoCreateStore->strategyParse());
    }

    protected function getHtml(string $name): string
    {
        return file_get_contents(__DIR__.'/../../Fixtures/AutoCreateStore/'.$name.'.html');
    }

    protected function fakeResponse(string $name, string $domain = 'example.com*'): void
    {
        $this->html = $this->getHtml($name);

        Http::fake([
            $domain => Http::response($this->html),
        ]);
    }
}

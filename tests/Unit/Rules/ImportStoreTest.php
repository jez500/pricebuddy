<?php

namespace Tests\Unit\Rules;

use App\Enums\ScraperService;
use App\Rules\ImportStore;
use Tests\TestCase;

class ImportStoreTest extends TestCase
{
    public function test_validates_valid_json()
    {
        $rule = new ImportStore;
        $valid = json_encode([
            'name' => 'Test Store',
            'domains' => ['example.com'],
            'scrape_strategy' => [
                'title' => ['type' => 'selector', 'value' => 'h1'],
                'price' => ['type' => 'selector', 'value' => '.price'],
            ],
            'settings' => [
                'scraper_service' => ScraperService::Http->value,
            ],
        ]);

        $failed = false;
        $rule->validate('import', $valid, function () use (&$failed) {
            $failed = true;
        });

        $this->assertFalse($failed);
    }

    public function test_fails_for_invalid_json()
    {
        $rule = new ImportStore;
        $invalid = 'not valid json {';

        $failed = false;
        $rule->validate('import', $invalid, function () use (&$failed) {
            $failed = true;
        });

        // json_decode returns null for invalid JSON, triggering validation failures
        $this->assertTrue($failed);
    }

    public function test_fails_when_missing_name()
    {
        $rule = new ImportStore;
        $json = json_encode([
            'domains' => ['example.com'],
            'scrape_strategy' => [
                'title' => ['type' => 'selector', 'value' => 'h1'],
                'price' => ['type' => 'selector', 'value' => '.price'],
            ],
            'settings' => [
                'scraper_service' => ScraperService::Http->value,
            ],
        ]);

        $failed = false;
        $rule->validate('import', $json, function ($message) use (&$failed) {
            $failed = true;
            $this->assertSame('The JSON is missing a store name', $message);
        });

        $this->assertTrue($failed);
    }

    public function test_fails_when_missing_domains()
    {
        $rule = new ImportStore;
        $json = json_encode([
            'name' => 'Test Store',
            'scrape_strategy' => [
                'title' => ['type' => 'selector', 'value' => 'h1'],
                'price' => ['type' => 'selector', 'value' => '.price'],
            ],
            'settings' => [
                'scraper_service' => ScraperService::Http->value,
            ],
        ]);

        $failed = false;
        $rule->validate('import', $json, function ($message) use (&$failed) {
            $failed = true;
            $this->assertSame('The JSON is missing domains', $message);
        });

        $this->assertTrue($failed);
    }

    public function test_fails_when_domains_not_array()
    {
        $rule = new ImportStore;
        $json = json_encode([
            'name' => 'Test Store',
            'domains' => 'example.com',
            'scrape_strategy' => [
                'title' => ['type' => 'selector', 'value' => 'h1'],
                'price' => ['type' => 'selector', 'value' => '.price'],
            ],
            'settings' => [
                'scraper_service' => ScraperService::Http->value,
            ],
        ]);

        $failed = false;
        $rule->validate('import', $json, function ($message) use (&$failed) {
            $failed = true;
            $this->assertSame('The JSON is missing domains', $message);
        });

        $this->assertTrue($failed);
    }

    public function test_fails_when_missing_title_strategy()
    {
        $rule = new ImportStore;
        $json = json_encode([
            'name' => 'Test Store',
            'domains' => ['example.com'],
            'scrape_strategy' => [
                'price' => ['type' => 'selector', 'value' => '.price'],
            ],
            'settings' => [
                'scraper_service' => ScraperService::Http->value,
            ],
        ]);

        $failed = false;
        $rule->validate('import', $json, function ($message) use (&$failed) {
            $failed = true;
            $this->assertSame('The JSON is missing a title strategy', $message);
        });

        $this->assertTrue($failed);
    }

    public function test_fails_when_missing_price_strategy()
    {
        $rule = new ImportStore;
        $json = json_encode([
            'name' => 'Test Store',
            'domains' => ['example.com'],
            'scrape_strategy' => [
                'title' => ['type' => 'selector', 'value' => 'h1'],
            ],
            'settings' => [
                'scraper_service' => ScraperService::Http->value,
            ],
        ]);

        $failed = false;
        $rule->validate('import', $json, function ($message) use (&$failed) {
            $failed = true;
            $this->assertSame('The JSON is missing a price strategy', $message);
        });

        $this->assertTrue($failed);
    }

    public function test_fails_when_scraper_service_invalid()
    {
        $rule = new ImportStore;
        $json = json_encode([
            'name' => 'Test Store',
            'domains' => ['example.com'],
            'scrape_strategy' => [
                'title' => ['type' => 'selector', 'value' => 'h1'],
                'price' => ['type' => 'selector', 'value' => '.price'],
            ],
            'settings' => [
                'scraper_service' => 'invalid_service',
            ],
        ]);

        $failed = false;
        $rule->validate('import', $json, function ($message) use (&$failed) {
            $failed = true;
            $this->assertSame('The scraper service is invalid', $message);
        });

        $this->assertTrue($failed);
    }

    public function test_set_data_returns_self()
    {
        $rule = new ImportStore;
        $result = $rule->setData(['key' => 'value']);

        $this->assertInstanceOf(ImportStore::class, $result);
    }
}

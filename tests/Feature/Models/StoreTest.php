<?php

namespace Tests\Feature\Models;

use App\Enums\ScraperService;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_initials_are_generated_correctly()
    {
        $store = Store::factory()->create(['name' => 'Example Store']);
        $this->assertEquals('ES', $store->initials);
    }

    public function test_initials_handle_single_word_name()
    {
        $store = Store::factory()->create(['name' => 'Example']);
        $this->assertEquals('EX', $store->initials);
    }

    public function test_initials_use_provided_value()
    {
        $store = Store::factory()->create(['name' => 'Example Store', 'initials' => 'EXS']);
        $this->assertEquals('EXS', $store->initials);
    }

    public function test_domains_html_returns_correct_format()
    {
        $store = Store::factory()->create(['domains' => [['domain' => 'example.com'], ['domain' => 'test.com']]]);
        $this->assertEquals('example.com, test.com', $store->domains_html);
    }

    public function test_domains_html_handles_empty_domains()
    {
        $store = Store::factory()->create(['domains' => []]);
        $this->assertEquals('', $store->domains_html);
    }

    public function test_scraper_service_returns_default_value()
    {
        $store = Store::factory()->create(['settings' => []]);
        $this->assertEquals(ScraperService::Http->value, $store->scraper_service);
    }

    public function test_scraper_service_returns_custom_value()
    {
        $store = Store::factory()->create(['settings' => ['scraper_service' => ScraperService::Api->value]]);
        $this->assertEquals(ScraperService::Api->value, $store->scraper_service);
    }

    public function test_scraper_options_returns_correct_format()
    {
        $store = Store::factory()->create(['settings' => ['scraper_service_settings' => "option1=value1\noption2=value2"]]);
        $this->assertEquals(['option1' => 'value1', 'option2' => 'value2'], $store->scraper_options);
    }

    public function test_scraper_options_handles_empty_settings()
    {
        $store = Store::factory()->create(['settings' => ['scraper_service_settings' => '']]);
        $this->assertEmpty($store->scraper_options);
    }

    public function test_scraper_options_ignores_invalid_entries()
    {
        $store = Store::factory()->create(['settings' => ['scraper_service_settings' => "option1=value1\ninvalid_entry"]]);
        $this->assertEquals(['option1' => 'value1'], $store->scraper_options);
    }
}

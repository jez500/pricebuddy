<?php

namespace Tests\Unit\Enums;

use App\Enums\ScraperService;
use Filament\Support\Colors\Color;
use Tests\TestCase;

class ScraperServiceTest extends TestCase
{
    public function test_values_returns_array_of_values()
    {
        $values = ScraperService::values();

        $this->assertIsArray($values);
        $this->assertContains(ScraperService::Http->value, $values);
        $this->assertContains(ScraperService::Api->value, $values);
    }

    public function test_get_label_returns_correct_label_for_http()
    {
        $this->assertSame('Curl based HTTP request', ScraperService::Http->getLabel());
    }

    public function test_get_label_returns_correct_label_for_api()
    {
        $this->assertSame('Browser based request', ScraperService::Api->getLabel());
    }

    public function test_get_description_returns_correct_description_for_http()
    {
        $this->assertSame('Faster and and less resource intensive', ScraperService::Http->getDescription());
    }

    public function test_get_description_returns_correct_description_for_api()
    {
        $this->assertSame('Slower but good for scraping JavaScript rendered pages', ScraperService::Api->getDescription());
    }

    public function test_get_color_returns_blue_for_http()
    {
        $this->assertSame(Color::Blue, ScraperService::Http->getColor());
    }

    public function test_get_color_returns_pink_for_api()
    {
        $this->assertSame(Color::Pink, ScraperService::Api->getColor());
    }

    public function test_scavio_label_description_and_color()
    {
        $this->assertSame('Scavio API', ScraperService::Scavio->getLabel());
        $this->assertStringContainsString('SCAVIO_API_KEY', ScraperService::Scavio->getDescription());
        $this->assertSame(Color::Emerald, ScraperService::Scavio->getColor());
    }

    public function test_options_hide_scavio_until_a_key_is_set()
    {
        config(['services.scavio.key' => null]);
        $this->assertSame(['http', 'api'], array_keys(ScraperService::options()));

        config(['services.scavio.key' => 'test-key']);
        $this->assertSame(['http', 'api', 'scavio'], array_keys(ScraperService::options()));
        $this->assertSame('Scavio API', ScraperService::options()['scavio']);
    }

    public function test_options_can_exclude_scavio()
    {
        config(['services.scavio.key' => 'test-key']);

        $this->assertSame(['http', 'api'], array_keys(ScraperService::options(withScavio: false)));
    }

    public function test_all_enum_cases_have_string_values()
    {
        $cases = ScraperService::cases();

        foreach ($cases as $case) {
            $this->assertIsString($case->value);
            $this->assertNotEmpty($case->value);
        }
    }
}

<?php

namespace Tests\Unit\Enums;

use App\Enums\ScraperStrategyType;
use Tests\TestCase;

class ScraperStrategyTypeTest extends TestCase
{
    public function test_values_returns_array_of_values()
    {
        $values = ScraperStrategyType::values();

        $this->assertIsArray($values);
        $this->assertContains(ScraperStrategyType::Selector->value, $values);
        $this->assertContains(ScraperStrategyType::xPath->value, $values);
        $this->assertContains(ScraperStrategyType::Regex->value, $values);
        $this->assertContains(ScraperStrategyType::Json->value, $values);
    }

    public function test_get_label_returns_correct_labels()
    {
        $this->assertSame('CSS Selector', ScraperStrategyType::Selector->getLabel());
        $this->assertSame('XPath', ScraperStrategyType::xPath->getLabel());
        $this->assertSame('Regex', ScraperStrategyType::Regex->getLabel());
        $this->assertSame('JSON path', ScraperStrategyType::Json->getLabel());
    }

    public function test_get_description_returns_correct_descriptions()
    {
        $this->assertSame('Use CSS selectors to extract a value from a HTML document.', ScraperStrategyType::Selector->getDescription());
        $this->assertSame('Use XPath to extract a value from a XML or HTML document.', ScraperStrategyType::xPath->getDescription());
        $this->assertSame('Use regular expressions to extract a value from any document.', ScraperStrategyType::Regex->getDescription());
        $this->assertSame('Use JSON path to extract data from a JSON document.', ScraperStrategyType::Json->getDescription());
    }

    public function test_get_value_help_returns_correct_help_for_selector()
    {
        $help = ScraperStrategyType::getValueHelp(ScraperStrategyType::Selector->value);

        $this->assertStringContainsString('CSS selector', $help);
        $this->assertStringContainsString('|attribute_name', $help);
    }

    public function test_get_value_help_returns_correct_help_for_xpath()
    {
        $help = ScraperStrategyType::getValueHelp(ScraperStrategyType::xPath->value);

        $this->assertStringContainsString('XPath', $help);
        $this->assertStringContainsString('@attribute', $help);
    }

    public function test_get_value_help_returns_correct_help_for_regex()
    {
        $help = ScraperStrategyType::getValueHelp(ScraperStrategyType::Regex->value);

        $this->assertStringContainsString('Regex', $help);
        $this->assertStringContainsString('()', $help);
    }

    public function test_get_value_help_returns_correct_help_for_json()
    {
        $help = ScraperStrategyType::getValueHelp(ScraperStrategyType::Json->value);

        $this->assertStringContainsString('JSON path', $help);
        $this->assertStringContainsString('dot notation', $help);
    }

    public function test_get_value_help_returns_empty_for_unknown_type()
    {
        $help = ScraperStrategyType::getValueHelp('unknown');

        $this->assertSame('', $help);
    }

    public function test_all_enum_cases_have_string_values()
    {
        $cases = ScraperStrategyType::cases();

        foreach ($cases as $case) {
            $this->assertIsString($case->value);
            $this->assertNotEmpty($case->value);
        }
    }
}

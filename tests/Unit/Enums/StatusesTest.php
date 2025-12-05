<?php

namespace Tests\Unit\Enums;

use App\Enums\Statuses;
use Tests\TestCase;

class StatusesTest extends TestCase
{
    public function test_names_returns_array_of_names()
    {
        $names = Statuses::names();

        $this->assertIsArray($names);
        $this->assertContains('Published', $names);
        $this->assertContains('Archived', $names);
    }

    public function test_values_returns_array_of_values()
    {
        $values = Statuses::values();

        $this->assertIsArray($values);
        $this->assertContains('p', $values);
        $this->assertContains('a', $values);
    }

    public function test_to_array_returns_combined_array()
    {
        $array = Statuses::toArray();

        $this->assertIsArray($array);
        $this->assertSame('Published', $array['p']);
        $this->assertSame('Archived', $array['a']);
    }

    public function test_ignored_returns_archived_status()
    {
        $ignored = Statuses::ignored();

        $this->assertIsArray($ignored);
        $this->assertContains(Statuses::Archived, $ignored);
    }

    public function test_get_label_returns_name()
    {
        $this->assertSame('Published', Statuses::Published->getLabel());
        $this->assertSame('Archived', Statuses::Archived->getLabel());
    }

    public function test_get_color_returns_success_for_published()
    {
        $this->assertSame('success', Statuses::Published->getColor());
    }

    public function test_get_color_returns_danger_for_archived()
    {
        $this->assertSame('danger', Statuses::Archived->getColor());
    }

    public function test_get_icon_returns_check_circle_for_published()
    {
        $this->assertSame('heroicon-o-check-circle', Statuses::Published->getIcon());
    }

    public function test_get_icon_returns_exclamation_circle_for_archived()
    {
        $this->assertSame('heroicon-o-exclamation-circle', Statuses::Archived->getIcon());
    }

    public function test_all_enum_cases_have_string_values()
    {
        $cases = Statuses::cases();

        foreach ($cases as $case) {
            $this->assertIsString($case->value);
            $this->assertNotEmpty($case->value);
        }
    }
}

<?php

namespace Tests\Unit\Enums;

use App\Enums\LogLevels;
use Tests\TestCase;

class LogLevelsTest extends TestCase
{
    public function test_get_label_returns_name()
    {
        $this->assertSame('Debug', LogLevels::Debug->getLabel());
        $this->assertSame('Info', LogLevels::Info->getLabel());
        $this->assertSame('Warning', LogLevels::Warning->getLabel());
        $this->assertSame('Error', LogLevels::Error->getLabel());
    }

    public function test_get_color_returns_danger_for_error_levels()
    {
        $this->assertSame('danger', LogLevels::Error->getColor());
        $this->assertSame('danger', LogLevels::Critical->getColor());
        $this->assertSame('danger', LogLevels::Emergency->getColor());
    }

    public function test_get_color_returns_warning_for_warning_levels()
    {
        $this->assertSame('warning', LogLevels::Warning->getColor());
        $this->assertSame('warning', LogLevels::Notice->getColor());
        $this->assertSame('warning', LogLevels::Alert->getColor());
    }

    public function test_get_color_returns_info_for_info_level()
    {
        $this->assertSame('info', LogLevels::Info->getColor());
    }

    public function test_get_color_returns_gray_for_debug()
    {
        $this->assertSame('gray', LogLevels::Debug->getColor());
    }

    public function test_get_icon_returns_exclamation_circle_for_error_levels()
    {
        $this->assertSame('heroicon-o-exclamation-circle', LogLevels::Error->getIcon());
        $this->assertSame('heroicon-o-exclamation-circle', LogLevels::Critical->getIcon());
        $this->assertSame('heroicon-o-exclamation-circle', LogLevels::Emergency->getIcon());
    }

    public function test_get_icon_returns_exclamation_triangle_for_warning_levels()
    {
        $this->assertSame('heroicon-o-exclamation-triangle', LogLevels::Warning->getIcon());
        $this->assertSame('heroicon-o-exclamation-triangle', LogLevels::Notice->getIcon());
        $this->assertSame('heroicon-o-exclamation-triangle', LogLevels::Alert->getIcon());
    }

    public function test_get_icon_returns_information_circle_for_default_levels()
    {
        $this->assertSame('heroicon-o-information-circle', LogLevels::Debug->getIcon());
        $this->assertSame('heroicon-o-information-circle', LogLevels::Info->getIcon());
    }

    public function test_all_enum_cases_have_string_values()
    {
        $cases = LogLevels::cases();

        foreach ($cases as $case) {
            $this->assertIsString($case->value);
            $this->assertNotEmpty($case->value);
        }
    }
}

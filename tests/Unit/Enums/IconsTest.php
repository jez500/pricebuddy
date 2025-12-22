<?php

namespace Tests\Unit\Enums;

use App\Enums\Icons;
use Tests\TestCase;

class IconsTest extends TestCase
{
    public function test_get_trend_icon_returns_trend_down_for_down()
    {
        $icon = Icons::getTrendIcon('down');
        $this->assertSame(Icons::TrendDown->value, $icon);
    }

    public function test_get_trend_icon_returns_trend_up_for_up()
    {
        $icon = Icons::getTrendIcon('up');
        $this->assertSame(Icons::TrendUp->value, $icon);
    }

    public function test_get_trend_icon_returns_trend_none_for_null()
    {
        $icon = Icons::getTrendIcon(null);
        $this->assertSame(Icons::TrendNone->value, $icon);
    }

    public function test_get_trend_icon_returns_trend_none_for_unknown_value()
    {
        $icon = Icons::getTrendIcon('unknown');
        $this->assertSame(Icons::TrendNone->value, $icon);
    }

    public function test_all_enum_cases_have_string_values()
    {
        $cases = Icons::cases();

        foreach ($cases as $case) {
            $this->assertIsString($case->value);
            $this->assertNotEmpty($case->value);
        }
    }
}

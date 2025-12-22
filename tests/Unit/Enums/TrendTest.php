<?php

namespace Tests\Unit\Enums;

use App\Enums\Icons;
use App\Enums\Trend;
use Tests\TestCase;

class TrendTest extends TestCase
{
    public function test_get_icon_returns_trend_down_for_down_and_lowest()
    {
        $this->assertSame(Icons::TrendDown->value, Trend::getIcon(Trend::Down->value));
        $this->assertSame(Icons::TrendDown->value, Trend::getIcon(Trend::Lowest->value));
    }

    public function test_get_icon_returns_trend_up_for_up()
    {
        $this->assertSame(Icons::TrendUp->value, Trend::getIcon(Trend::Up->value));
    }

    public function test_get_icon_returns_trend_none_for_null_and_default()
    {
        $this->assertSame(Icons::TrendNone->value, Trend::getIcon(null));
        $this->assertSame(Icons::TrendNone->value, Trend::getIcon(Trend::None->value));
        $this->assertSame(Icons::TrendNone->value, Trend::getIcon('unknown'));
    }

    public function test_get_text_returns_below_average_for_down()
    {
        $this->assertSame(__('Below average'), Trend::getText(Trend::Down->value));
    }

    public function test_get_text_returns_lowest_recorded_for_lowest()
    {
        $this->assertSame(__('Lowest recorded'), Trend::getText(Trend::Lowest->value));
    }

    public function test_get_text_returns_above_average_for_up()
    {
        $this->assertSame(__('Above average'), Trend::getText(Trend::Up->value));
    }

    public function test_get_text_returns_no_change_for_default()
    {
        $this->assertSame(__('No change'), Trend::getText(null));
        $this->assertSame(__('No change'), Trend::getText(Trend::None->value));
    }

    public function test_get_color_returns_warning_for_down()
    {
        $this->assertSame('warning', Trend::getColor(Trend::Down->value));
    }

    public function test_get_color_returns_success_for_lowest()
    {
        $this->assertSame('success', Trend::getColor(Trend::Lowest->value));
    }

    public function test_get_color_returns_danger_for_up()
    {
        $this->assertSame('danger', Trend::getColor(Trend::Up->value));
    }

    public function test_get_color_returns_gray_for_default()
    {
        $this->assertSame('gray', Trend::getColor(null));
        $this->assertSame('gray', Trend::getColor(Trend::None->value));
    }

    public function test_get_color_rgb_returns_array()
    {
        $rgb = Trend::getColorRgb(Trend::Down->value);

        $this->assertIsArray($rgb);
        $this->assertNotEmpty($rgb);
    }

    public function test_get_trend_direction_returns_up_when_first_value_greater()
    {
        $result = Trend::getTrendDirection([100, 50]);

        $this->assertSame(Trend::Up->value, $result);
    }

    public function test_get_trend_direction_returns_down_when_first_value_less()
    {
        $result = Trend::getTrendDirection([50, 100]);

        $this->assertSame(Trend::Down->value, $result);
    }

    public function test_get_trend_direction_returns_none_when_values_equal()
    {
        $result = Trend::getTrendDirection([50, 50]);

        $this->assertSame(Trend::None->value, $result);
    }

    public function test_get_trend_direction_returns_none_when_not_two_values()
    {
        $result = Trend::getTrendDirection([50]);

        $this->assertSame(Trend::None->value, $result);
    }

    public function test_calculate_trend_returns_lowest_when_at_or_below_lowest()
    {
        $result = Trend::calculateTrend(50.0, 75.0, 50.0);
        $this->assertSame(Trend::Lowest->value, $result);

        $result = Trend::calculateTrend(45.0, 75.0, 50.0);
        $this->assertSame(Trend::Lowest->value, $result);
    }

    public function test_calculate_trend_returns_down_when_below_average()
    {
        $result = Trend::calculateTrend(60.0, 75.0, 50.0);

        $this->assertSame(Trend::Down->value, $result);
    }

    public function test_calculate_trend_returns_up_when_above_average()
    {
        $result = Trend::calculateTrend(80.0, 75.0, 50.0);

        $this->assertSame(Trend::Up->value, $result);
    }

    public function test_calculate_trend_returns_none_when_equal_to_average()
    {
        $result = Trend::calculateTrend(75.0, 75.0, 50.0);

        $this->assertSame(Trend::None->value, $result);
    }
}

<?php

namespace Tests\Feature\View;

use App\Services\Helpers\SettingsHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StatBarTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SettingsHelper::$settings = null;
        SettingsHelper::setSetting('default_locale_settings', ['locale' => 'en', 'currency' => 'USD']);
    }

    private function renderStatBar(array $overrides = []): string
    {
        $stats = array_merge([
            'tracked' => 10,
            'atLowest' => 2,
            'belowAverage' => 3,
            'outOfStock' => 1,
            'potentialSavings' => 123.45,
        ], $overrides);

        return $this->view('filament.widgets.dashboard.stat-bar', ['stats' => $stats])->render();
    }

    public function test_potential_savings_uses_configured_currency(): void
    {
        SettingsHelper::setSetting('default_locale_settings', ['locale' => 'en_IE', 'currency' => 'EUR']);

        $html = $this->renderStatBar();

        $this->assertStringContainsString('€', $html);
        $this->assertStringNotContainsString('$123.45', $html);
    }

    public function test_potential_savings_usd_default_renders_dollar_value(): void
    {
        $html = $this->renderStatBar(['potentialSavings' => 123.45]);

        $this->assertStringContainsString('$123.45', $html);
    }
}

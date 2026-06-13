<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\AppSettingsPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppSettingsAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_access_app_settings_page()
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->get(AppSettingsPage::getUrl())->assertOk();
    }

    public function test_non_admin_cannot_access_app_settings_page()
    {
        $this->actingAs(User::factory()->create());

        $this->get(AppSettingsPage::getUrl())->assertForbidden();
    }
}

<?php

namespace Tests\Feature\Services\Dashboard;

use App\Models\User;
use App\Services\Dashboard\DashboardLayoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardLayoutServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_defaults_when_no_settings(): void
    {
        $user = User::factory()->create(['settings' => []]);
        $service = new DashboardLayoutService($user);

        $keys = array_column($service->sections(), 'key');
        $this->assertSame(DashboardLayoutService::SECTION_KEYS, $keys);
        $this->assertTrue($service->isSectionVisible('buy_now'));
        $this->assertFalse($service->isSectionVisible('stat_bar'));
        $this->assertFalse($service->isSectionVisible('recently_dropped'));
        $this->assertFalse($service->isSectionVisible('needs_attention'));
        $this->assertSame([], $service->categoryOrder());
        $this->assertFalse($service->isCategoryCollapsed('3-7'));
    }

    public function test_toggle_section_persists_and_flips(): void
    {
        $user = User::factory()->create(['settings' => []]);
        $service = new DashboardLayoutService($user);

        $service->toggleSection('buy_now');

        $this->assertFalse($service->isSectionVisible('buy_now'));
        $this->assertFalse((new DashboardLayoutService($user->fresh()))->isSectionVisible('buy_now'));
    }

    public function test_toggle_section_flips_from_hidden_default(): void
    {
        $user = User::factory()->create(['settings' => []]);
        $service = new DashboardLayoutService($user);

        $service->toggleSection('stat_bar');

        $this->assertTrue($service->isSectionVisible('stat_bar'));
        $this->assertTrue((new DashboardLayoutService($user->fresh()))->isSectionVisible('stat_bar'));
    }

    public function test_stored_preference_overrides_default(): void
    {
        $user = User::factory()->create([
            'settings' => ['dashboard' => ['sections' => ['stat_bar' => ['visible' => true]]]],
        ]);
        $service = new DashboardLayoutService($user);

        $this->assertTrue($service->isSectionVisible('stat_bar'));
    }

    public function test_toggle_section_ignores_unknown_key(): void
    {
        $user = User::factory()->create(['settings' => []]);
        $service = new DashboardLayoutService($user);

        $service->toggleSection('not_a_section');

        $this->assertCount(4, $service->sections());
    }

    public function test_collapse_persists(): void
    {
        $user = User::factory()->create(['settings' => []]);
        $service = new DashboardLayoutService($user);

        $service->toggleCategoryCollapse('3-7');

        $this->assertTrue($service->isCategoryCollapsed('3-7'));
        $service->toggleCategoryCollapse('3-7');
        $this->assertFalse($service->isCategoryCollapsed('3-7'));
    }

    public function test_set_category_order_persists(): void
    {
        $user = User::factory()->create(['settings' => []]);
        $service = new DashboardLayoutService($user);

        $service->setCategoryOrder(['12', '3-7', 'uncategorized']);

        $this->assertSame(['12', '3-7', 'uncategorized'], $user->fresh()->settings['dashboard']['categories']['order']);
    }
}

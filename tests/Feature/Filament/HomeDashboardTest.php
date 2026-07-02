<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\HomeDashboard;
use App\Models\User;
use App\Services\Dashboard\DashboardLayoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class HomeDashboardTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    public function test_customize_action_seeds_current_section_visibility(): void
    {
        // Defaults: only buy_now visible.
        Livewire::test(HomeDashboard::class)
            ->mountAction('customize')
            ->assertActionDataSet([
                'stat_bar' => false,
                'buy_now' => true,
                'recently_dropped' => false,
                'needs_attention' => false,
            ]);
    }

    public function test_customize_action_persists_section_visibility(): void
    {
        Livewire::test(HomeDashboard::class)
            ->callAction('customize', data: [
                'stat_bar' => true,
                'buy_now' => false,
                'recently_dropped' => true,
                'needs_attention' => false,
            ])
            ->assertDispatched('dashboard-sections-updated');

        $layout = new DashboardLayoutService($this->user->fresh());
        $this->assertTrue($layout->isSectionVisible('stat_bar'));
        $this->assertFalse($layout->isSectionVisible('buy_now'));
        $this->assertTrue($layout->isSectionVisible('recently_dropped'));
        $this->assertFalse($layout->isSectionVisible('needs_attention'));
    }
}

<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\AppSettingsPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AppSettingsPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_max_priced_results_rejects_non_integer_values()
    {
        Livewire::test(AppSettingsPage::class)
            ->fillForm([
                'integrated_services.searxng.enabled' => true,
                'integrated_services.searxng.url' => 'https://searxng.example.com/search',
                'integrated_services.searxng.max_priced_results' => 2.5,
            ])
            ->call('save')
            ->assertHasFormErrors(['integrated_services.searxng.max_priced_results' => 'integer']);
    }

    public function test_max_priced_results_accepts_integer_values()
    {
        Livewire::test(AppSettingsPage::class)
            ->fillForm([
                'integrated_services.searxng.enabled' => true,
                'integrated_services.searxng.url' => 'https://searxng.example.com/search',
                'integrated_services.searxng.max_priced_results' => 3,
            ])
            ->call('save')
            ->assertHasNoFormErrors(['integrated_services.searxng.max_priced_results']);
    }
}

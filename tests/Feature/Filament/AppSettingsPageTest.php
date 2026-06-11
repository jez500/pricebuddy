<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\AppSettingsPage;
use App\Models\User;
use App\Settings\AppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AppSettingsPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->admin()->create());
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

    public function test_settings_page_renders_the_tabs(): void
    {
        Livewire::test(AppSettingsPage::class)
            ->assertSee('General')
            ->assertSee('Scraping')
            ->assertSee('Notifications')
            ->assertSee('AI');
    }

    public function test_a_general_tab_field_saves_without_error(): void
    {
        Livewire::test(AppSettingsPage::class)
            ->fillForm(['log_retention_days' => 90])
            ->call('save')
            ->assertHasNoFormErrors(['log_retention_days']);
    }

    public function test_settings_sections_render_their_headings(): void
    {
        Livewire::test(AppSettingsPage::class)
            ->assertSee('Scrape Settings')
            ->assertSee('Locale')
            ->assertSee('Logging')
            ->assertSee('Email');
    }

    public function test_retry_settings_have_expected_defaults(): void
    {
        $settings = AppSettings::new();

        $this->assertSame(3, $settings->scrape_retry_max_attempts);
        $this->assertSame(15, $settings->scrape_retry_delay_minutes);
    }

    public function test_retry_settings_save_without_error(): void
    {
        // Mirrors test_a_general_tab_field_saves_without_error: this page's full
        // save can be blocked by unrelated required fields (e.g. SearXng), so we
        // assert only that the retry fields themselves accept valid input.
        Livewire::test(AppSettingsPage::class)
            ->fillForm([
                'scrape_retry_max_attempts' => 4,
                'scrape_retry_delay_minutes' => 20,
            ])
            ->call('save')
            ->assertHasNoFormErrors(['scrape_retry_max_attempts', 'scrape_retry_delay_minutes']);
    }

    public function test_retry_settings_reject_values_below_one(): void
    {
        Livewire::test(AppSettingsPage::class)
            ->fillForm([
                'scrape_retry_max_attempts' => 0,
                'scrape_retry_delay_minutes' => 0,
            ])
            ->call('save')
            ->assertHasFormErrors(['scrape_retry_max_attempts', 'scrape_retry_delay_minutes']);
    }
}

<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\StoreResource\Pages\ListStores;
use App\Models\Store;
use App\Models\User;
use App\Services\Helpers\SettingsHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Stores save their own locale and currency when they are created, so a later
 * change to the default currency does not reach them. The bulk action applies a
 * locale and currency to the selected stores (issue #218).
 */
class StoreBulkLocaleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SettingsHelper::$settings = null;
        $this->actingAs(User::factory()->create());
    }

    private function storeWithLocale(string $locale, string $currency): Store
    {
        return Store::factory()->create([
            'settings' => [
                'scraper_service' => 'api',
                'missing_price_out_of_stock' => true,
                'locale_settings' => ['locale' => $locale, 'currency' => $currency],
            ],
        ]);
    }

    public function test_sets_locale_and_currency_on_the_selected_stores_only(): void
    {
        $selected = collect([
            $this->storeWithLocale('en', 'USD'),
            $this->storeWithLocale('en', 'USD'),
        ]);
        $other = $this->storeWithLocale('en', 'USD');

        Livewire::test(ListStores::class)
            ->callTableBulkAction('setLocale', $selected, data: [
                'locale_settings' => ['locale' => 'fr_FR', 'currency' => 'EUR'],
            ])
            ->assertHasNoTableBulkActionErrors();

        foreach ($selected as $store) {
            $store->refresh();
            $this->assertSame('fr_FR', $store->locale);
            $this->assertSame('EUR', $store->currency);
        }

        $this->assertSame('USD', $other->fresh()->currency);
    }

    public function test_keeps_the_other_store_settings(): void
    {
        $store = $this->storeWithLocale('en', 'USD');

        Livewire::test(ListStores::class)
            ->callTableBulkAction('setLocale', [$store], data: [
                'locale_settings' => ['locale' => 'fr_FR', 'currency' => 'EUR'],
            ]);

        $settings = $store->fresh()->settings;
        $this->assertSame('api', data_get($settings, 'scraper_service'));
        $this->assertTrue(data_get($settings, 'missing_price_out_of_stock'));
    }

    public function test_form_defaults_to_the_app_default_locale_and_currency(): void
    {
        SettingsHelper::setSetting('default_locale_settings', ['locale' => 'fr_FR', 'currency' => 'EUR']);
        $store = $this->storeWithLocale('en', 'USD');

        Livewire::test(ListStores::class)
            ->mountTableBulkAction('setLocale', [$store])
            ->assertTableBulkActionDataSet([
                'locale_settings.locale' => 'fr_FR',
                'locale_settings.currency' => 'EUR',
            ]);
    }

    public function test_store_list_shows_each_store_currency(): void
    {
        $euro = $this->storeWithLocale('fr_FR', 'EUR');
        $dollar = $this->storeWithLocale('en', 'USD');

        Livewire::test(ListStores::class)
            ->assertTableColumnStateSet('currency', 'EUR', $euro)
            ->assertTableColumnStateSet('currency', 'USD', $dollar);
    }

    public function test_requires_a_currency(): void
    {
        $store = $this->storeWithLocale('en', 'USD');

        Livewire::test(ListStores::class)
            ->callTableBulkAction('setLocale', [$store], data: [
                'locale_settings' => ['locale' => 'fr_FR', 'currency' => null],
            ])
            ->assertHasTableBulkActionErrors(['locale_settings.currency' => 'required']);

        $this->assertSame('USD', $store->fresh()->currency);
    }
}

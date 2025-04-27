<?php

use App\Models\Store;
use App\Services\Helpers\CurrencyHelper;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Ensure all stores have a locale.
     */
    public function up(): void
    {
        $defaults = [
            'locale' => CurrencyHelper::getLocale(),
            'currency' => CurrencyHelper::getCurrency(),
        ];

        Store::all()
            ->each(function (Store $store) use ($defaults) {
                $settings = $store->settings;
                $settings['locale_settings'] = $settings['locale_settings'] ?? [];

                foreach (['locale', 'currency'] as $attribute) {
                    if (! data_get($store->settings, 'locale_settings.'.$attribute)) {
                        $settings['locale_settings'][$attribute] = $defaults[$attribute];
                    }
                }

                $store->update(['settings' => $settings]);
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};

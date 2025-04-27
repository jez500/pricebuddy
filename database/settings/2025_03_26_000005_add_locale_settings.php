<?php

use App\Services\Helpers\LocaleHelper;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $this->migrator->add('app.default_locale_settings', LocaleHelper::getAppLocaleSettings());
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $this->migrator->delete('app.default_locale_settings');
    }
};

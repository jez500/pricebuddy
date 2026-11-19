<?php

use App\Enums\ExchangeRateProvider;
use Illuminate\Support\Facades\DB;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        if (! DB::table('settings')->where('group', 'app')->where('name', 'currency_conversion')->exists()) {
            $this->migrator->add('app.currency_conversion', [
                'enabled' => false,
                'provider' => ExchangeRateProvider::Frankfurter->value,
                'manual_rates' => [],
            ]);
        }
    }

    public function down(): void
    {
        if (DB::table('settings')->where('group', 'app')->where('name', 'currency_conversion')->exists()) {
            $this->migrator->delete('app.currency_conversion');
        }
    }
};

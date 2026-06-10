<?php

use Illuminate\Support\Facades\DB;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        if (! DB::table('settings')->where('group', 'app')->where('name', 'scrape_retry_max_attempts')->exists()) {
            $this->migrator->add('app.scrape_retry_max_attempts', 3);
        }

        if (! DB::table('settings')->where('group', 'app')->where('name', 'scrape_retry_delay_minutes')->exists()) {
            $this->migrator->add('app.scrape_retry_delay_minutes', 15);
        }
    }

    public function down(): void
    {
        if (DB::table('settings')->where('group', 'app')->where('name', 'scrape_retry_max_attempts')->exists()) {
            $this->migrator->delete('app.scrape_retry_max_attempts');
        }

        if (DB::table('settings')->where('group', 'app')->where('name', 'scrape_retry_delay_minutes')->exists()) {
            $this->migrator->delete('app.scrape_retry_delay_minutes');
        }
    }
};

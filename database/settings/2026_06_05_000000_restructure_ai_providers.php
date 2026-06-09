<?php

use App\Settings\AiProvidersRestructure;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->update(
            'app.integrated_services',
            function (mixed $services): array {
                // The migrator decodes JSON as stdClass; cast deeply to array first.
                $services = json_decode(json_encode($services), true) ?: [];

                return AiProvidersRestructure::transform($services);
            },
        );
    }

    public function down(): void
    {
        // Non-reversible: the multi-provider structure cannot be losslessly
        // collapsed back to a single provider.
    }
};

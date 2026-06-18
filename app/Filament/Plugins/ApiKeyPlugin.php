<?php

namespace App\Filament\Plugins;

use App\Filament\Resources\ApiKeyResource;
use Filament\Panel;
use Rupadana\ApiService\ApiServicePlugin;

class ApiKeyPlugin extends ApiServicePlugin
{
    public function register(Panel $panel): void
    {
        $panel->resources([
            ApiKeyResource::class,
        ]);
    }
}

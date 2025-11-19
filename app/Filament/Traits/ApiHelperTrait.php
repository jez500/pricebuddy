<?php

namespace App\Filament\Traits;

use App\Providers\Filament\AdminPanelProvider;

trait ApiHelperTrait
{
    public function getPerPage(): ?int
    {
        $default = (string) AdminPanelProvider::DEFAULT_PAGINATION[0];

        return (int) request()->query('per_page', $default);
    }
}

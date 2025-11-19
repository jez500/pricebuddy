<?php

namespace App\Filament\Resources\StoreResource\Api;

use App\Filament\Resources\StoreResource;
use Rupadana\ApiService\ApiService;

class StoreApiService extends ApiService
{
    protected static ?string $resource = StoreResource::class;

    public static function handlers(): array
    {
        return [
            Handlers\CreateHandler::class,
            Handlers\UpdateHandler::class,
            Handlers\DeleteHandler::class,
            Handlers\PaginationHandler::class,
            Handlers\DetailHandler::class,
        ];
    }
}

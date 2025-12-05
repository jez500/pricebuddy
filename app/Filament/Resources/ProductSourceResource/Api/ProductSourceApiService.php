<?php

namespace App\Filament\Resources\ProductSourceResource\Api;

use App\Filament\Resources\ProductSourceResource;
use Rupadana\ApiService\ApiService;

class ProductSourceApiService extends ApiService
{
    protected static ?string $resource = ProductSourceResource::class;

    public static function handlers(): array
    {
        return [
            Handlers\SearchAllHandler::class,
            Handlers\CreateHandler::class,
            Handlers\UpdateHandler::class,
            Handlers\DeleteHandler::class,
            Handlers\PaginationHandler::class,
            Handlers\DetailHandler::class,
            Handlers\SearchHandler::class,
        ];
    }
}

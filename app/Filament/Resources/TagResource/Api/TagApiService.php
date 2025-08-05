<?php

namespace App\Filament\Resources\TagResource\Api;

use App\Filament\Resources\TagResource;
use Rupadana\ApiService\ApiService;

class TagApiService extends ApiService
{
    protected static ?string $resource = TagResource::class;

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

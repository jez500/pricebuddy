<?php

namespace App\Filament\Resources\StoreResource\Api\Handlers;

use App\Filament\Resources\StoreResource;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Rupadana\ApiService\Http\Handlers;

#[Group(StoreResource::API_GROUP)]
class DeleteHandler extends Handlers
{
    public static ?string $uri = '/{id}';

    public static ?string $resource = StoreResource::class;

    public static function getMethod()
    {
        return Handlers::DELETE;
    }

    public static function getModel()
    {
        return static::$resource::getModel();
    }

    /**
     * Delete Store
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function handler(Request $request)
    {
        $id = $request->route('id');

        $model = static::getModel()::where('id', $id)->where('user_id', auth()->id())->first();

        if (! $model) {
            return static::sendNotFoundResponse();
        }

        $model->delete();

        return response()->json([], 204);
    }
}

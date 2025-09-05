<?php

namespace App\Filament\Resources\StoreResource\Api\Handlers;

use App\Filament\Resources\StoreResource;
use App\Filament\Resources\StoreResource\Api\Requests\UpdateStoreRequest;
use Dedoc\Scramble\Attributes\Group;
use Rupadana\ApiService\Http\Handlers;

#[Group(StoreResource::API_GROUP)]
class UpdateHandler extends Handlers
{
    public static ?string $uri = '/{id}';

    public static ?string $resource = StoreResource::class;

    public static function getMethod()
    {
        return Handlers::PUT;
    }

    public static function getModel()
    {
        return static::$resource::getModel();
    }

    /**
     * Update Store
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function handler(UpdateStoreRequest $request)
    {
        $id = $request->route('id');

        $model = static::getModel()::where('id', $id)->where('user_id', auth()->id())->first();

        if (! $model) {
            return static::sendNotFoundResponse();
        }

        $model->fill($request->all());

        $model->save();

        return static::sendSuccessResponse($model, 'Successfully Update Resource');
    }
}

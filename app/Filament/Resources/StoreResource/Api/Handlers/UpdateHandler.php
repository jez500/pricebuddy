<?php

namespace App\Filament\Resources\StoreResource\Api\Handlers;

use App\Filament\Resources\StoreResource;
use App\Filament\Resources\StoreResource\Api\Requests\UpdateStoreRequest;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
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
     * @return JsonResponse
     */
    public function handler(UpdateStoreRequest $request)
    {
        $id = $request->route('id');

        // Stores are shared, so any user can edit any store (StorePolicy).
        $model = static::getModel()::find($id);

        if (! $model) {
            return static::sendNotFoundResponse();
        }

        if (Gate::denies('update', $model)) {
            return response()->json(['message' => 'You cannot edit this store.'], 403);
        }

        // user_id records who created the store. An edit does not change it.
        $model->fill($request->except('user_id'));

        $model->save();

        return static::sendSuccessResponse($model, 'Successfully Update Resource');
    }
}

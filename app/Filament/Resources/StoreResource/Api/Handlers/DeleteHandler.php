<?php

namespace App\Filament\Resources\StoreResource\Api\Handlers;

use App\Filament\Resources\StoreResource;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
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
     * @return JsonResponse
     */
    public function handler(Request $request)
    {
        $id = $request->route('id');

        $model = static::getModel()::find($id);

        if (! $model) {
            return static::sendNotFoundResponse();
        }

        // Deleting a shared store deletes every user's URLs and prices for it, so only
        // an admin can do it (StorePolicy). 403, not 404, so a client can explain it.
        if (Gate::denies('delete', $model)) {
            return response()->json(['message' => 'Only an admin can delete a store.'], 403);
        }

        $model->delete();

        return response()->json([], 204);
    }
}
